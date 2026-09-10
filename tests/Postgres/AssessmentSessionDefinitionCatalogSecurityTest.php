<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Domain\AssessmentSessions\SessionDefinition;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AssessmentSessionDefinitionCatalogSecurityTest extends TestCase
{
    private const TABLE = 'assessment_session_definitions';

    protected function tearDown(): void
    {
        $this->asOwner(fn () => DB::statement('TRUNCATE TABLE '.self::TABLE.' RESTART IDENTITY'));

        parent::tearDown();
    }

    public function test_runtime_has_forced_service_only_rls_and_least_privilege(): void
    {
        $identity = DB::selectOne(<<<'SQL'
            SELECT current_user AS name, role.rolsuper, role.rolbypassrls,
                pg_get_userbyid(class.relowner) AS table_owner,
                class.relrowsecurity, class.relforcerowsecurity
            FROM pg_roles role
            CROSS JOIN pg_class class
            WHERE role.rolname = current_user
                AND class.oid = 'assessment_session_definitions'::regclass
            SQL);

        $this->assertSame('psikotes_runtime', $identity->name);
        $this->assertFalse($identity->rolsuper);
        $this->assertFalse($identity->rolbypassrls);
        $this->assertNotSame('psikotes_runtime', $identity->table_owner);
        $this->assertTrue($identity->relrowsecurity);
        $this->assertTrue($identity->relforcerowsecurity);

        foreach (['SELECT', 'INSERT', 'UPDATE'] as $privilege) {
            $this->assertTrue((bool) DB::scalar(
                "SELECT has_table_privilege('psikotes_runtime', ?, ?)",
                [self::TABLE, $privilege],
            ), $privilege);
        }
        foreach (['DELETE', 'TRUNCATE', 'TRIGGER', 'REFERENCES'] as $privilege) {
            $this->assertFalse((bool) DB::scalar(
                "SELECT has_table_privilege('psikotes_runtime', ?, ?)",
                [self::TABLE, $privilege],
            ), $privilege);
        }
        $this->assertFalse((bool) DB::scalar(<<<'SQL'
            SELECT has_function_privilege(
                'psikotes_runtime',
                'app_private.guard_assessment_session_definitions()',
                'EXECUTE'
            )
            SQL));

        $policies = DB::table('pg_policies')
            ->where('schemaname', 'public')
            ->where('tablename', self::TABLE)
            ->orderBy('policyname')
            ->get();
        $this->assertSame([
            'assessment_session_definitions_service_insert',
            'assessment_session_definitions_service_select',
            'assessment_session_definitions_service_update',
        ], $policies->pluck('policyname')->all());
        foreach ($policies as $policy) {
            $this->assertSame('{psikotes_runtime}', $policy->roles);
            $this->assertStringContainsString(
                "app_private.app_role() = 'service'",
                ($policy->qual ?? '').($policy->with_check ?? ''),
            );
        }
    }

    public function test_only_service_can_read_insert_and_deactivate_catalog_history(): void
    {
        $this->assertSame(0, DB::table(self::TABLE)->count());
        $this->assertSqlState('42501', fn () => DB::table(self::TABLE)->insert($this->row('ist', 'outside')));

        app(RlsContextRunner::class)->runAsService(
            fn () => DB::table(self::TABLE)->insert($this->row('ist', 'v1')),
        );
        $this->assertSame(0, DB::table(self::TABLE)->count());

        foreach (['super_admin', 'psychologist', 'branch_admin', 'staff', 'participant'] as $role) {
            $context = match ($role) {
                'branch_admin', 'staff' => new RlsContext($role, 1),
                'participant' => new RlsContext($role, 1, 1),
                default => new RlsContext($role),
            };
            app(RlsContextRunner::class)->run($context, function () use ($role): void {
                $this->assertSame(0, DB::table(self::TABLE)->count(), $role);
                $this->assertSqlState(
                    '42501',
                    fn () => DB::table(self::TABLE)->insert($this->row('papi', $role)),
                );
            });
        }

        app(RlsContextRunner::class)->runAsService(function (): void {
            $this->assertSame(1, DB::table(self::TABLE)->count());
            DB::table(self::TABLE)->where('instrument', 'ist')->update([
                'is_active' => false,
                'deactivated_at' => now()->addSecond(),
            ]);
            DB::table(self::TABLE)->insert($this->row('ist', 'v2'));
            $this->assertSame(1, DB::table(self::TABLE)->where('is_active', true)->count());
        });
    }

    public function test_history_is_immutable_and_database_serializes_one_active_version(): void
    {
        app(RlsContextRunner::class)->runAsService(
            fn () => DB::table(self::TABLE)->insert($this->row('ist', 'v1')),
        );

        foreach (['instrument', 'version', 'provenance', 'template_checksum', 'template_payload', 'activated_at'] as $column) {
            $this->assertSqlState('P0001', function () use ($column): void {
                app(RlsContextRunner::class)->runAsService(function () use ($column): void {
                    $value = match ($column) {
                        'template_checksum' => str_repeat('f', 64),
                        'template_payload' => json_encode(['changed' => true], JSON_THROW_ON_ERROR),
                        'activated_at' => now()->subDay(),
                        default => 'changed',
                    };
                    DB::table(self::TABLE)->where('instrument', 'ist')->update([$column => $value]);
                });
            });
        }

        $this->assertSqlState('23505', fn () => app(RlsContextRunner::class)->runAsService(
            fn () => DB::table(self::TABLE)->insert($this->row('ist', 'v2')),
        ));
        $this->assertSqlState('42501', fn () => app(RlsContextRunner::class)->runAsService(
            fn () => DB::table(self::TABLE)->where('instrument', 'ist')->delete(),
        ));

        $index = DB::selectOne(<<<'SQL'
            SELECT indexdef FROM pg_indexes
            WHERE schemaname = 'public'
                AND indexname = 'assessment_session_definitions_one_active_instrument_unique'
            SQL);
        $this->assertNotNull($index);
        $this->assertStringContainsString('CREATE UNIQUE INDEX', $index->indexdef);
        $this->assertStringContainsString('WHERE (is_active = true)', $index->indexdef);

        app(RlsContextRunner::class)->runAsService(fn () => DB::table(self::TABLE)
            ->where('instrument', 'ist')->update([
                'is_active' => false,
                'deactivated_at' => now()->addSecond(),
            ]));
        $this->assertSqlState('P0001', fn () => app(RlsContextRunner::class)->runAsService(
            fn () => DB::table(self::TABLE)->where('instrument', 'ist')->update([
                'is_active' => true,
                'deactivated_at' => null,
            ]),
        ));
    }

    public function test_database_rejects_dass_and_non_exact_payloads(): void
    {
        $this->assertSqlState('23514', fn () => app(RlsContextRunner::class)->runAsService(
            fn () => DB::table(self::TABLE)->insert($this->row('dass21', 'v1')),
        ));

        $invalid = $this->template('ist', 'bad-duration');
        $invalid['subtests'][0]['duration_seconds'] = 30;
        $this->assertSqlState('23514', fn () => app(RlsContextRunner::class)->runAsService(
            fn () => DB::table(self::TABLE)->insert($this->rowForTemplate($invalid)),
        ));

        $kraepelin = $this->kraepelinTemplate();
        $kraepelin['generator']['columns'] = '50';
        $this->assertSqlState('23514', fn () => app(RlsContextRunner::class)->runAsService(
            fn () => DB::table(self::TABLE)->insert($this->rowForTemplate($kraepelin)),
        ));
    }

    public function test_identity_fields_match_the_domain_unicode_contract(): void
    {
        foreach ($this->nonCanonicalUnicodeTemplates() as $label => $template) {
            $this->assertSqlState('23514', fn () => app(RlsContextRunner::class)->runAsService(
                fn () => DB::table(self::TABLE)->insert($this->rowForTemplate($template)),
            ), $label);
        }

        $canonical = $this->kraepelinTemplate();
        $canonical['version'] = 'versi-é';
        $canonical['provenance'] = 'sumber-日本';
        $canonical['subtests'][0]['code'] = '分析';
        $canonical['generator']['algorithm'] = 'algoritme™';
        $canonical['generator']['version'] = 'versi-é';
        app(RlsContextRunner::class)->runAsService(
            fn () => DB::table(self::TABLE)->insert($this->rowForTemplate($canonical)),
        );

        $stored = app(RlsContextRunner::class)->runAsService(
            fn () => DB::table(self::TABLE)->sole(['version', 'provenance']),
        );
        $this->assertSame('versi-é', $stored->version);
        $this->assertSame('sumber-日本', $stored->provenance);
    }

    public function test_postgres_rejects_nul_in_every_identity_field(): void
    {
        foreach ($this->nulIdentityTemplates() as $label => $template) {
            try {
                app(RlsContextRunner::class)->runAsService(
                    fn () => DB::table(self::TABLE)->insert($this->rowForTemplate($template)),
                );
                $this->fail('Expected PostgreSQL to reject '.$label.'.');
            } catch (QueryException $exception) {
                $this->assertContains(
                    $exception->errorInfo[0] ?? null,
                    ['22021', '22P05', '23514'],
                    $exception->getMessage(),
                );
            }
        }
        $this->assertSame(0, app(RlsContextRunner::class)->runAsService(
            fn () => DB::table(self::TABLE)->count(),
        ));
    }

    public function test_two_runtime_processes_racing_activation_leave_exactly_one_winner(): void
    {
        DB::purge('pgsql');
        $workers = [
            $this->startActivationWorker('race-a'),
            $this->startActivationWorker('race-b'),
        ];

        try {
            foreach ($workers as $worker) {
                fwrite($worker['socket'], "go\n");
            }

            $winnerIndex = $this->waitForInsertedWorker($workers);
            $loserIndex = $winnerIndex === 0 ? 1 : 0;
            $this->assertWorkerWaitsOnCatalogLock($workers[$loserIndex]['backend']);

            fwrite($workers[$winnerIndex]['socket'], "commit\n");
            $winner = $this->readWorkerEvent($workers[$winnerIndex]['socket'], 'result');
            $loser = $this->readWorkerEvent($workers[$loserIndex]['socket'], 'result');

            $this->assertTrue($winner['accepted']);
            $this->assertNull($winner['sqlstate']);
            $this->assertFalse($loser['accepted']);
            $this->assertSame('23505', $loser['sqlstate']);
        } finally {
            $this->stopActivationWorkers($workers);
        }

        $history = app(RlsContextRunner::class)->runAsService(
            fn () => DB::table(self::TABLE)->get(['version', 'is_active']),
        );
        $this->assertCount(1, $history);
        $this->assertTrue((bool) $history->sole()->is_active);
        $this->assertContains($history->sole()->version, ['race-a', 'race-b']);
    }

    public function test_overlapping_rotations_leave_old_history_and_one_active_winner(): void
    {
        app(RlsContextRunner::class)->runAsService(
            fn () => DB::table(self::TABLE)->insert($this->row('ist', 'v1')),
        );
        DB::purge('pgsql');
        $workers = [
            $this->startActivationWorker('v2', 'v1'),
            $this->startActivationWorker('v3', 'v1'),
        ];

        try {
            foreach ($workers as $worker) {
                fwrite($worker['socket'], "go\n");
            }

            $winnerIndex = $this->waitForInsertedWorker($workers);
            $loserIndex = $winnerIndex === 0 ? 1 : 0;
            $this->assertWorkerWaitsOnCatalogLock($workers[$loserIndex]['backend']);

            fwrite($workers[$winnerIndex]['socket'], "commit\n");
            $winner = $this->readWorkerEvent($workers[$winnerIndex]['socket'], 'result');
            $loser = $this->readWorkerEvent($workers[$loserIndex]['socket'], 'result');

            $this->assertTrue($winner['accepted']);
            $this->assertNull($winner['sqlstate']);
            $this->assertFalse($loser['accepted']);
            $this->assertSame('P0001', $loser['sqlstate']);
        } finally {
            $this->stopActivationWorkers($workers);
        }

        $history = app(RlsContextRunner::class)->runAsService(
            fn () => DB::table(self::TABLE)->orderBy('version')->get(['version', 'is_active']),
        );
        $this->assertCount(2, $history);
        $this->assertSame('v1', $history->first()->version);
        $this->assertFalse((bool) $history->first()->is_active);
        $active = $history->where('is_active', true)->values();
        $this->assertCount(1, $active);
        $this->assertContains($active->sole()->version, ['v2', 'v3']);
    }

    public function test_populated_catalog_refuses_rollback_and_empty_catalog_is_reversible(): void
    {
        $this->asOwner(function (): void {
            $migration = require database_path('migrations/2026_09_10_000200_create_assessment_session_definitions.php');

            DB::beginTransaction();
            try {
                $migration->down();
                $this->assertNull(DB::selectOne("SELECT to_regclass('assessment_session_definitions') AS name")->name);
                $migration->up();
                $this->assertTrue(DB::selectOne(
                    "SELECT relforcerowsecurity FROM pg_class WHERE oid = 'assessment_session_definitions'::regclass",
                )->relforcerowsecurity);
            } finally {
                DB::rollBack();
            }

            DB::beginTransaction();
            try {
                DB::statement("SELECT set_config('app.role', 'service', true)");
                DB::table(self::TABLE)->insert($this->row('ist', 'history'));
                try {
                    $migration->down();
                    $this->fail('Populated definition history must refuse rollback.');
                } catch (RuntimeException $exception) {
                    $this->assertSame(
                        'Assessment session definition history prevents rollback.',
                        $exception->getMessage(),
                    );
                }
                $this->assertNotNull(DB::selectOne(
                    "SELECT to_regclass('assessment_session_definitions') AS name",
                )->name);
            } finally {
                DB::rollBack();
            }
        });
    }

    public function test_non_bypass_owner_cannot_erase_populated_history_on_rollback(): void
    {
        $this->asNonBypassOwner(function (): void {
            $migration = require database_path('migrations/2026_09_10_000200_create_assessment_session_definitions.php');
            $identity = DB::selectOne(
                'SELECT current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user',
            );
            $this->assertSame('session_definition_history_owner', $identity->name);
            $this->assertFalse($identity->rolsuper);
            $this->assertFalse($identity->rolbypassrls);

            DB::beginTransaction();
            try {
                $migration->down();
                $migration->up();
                $this->assertTrue(DB::selectOne(
                    "SELECT relforcerowsecurity FROM pg_class WHERE oid = 'assessment_session_definitions'::regclass",
                )->relforcerowsecurity);
            } finally {
                DB::rollBack();
            }

            DB::beginTransaction();
            try {
                DB::statement('ALTER TABLE '.self::TABLE.' NO FORCE ROW LEVEL SECURITY');
                DB::table(self::TABLE)->insert($this->row('ist', 'history'));
                DB::statement('ALTER TABLE '.self::TABLE.' FORCE ROW LEVEL SECURITY');

                try {
                    $migration->down();
                    $this->fail('A non-bypass owner must not erase populated definition history.');
                } catch (RuntimeException $exception) {
                    $this->assertSame(
                        'Assessment session definition history prevents rollback.',
                        $exception->getMessage(),
                    );
                }
                $this->assertNotNull(DB::selectOne(
                    "SELECT to_regclass('assessment_session_definitions') AS name",
                )->name);
            } finally {
                DB::rollBack();
            }
        });
    }

    /** @return array<string, mixed> */
    private function row(string $instrument, string $version): array
    {
        return $this->rowForTemplate($this->template($instrument, $version));
    }

    /** @param array<string, mixed> $template
     * @return array<string, mixed>
     */
    private function rowForTemplate(array $template): array
    {
        return [
            'instrument' => $template['instrument'],
            'version' => $template['version'],
            'provenance' => $template['provenance'],
            'template_checksum' => SessionDefinition::checksumFor($template),
            'template_payload' => json_encode($template, JSON_THROW_ON_ERROR),
            'is_active' => true,
            'activated_at' => now(),
            'deactivated_at' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function template(string $instrument, string $version): array
    {
        return [
            'instrument' => $instrument,
            'version' => $version,
            'provenance' => 'synthetic-postgres-test',
            'total_duration_seconds' => 60,
            'subtests' => [[
                'code' => 'SYNTHETIC',
                'duration_seconds' => 60,
                'item_count' => 1,
            ]],
            'randomization' => 'fixed',
            'seed' => null,
            'generator' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function kraepelinTemplate(): array
    {
        return [
            'instrument' => 'kraepelin',
            'version' => 'synthetic-v1',
            'provenance' => 'synthetic-postgres-test',
            'total_duration_seconds' => 750,
            'subtests' => [[
                'code' => 'SYNTHETIC',
                'duration_seconds' => 750,
                'item_count' => 1350,
            ]],
            'randomization' => 'seeded',
            'seed' => null,
            'generator' => [
                'algorithm' => 'synthetic-generator',
                'version' => 'synthetic-v1',
                'columns' => 50,
                'seconds_per_column' => 15,
                'numbers_per_column' => 28,
                'answer_slots_per_column' => 27,
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function nonCanonicalUnicodeTemplates(): array
    {
        $templates = [];
        foreach (['version', 'provenance', 'subtest_code'] as $field) {
            $template = $this->template('ist', 'unicode-'.$field);
            match ($field) {
                'version' => $template['version'] .= "\u{2060}",
                'provenance' => $template['provenance'] .= "\u{2060}",
                'subtest_code' => $template['subtests'][0]['code'] .= "\u{2060}",
            };
            $templates['word joiner in '.$field] = $template;
        }
        foreach (['algorithm', 'version'] as $field) {
            $template = $this->kraepelinTemplate();
            $template['version'] = 'unicode-generator-'.$field;
            $template['generator'][$field] .= "\u{2060}";
            $templates['word joiner in generator '.$field] = $template;
        }

        $templates['unassigned category Cn'] = $this->template('papi', "unassigned-\u{0378}");
        $privateUse = $this->template('rmib', 'private-use');
        $privateUse['provenance'] .= "\u{E000}";
        $templates['private-use category Co'] = $privateUse;
        $separator = $this->template('papi', 'separator');
        $separator['subtests'][0]['code'] .= "\u{2007}";
        $templates['separator category Zs'] = $separator;

        return $templates;
    }

    /** @return array<string, array<string, mixed>> */
    private function nulIdentityTemplates(): array
    {
        $templates = [];
        foreach (['version', 'provenance', 'subtest_code'] as $field) {
            $template = $this->template('ist', 'nul-'.$field);
            match ($field) {
                'version' => $template['version'] .= "\u{0000}",
                'provenance' => $template['provenance'] .= "\u{0000}",
                'subtest_code' => $template['subtests'][0]['code'] .= "\u{0000}",
            };
            $templates['nul in '.$field] = $template;
        }
        foreach (['algorithm', 'version'] as $field) {
            $template = $this->kraepelinTemplate();
            $template['version'] = 'nul-generator-'.$field;
            $template['generator'][$field] .= "\u{0000}";
            $templates['nul in generator '.$field] = $template;
        }

        return $templates;
    }

    /** @return array{pid:int,backend:int,socket:resource} */
    private function startActivationWorker(string $version, ?string $deactivateVersion = null): array
    {
        $this->assertTrue(function_exists('pcntl_fork'), 'Catalog concurrency requires pcntl; never skip.');
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($pair === false || ($pid = pcntl_fork()) === -1) {
            throw new RuntimeException('Unable to create catalog activation worker.');
        }
        if ($pid === 0) {
            fclose($pair[0]);
            stream_set_timeout($pair[1], 20);
            try {
                DB::purge('pgsql');
                $identity = DB::selectOne(
                    'SELECT pg_backend_pid() AS pid, current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user',
                );
                if ($identity->name !== 'psikotes_runtime' || $identity->rolsuper || $identity->rolbypassrls) {
                    throw new RuntimeException('Catalog worker must be runtime non-owner without RLS bypass.');
                }
                DB::statement("SET lock_timeout = '12s'");
                DB::statement("SET statement_timeout = '15s'");
                $this->writeWorkerEvent($pair[1], ['event' => 'ready', 'backend' => (int) $identity->pid]);
                if (fgets($pair[1]) !== "go\n") {
                    throw new RuntimeException('Catalog activation barrier timed out.');
                }

                app(RlsContextRunner::class)->runAsService(function () use (
                    $deactivateVersion,
                    $pair,
                    $version,
                ): void {
                    if ($deactivateVersion !== null) {
                        DB::table(self::TABLE)->where('version', $deactivateVersion)->update([
                            'is_active' => false,
                            'deactivated_at' => now()->addSecond(),
                        ]);
                    }
                    DB::table(self::TABLE)->insert($this->row('ist', $version));
                    $this->writeWorkerEvent($pair[1], ['event' => 'inserted']);
                    if (fgets($pair[1]) !== "commit\n") {
                        throw new RuntimeException('Catalog commit barrier timed out.');
                    }
                });
                $this->writeWorkerEvent($pair[1], [
                    'event' => 'result', 'accepted' => true, 'sqlstate' => null,
                ]);
            } catch (QueryException $exception) {
                $this->writeWorkerEvent($pair[1], [
                    'event' => 'result', 'accepted' => false,
                    'sqlstate' => $exception->errorInfo[0] ?? null,
                ]);
            } catch (\Throwable $exception) {
                $this->writeWorkerEvent($pair[1], [
                    'event' => 'unexpected', 'type' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
            fclose($pair[1]);
            DB::disconnect('pgsql');
            exit(0);
        }
        fclose($pair[1]);
        stream_set_timeout($pair[0], 20);
        $ready = $this->readWorkerEvent($pair[0], 'ready');

        return ['pid' => $pid, 'backend' => $ready['backend'], 'socket' => $pair[0]];
    }

    /** @param list<array{pid:int,backend:int,socket:resource}> $workers */
    private function waitForInsertedWorker(array $workers): int
    {
        $read = [$workers[0]['socket'], $workers[1]['socket']];
        $write = null;
        $except = null;
        if (stream_select($read, $write, $except, 10) !== 1) {
            throw new RuntimeException('Exactly one catalog worker should insert before commit.');
        }
        $readySocket = reset($read);
        if (! is_resource($readySocket)) {
            throw new RuntimeException('Catalog activation socket is invalid.');
        }
        $winner = $readySocket === $workers[0]['socket'] ? 0 : 1;
        $this->assertSame('inserted', $this->readWorkerEvent($workers[$winner]['socket'])['event']);

        return $winner;
    }

    private function assertWorkerWaitsOnCatalogLock(int $backend): void
    {
        $deadline = microtime(true) + 5;
        do {
            $waiting = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [$backend]);
            if ($waiting?->wait_event_type === 'Lock') {
                break;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);
        $this->assertSame('Lock', $waiting?->wait_event_type);
    }

    /** @param resource $socket
     * @return array<string, mixed>
     */
    private function readWorkerEvent($socket, ?string $expected = null): array
    {
        $line = fgets($socket);
        if (! is_string($line)) {
            throw new RuntimeException('Catalog worker did not report an event.');
        }
        $event = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($event) || ! is_string($event['event'] ?? null)) {
            throw new RuntimeException('Catalog worker event is invalid.');
        }
        if ($expected !== null) {
            $this->assertSame($expected, $event['event'], json_encode($event, JSON_THROW_ON_ERROR));
        }

        return $event;
    }

    /** @param resource $socket
     * @param  array<string, mixed>  $event
     */
    private function writeWorkerEvent($socket, array $event): void
    {
        fwrite($socket, json_encode($event, JSON_THROW_ON_ERROR)."\n");
    }

    /** @param list<array{pid:int,backend:int,socket:resource}> $workers */
    private function stopActivationWorkers(array $workers): void
    {
        foreach ($workers as $worker) {
            fclose($worker['socket']);
            pcntl_waitpid($worker['pid'], $status);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
    }

    private function assertSqlState(string $state, callable $operation, string $message = ''): void
    {
        try {
            $operation();
            $this->fail("Expected SQLSTATE {$state}.");
        } catch (QueryException $exception) {
            $this->assertSame(
                $state,
                $exception->errorInfo[0] ?? null,
                trim($message.' '.$exception->getMessage()),
            );
        }
    }

    private function asOwner(callable $callback): void
    {
        $runtime = DB::getDefaultConnection();
        $config = config('database.connections.'.$runtime);
        config()->set('database.connections.session_definition_owner', [
            ...$config,
            'username' => 'org_test_owner',
        ]);
        DB::setDefaultConnection('session_definition_owner');
        Schema::clearResolvedInstance('db.schema');

        try {
            $this->assertSame('org_test_owner', DB::selectOne('SELECT current_user AS name')->name);
            $callback();
        } finally {
            DB::setDefaultConnection($runtime);
            Schema::clearResolvedInstance('db.schema');
            DB::purge('session_definition_owner');
            config()->set('database.connections.session_definition_owner', null);
        }
    }

    private function asNonBypassOwner(callable $callback): void
    {
        $runtime = DB::getDefaultConnection();
        $config = config('database.connections.'.$runtime);

        $this->asOwner(function (): void {
            DB::statement(<<<'SQL'
                CREATE ROLE session_definition_history_owner
                    LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS
                SQL);
            DB::statement('GRANT USAGE, CREATE ON SCHEMA public TO session_definition_history_owner');
            DB::statement('GRANT USAGE, CREATE ON SCHEMA app_private TO session_definition_history_owner');
            DB::statement('ALTER TABLE '.self::TABLE.' OWNER TO session_definition_history_owner');
            DB::statement('ALTER SEQUENCE assessment_session_definitions_id_seq OWNER TO session_definition_history_owner');
            DB::statement(<<<'SQL'
                ALTER FUNCTION app_private.guard_assessment_session_definitions()
                    OWNER TO session_definition_history_owner
                SQL);
        });

        config()->set('database.connections.session_definition_nonbypass_owner', [
            ...$config,
            'username' => 'session_definition_history_owner',
        ]);
        DB::setDefaultConnection('session_definition_nonbypass_owner');
        Schema::clearResolvedInstance('db.schema');

        try {
            $callback();
        } finally {
            DB::setDefaultConnection($runtime);
            Schema::clearResolvedInstance('db.schema');
            DB::purge('session_definition_nonbypass_owner');
            config()->set('database.connections.session_definition_nonbypass_owner', null);

            $this->asOwner(function (): void {
                DB::statement('ALTER TABLE '.self::TABLE.' OWNER TO org_test_owner');
                DB::statement('ALTER SEQUENCE assessment_session_definitions_id_seq OWNER TO org_test_owner');
                DB::statement(<<<'SQL'
                    ALTER FUNCTION app_private.guard_assessment_session_definitions()
                        OWNER TO org_test_owner
                    SQL);
                DB::statement('REVOKE USAGE, CREATE ON SCHEMA public FROM session_definition_history_owner');
                DB::statement('REVOKE USAGE, CREATE ON SCHEMA app_private FROM session_definition_history_owner');
                DB::statement('DROP ROLE session_definition_history_owner');
            });
        }
    }
}
