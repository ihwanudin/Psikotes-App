<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/** PostgreSQL-authoritative generic assessment session schema and RLS proof. */
final class AssessmentSessionSchemaTest extends TestCase
{
    private int $attemptSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_runtime_is_non_owner_nobypassrls_with_forced_rls_and_microsecond_columns(): void
    {
        $identity = DB::selectOne(<<<'SQL'
            SELECT current_user AS name, rolsuper, rolbypassrls,
                pg_get_userbyid(class.relowner) AS table_owner
            FROM pg_roles role
            CROSS JOIN pg_class class
            WHERE role.rolname = current_user AND class.oid = 'test_sessions'::regclass
            SQL);
        $this->assertSame('psikotes_runtime', $identity->name);
        $this->assertFalse($identity->rolsuper);
        $this->assertFalse($identity->rolbypassrls);
        $this->assertNotSame('psikotes_runtime', $identity->table_owner);

        foreach (['test_sessions', 'answers', 'assessment_autosave_mutations'] as $table) {
            $security = DB::selectOne("SELECT relrowsecurity, relforcerowsecurity
                FROM pg_class WHERE oid = '{$table}'::regclass");
            $this->assertTrue($security->relrowsecurity);
            $this->assertTrue($security->relforcerowsecurity);
        }

        $types = DB::table('pg_attribute')->whereRaw("attrelid IN ('test_sessions'::regclass,
            'answers'::regclass, 'assessment_autosave_mutations'::regclass)")
            ->whereIn('attname', [
                'started_at', 'ends_at', 'submitted_at', 'scored_at', 'expired_at', 'voided_at',
                'answered_at', 'received_at', 'created_at', 'updated_at',
            ])->selectRaw('attrelid::regclass::text AS relation, attname, format_type(atttypid, atttypmod) AS type')
            ->get();
        $this->assertNotEmpty($types);
        foreach ($types as $column) {
            $this->assertSame('timestamp(6) with time zone', $column->type, "{$column->relation}.{$column->attname}");
        }

        $participantPolicy = DB::table('pg_policies')->where('tablename', 'test_sessions')
            ->where('policyname', 'test_sessions_participant_update')->sole();
        $this->assertSame('UPDATE', $participantPolicy->cmd);
        $this->assertSame('{psikotes_runtime}', $participantPolicy->roles);
        $this->assertFalse(DB::selectOne("SELECT has_table_privilege('psikotes_runtime',
            'assessment_autosave_mutations', 'UPDATE') AS allowed")->allowed);
        $this->assertFalse(DB::selectOne("SELECT has_table_privilege('psikotes_runtime',
            'assessment_autosave_mutations', 'DELETE') AS allowed")->allowed);
    }

    public function test_postgres_checks_reject_null_evidence_and_preserve_exact_microsecond_deadline(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            foreach ([
                ['status' => 'submitted', 'submitted_at' => null],
                ['status' => 'scored', 'scored_at' => null],
                ['status' => 'expired', 'expired_at' => null],
                ['status' => 'void', 'void_reason' => null],
            ] as $override) {
                $this->assertSqlState('23514', fn () => DB::table('test_sessions')->insert([
                    ...$this->sessionRow(null, $override['status']), ...$override,
                ]));
            }

            $exact = $this->sessionRow(null, 'submitted');
            $exact['started_at'] = '2026-09-08 03:00:00.000000+00';
            $exact['ends_at'] = '2026-09-08 04:00:00.000000+00';
            $exact['submitted_at'] = '2026-09-08 04:00:00.000000+00';
            DB::table('test_sessions')->insert($exact);

            $late = $this->sessionRow(null, 'submitted');
            $late['started_at'] = '2026-09-08 03:00:00.000000+00';
            $late['ends_at'] = '2026-09-08 04:00:00.000000+00';
            $late['submitted_at'] = '2026-09-08 04:00:00.000001+00';
            $this->assertSqlState('23514', fn () => DB::table('test_sessions')->insert($late));

            $expired = $this->sessionRow(null, 'expired');
            $expired['ends_at'] = '2026-09-08 04:00:00.000000+00';
            $expired['expired_at'] = '2026-09-08 04:00:00.000001+00';
            DB::table('test_sessions')->insert($expired);

            $exactMutationSession = DB::table('test_sessions')->insertGetId(
                $this->sessionRow(null, 'in_progress'),
            );
            $exactMutation = $this->mutationRow($exactMutationSession);
            $exactMutation['received_at'] = '2026-09-08 04:00:00.000000+00';
            DB::table('assessment_autosave_mutations')->insert($exactMutation);

            $lateMutationSession = DB::table('test_sessions')->insertGetId(
                $this->sessionRow(null, 'in_progress'),
            );
            $lateMutation = $this->mutationRow($lateMutationSession);
            $lateMutation['received_at'] = '2026-09-08 04:00:00.000001+00';
            $this->assertSqlState('P0001', fn () => DB::table('assessment_autosave_mutations')
                ->insert($lateMutation));
        });
    }

    public function test_role_matrix_is_tenant_scoped_and_raw_answers_are_restricted(): void
    {
        [$own, $foreign] = app(RlsContextRunner::class)->runAsService(function (): array {
            $own = $this->graph();
            $foreign = $this->graph();
            foreach ([$own, $foreign] as $graph) {
                $session = DB::table('test_sessions')->insertGetId($this->sessionRow($graph['participant'], 'in_progress'));
                DB::table('assessment_autosave_mutations')->insert($this->mutationRow($session));
                DB::table('answers')->insert($this->answerRow($session));
                DB::table('test_sessions')->where('id', $session)->update(['answers_revision' => 1]);
            }
            DB::statement('SET CONSTRAINTS answers_ledger_guard IMMEDIATE');
            DB::statement('SET CONSTRAINTS answers_ledger_guard DEFERRED');

            return [$own, $foreign];
        });

        DB::select("SELECT set_config('app.role', '', true), set_config('app.branch_id', '', true),
            set_config('app.participant_id', '', true)");
        $this->assertSame(0, DB::table('test_sessions')->count());
        $this->assertSame(0, DB::table('answers')->count());
        $this->assertSqlState('42501', fn () => DB::table('test_sessions')->insert(
            $this->sessionRow($own['participant']),
        ));
        $this->assertSame(0, DB::table('test_sessions')->update(['answers_revision' => 2]));
        $this->assertSame(0, DB::table('test_sessions')->delete());

        app(RlsContextRunner::class)->run(
            new RlsContext('participant', $own['branch'], $own['participant']),
            function (): void {
                $this->assertSame(1, DB::table('test_sessions')->count());
                $this->assertSame(1, DB::table('answers')->count());
                $this->assertSame(1, DB::table('assessment_autosave_mutations')->count());
                $this->assertSqlState('42501', fn () => DB::table('answers')->insert([
                    ...$this->answerRow((int) DB::table('test_sessions')->value('id')),
                    'item_no' => 2, 'revision' => 2,
                ]));
                $this->assertSqlState('42501', fn () => DB::table('assessment_autosave_mutations')->insert([
                    ...$this->mutationRow((int) DB::table('test_sessions')->value('id')), 'revision' => 2,
                ]));
                $this->assertSame(0, DB::table('answers')->update(['revision' => 2]));
                $this->assertSame(0, DB::table('test_sessions')->delete());
            },
        );

        foreach (['branch_admin', 'staff'] as $role) {
            app(RlsContextRunner::class)->run(new RlsContext($role, $own['branch']), function (): void {
                $this->assertSame(1, DB::table('test_sessions')->count());
                $this->assertSame(0, DB::table('answers')->count());
                $this->assertSame(0, DB::table('assessment_autosave_mutations')->count());
            });
        }
        app(RlsContextRunner::class)->run(new RlsContext('psychologist'), function (): void {
            $this->assertSame(2, DB::table('test_sessions')->count());
            $this->assertSame(2, DB::table('answers')->count());
            $this->assertSame(2, DB::table('assessment_autosave_mutations')->count());
        });
        app(RlsContextRunner::class)->run(new RlsContext('super_admin'), function (): void {
            $this->assertSame(2, DB::table('test_sessions')->count());
            $this->assertSame(0, DB::table('answers')->count());
            $this->assertSame(0, DB::table('assessment_autosave_mutations')->count());
        });
        DB::select("SELECT set_config('app.role', '', true), set_config('app.branch_id', '', true),
            set_config('app.participant_id', '', true)");
        $this->assertSame(0, DB::table('test_sessions')->count());
        $this->assertNotSame($own['participant'], $foreign['participant']);
    }

    public function test_participant_session_update_is_narrow_and_server_authoritative(): void
    {
        $graph = app(RlsContextRunner::class)->runAsService(function (): array {
            $graph = $this->graph();
            $graph['session'] = DB::table('test_sessions')->insertGetId($this->sessionRow($graph['participant']));

            return $graph;
        });

        app(RlsContextRunner::class)->run(
            new RlsContext('participant', $graph['branch'], $graph['participant']),
            function () use ($graph): void {
                $this->assertSame(1, DB::table('test_sessions')->where('id', $graph['session'])
                    ->update(['status' => 'in_progress']));
                $started = DB::table('test_sessions')->where('id', $graph['session'])->first();
                $duration = DB::selectOne('SELECT EXTRACT(EPOCH FROM (?::timestamptz - ?::timestamptz)) AS seconds', [
                    $started->ends_at, $started->started_at,
                ]);
                $this->assertSame('3600.000000', $duration->seconds);

                $this->assertSqlState('P0001', fn () => DB::table('test_sessions')
                    ->where('id', $graph['session'])->update(['ends_at' => '2099-01-01 00:00:00+00']));
                $this->assertSqlState('P0001', fn () => DB::table('test_sessions')
                    ->where('id', $graph['session'])->update(['answers_revision' => 1]));
                $this->assertSame(1, DB::table('test_sessions')->where('id', $graph['session'])
                    ->update(['status' => 'submitted']));
                $this->assertNotNull(DB::table('test_sessions')->where('id', $graph['session'])->value('submitted_at'));
            },
        );
    }

    public function test_service_revision_bump_requires_exact_receipt_and_ledger_delete_is_denied(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            $session = DB::table('test_sessions')->insertGetId($this->sessionRow(null, 'in_progress'));
            $this->assertSqlState('P0001', fn () => DB::table('test_sessions')->where('id', $session)
                ->update(['answers_revision' => 1]));

            $other = DB::table('test_sessions')->insertGetId($this->sessionRow(null, 'in_progress'));
            DB::table('assessment_autosave_mutations')->insert($this->mutationRow($other));
            $this->assertSqlState('P0001', fn () => DB::table('test_sessions')->where('id', $session)
                ->update(['answers_revision' => 1]));

            DB::table('assessment_autosave_mutations')->insert($this->mutationRow($session));
            $this->assertSqlState('P0001', fn () => DB::table('test_sessions')->where('id', $session)
                ->update(['answers_revision' => 2]));
            DB::table('answers')->insert($this->answerRow($session));
            DB::table('test_sessions')->where('id', $session)->update(['answers_revision' => 1]);
            DB::statement('SET CONSTRAINTS answers_ledger_guard IMMEDIATE');
            DB::statement('SET CONSTRAINTS answers_ledger_guard DEFERRED');
            $this->assertSqlState('P0001', fn () => DB::table('test_sessions')->where('id', $session)
                ->update(['answers_revision' => 3]));
            $this->assertSqlState('42501', fn () => DB::table('assessment_autosave_mutations')
                ->where('session_id', $session)->delete());
        });
    }

    public function test_owner_empty_roundtrip_and_populated_rollback_are_guarded(): void
    {
        $runtime = DB::getDefaultConnection();
        $config = config('database.connections.'.$runtime);
        config()->set('database.connections.assessment_session_ddl_test', [...$config, 'username' => 'org_test_owner']);
        $owner = DB::connection('assessment_session_ddl_test');
        try {
            $owner->beginTransaction();
            DB::setDefaultConnection('assessment_session_ddl_test');
            Schema::clearResolvedInstance('db.schema');
            $this->runMigration('down');
            $this->assertFalse(Schema::hasTable('test_sessions'));
            $this->runMigration('up');

            DB::statement("SELECT set_config('app.role', 'service', true)");
            DB::table('test_sessions')->insert($this->sessionRow());
            try {
                $this->runMigration('down');
                $this->fail('Populated rollback was accepted.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Assessment session history prevents rollback.', $exception->getMessage());
            }
            $this->assertTrue(Schema::hasTable('test_sessions'));
        } finally {
            if ($owner->transactionLevel() > 0) {
                $owner->rollBack();
            }
            DB::setDefaultConnection($runtime);
            Schema::clearResolvedInstance('db.schema');
            DB::purge('assessment_session_ddl_test');
            config()->set('database.connections.assessment_session_ddl_test', null);
        }
        $this->assertSame('psikotes_runtime', DB::selectOne('SELECT current_user AS name')->name);
    }

    /** @return array{branch:int,participant:int} */
    private function graph(): array
    {
        $key = (string) Str::ulid();
        $branch = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => 'Synthetic',
            'organization_code' => $key, 'display_name' => 'Synthetic',
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $branch, 'referral_branch_id' => $branch, 'referral_source' => 'default',
            'full_name' => 'Synthetic', 'phone' => '620000000000',
        ]);

