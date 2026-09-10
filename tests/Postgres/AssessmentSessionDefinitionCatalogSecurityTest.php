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

    private function assertSqlState(string $state, callable $operation): void
    {
        try {
            $operation();
            $this->fail("Expected SQLSTATE {$state}.");
        } catch (QueryException $exception) {
            $this->assertSame($state, $exception->errorInfo[0] ?? null, $exception->getMessage());
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