        return compact('branch', 'participant');
    }

    /** @return array<string, mixed> */
    private function sessionRow(?int $participant = null, string $status = 'created'): array
    {
        $started = in_array($status, ['in_progress', 'submitted', 'scored', 'expired'], true)
            ? '2026-09-08 03:00:00.000000+00' : null;
        $ends = $started === null ? null : '2026-09-08 04:00:00.000000+00';

        return [
            'public_id' => (string) Str::ulid(),
            'participant_id' => $participant ?? $this->graph()['participant'], 'test_type' => 'ist',
            'attempt_no' => ++$this->attemptSequence, 'authorization_id' => (string) Str::ulid(),
            'allocation_intent_id' => (string) Str::ulid(), 'duration_seconds' => 3600,
            'status' => $status, 'answers_revision' => 0, 'started_at' => $started, 'ends_at' => $ends,
            'submitted_at' => in_array($status, ['submitted', 'scored'], true) ? $ends : null,
            'scored_at' => $status === 'scored' ? '2026-09-08 04:00:00.000001+00' : null,
            'expired_at' => $status === 'expired' ? '2026-09-08 04:00:00.000001+00' : null,
            'voided_at' => $status === 'void' ? '2026-09-08 03:30:00.000000+00' : null,
            'void_reason' => $status === 'void' ? 'authorized correction' : null,
        ];
    }

    /** @return array<string, mixed> */
    private function answerRow(int $session): array
    {
        return ['session_id' => $session, 'item_no' => 1, 'value' => json_encode(['choice' => 'A']),
            'revision' => 1, 'answered_at' => '2026-09-08 03:05:00.000000+00'];
    }

    /** @return array<string, mixed> */
    private function mutationRow(int $session): array
    {
        return ['session_id' => $session, 'mutation_id' => (string) Str::ulid(), 'revision' => 1,
            'request_hash' => str_repeat('a', 64), 'accepted_item_numbers' => json_encode([1]),
            'received_at' => '2026-09-08 03:05:00.000000+00',
            'created_at' => '2026-09-08 03:05:00.000000+00'];
    }

    private function assertSqlState(string $state, callable $operation): void
    {
        DB::beginTransaction();
        try {
            $operation();
            $this->fail("Expected PostgreSQL SQLSTATE {$state}.");
        } catch (QueryException $exception) {
            $this->assertSame($state, $exception->getCode());
        } finally {
            DB::rollBack();
        }
    }

    private function runMigration(string $method): void
    {
        if ($method === 'down' && Schema::hasTable('test_session_grants')) {
            (require database_path('migrations/2026_09_09_000700_create_test_session_grants.php'))->down();
        }
        $migration = require database_path('migrations/2026_09_08_000100_create_generic_assessment_sessions.php');
        if (! is_object($migration) || ! in_array($method, ['up', 'down'], true) || ! method_exists($migration, $method)) {
            throw new RuntimeException('Assessment session migration is invalid.');
        }
        (new ReflectionMethod($migration, $method))->invoke($migration);
    }
}
