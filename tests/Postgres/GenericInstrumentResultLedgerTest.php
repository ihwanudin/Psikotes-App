<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Domain\AssessmentSessions\SessionDefinition;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class GenericInstrumentResultLedgerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_schema_binds_an_immutable_generic_parent_to_ordered_source_evidence(): void
    {
        $this->assertTrue(Schema::hasTable('generic_instrument_results'));
        $this->assertTrue(Schema::hasTable('generic_instrument_result_sources'));

        $columns = collect(DB::select(<<<'SQL'
            SELECT table_name, column_name
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name IN (
                'generic_instrument_results',
                'generic_instrument_result_sources'
              )
            ORDER BY table_name, ordinal_position
            SQL))->groupBy('table_name')->map(
            fn ($rows) => $rows->pluck('column_name')->all(),
        )->all();

        $this->assertSame([
            'id', 'public_id', 'assessment_case_id', 'session_id', 'participant_id',
            'session_public_id', 'instrument_code', 'attempt_no', 'submitted_at',
            'answers_revision', 'sealed_source_checksum', 'session_definition_version',
            'session_definition_provenance', 'session_definition_checksum',
            'session_definition_payload', 'instrument_version_id', 'instrument_version',
            'instrument_source_file', 'instrument_checksum', 'result_contract_version',
            // engine_version (added by 2026_09_22_000100) lands last, not
            // right after result_contract_version -- Postgres ALTER TABLE
            // ADD COLUMN has no positioning, unlike MySQL's AFTER clause.
            'result_payload', 'result_checksum', 'created_at', 'engine_version',
        ], $columns['generic_instrument_results'] ?? []);
        $this->assertSame([
            'id', 'result_id', 'ordinal', 'source_code', 'raw_score',
            'standard_score', 'source_score', 'level', 'category', 'band_low',
            'band_high', 'created_at',
        ], $columns['generic_instrument_result_sources'] ?? []);

        $foreignKeys = collect(DB::select(<<<'SQL'
            SELECT constraint_name
            FROM information_schema.table_constraints
            WHERE table_schema = 'public'
              AND table_name IN (
                'generic_instrument_results',
                'generic_instrument_result_sources'
              )
              AND constraint_type = 'FOREIGN KEY'
            ORDER BY constraint_name
            SQL))->pluck('constraint_name')->all();
        $this->assertContains('generic_instrument_results_session_scope_fk', $foreignKeys);
        $this->assertContains('generic_instrument_results_instrument_version_fk', $foreignKeys);
        $this->assertContains('generic_instrument_result_sources_parent_fk', $foreignKeys);
    }

    public function test_runtime_is_nonbypass_and_only_service_can_append_or_read(): void
    {
        foreach (['generic_instrument_results', 'generic_instrument_result_sources'] as $table) {
            $identity = DB::selectOne(<<<SQL
                SELECT role.rolsuper, role.rolbypassrls, class.relrowsecurity,
                    class.relforcerowsecurity, pg_get_userbyid(class.relowner) owner
                FROM pg_roles role CROSS JOIN pg_class class
                WHERE role.rolname=current_user AND class.oid='{$table}'::regclass
                SQL);
            $this->assertFalse($identity->rolsuper);
            $this->assertFalse($identity->rolbypassrls);
            $this->assertNotSame('psikotes_runtime', $identity->owner);
            $this->assertTrue($identity->relrowsecurity);
            $this->assertTrue($identity->relforcerowsecurity);
            foreach (['SELECT', 'INSERT'] as $privilege) {
                $this->assertTrue((bool) DB::scalar(
                    "SELECT has_table_privilege('psikotes_runtime', ?, ?)",
                    [$table, $privilege],
                ));
            }
            foreach (['UPDATE', 'DELETE', 'TRUNCATE', 'TRIGGER', 'REFERENCES'] as $privilege) {
                $this->assertFalse((bool) DB::scalar(
                    "SELECT has_table_privilege('psikotes_runtime', ?, ?)",
                    [$table, $privilege],
                ));
            }
        }
        $sequences = DB::select(<<<'SQL'
            SELECT sequence.relname,pg_get_userbyid(sequence.relowner) owner,
                pg_get_userbyid(table_row.relowner) table_owner,attribute.attname,
                pg_get_expr(default_value.adbin,default_value.adrelid) default_value,
                has_sequence_privilege('psikotes_runtime', sequence.oid, 'USAGE') runtime_usage,
                has_sequence_privilege('psikotes_runtime', sequence.oid, 'SELECT') runtime_select,
                has_sequence_privilege('psikotes_runtime', sequence.oid, 'UPDATE') runtime_update
            FROM pg_class sequence
            JOIN pg_depend dependency ON dependency.classid='pg_class'::regclass
              AND dependency.objid=sequence.oid AND dependency.deptype='a'
            JOIN pg_class table_row ON table_row.oid=dependency.refobjid
            JOIN pg_attribute attribute ON attribute.attrelid=table_row.oid
              AND attribute.attnum=dependency.refobjsubid
            JOIN pg_attrdef default_value ON default_value.adrelid=table_row.oid
              AND default_value.adnum=attribute.attnum
            WHERE sequence.relkind='S' AND sequence.relname IN (
                'generic_instrument_results_id_seq',
                'generic_instrument_result_sources_id_seq'
            ) ORDER BY sequence.relname
            SQL);
        $this->assertCount(2, $sequences);
        foreach ($sequences as $sequence) {
            $this->assertSame($sequence->table_owner, $sequence->owner);
            $this->assertSame('id', $sequence->attname);
            $this->assertSame("nextval('{$sequence->relname}'::regclass)", $sequence->default_value);
            $this->assertTrue($sequence->runtime_usage);
            $this->assertTrue($sequence->runtime_select);
            $this->assertFalse($sequence->runtime_update);
        }

        $fixture = app(RlsContextRunner::class)->runAsService(fn (): array => $this->fixture());
        $row = $this->resultRow($fixture);
        DB::select("SELECT set_config('app.role', '', true)");
        $this->assertSqlState('42501', fn () => DB::table('generic_instrument_results')->insert($row));
        app(RlsContextRunner::class)->run(new RlsContext('participant', $fixture['branch'], $fixture['participant']),
            fn () => $this->assertSqlState(
                '42501', fn () => DB::table('generic_instrument_results')->insert($row),
            ));

        app(RlsContextRunner::class)->runAsService(function () use ($row): void {
            $result = DB::table('generic_instrument_results')->insertGetId($row);
            DB::table('generic_instrument_result_sources')->insert($this->sourceRow($result));
            $this->assertSame(1, DB::table('generic_instrument_results')->count());
            $this->assertSame(1, DB::table('generic_instrument_result_sources')->count());
            $this->assertSqlState('42501', fn () => DB::table('generic_instrument_results')
                ->where('id', $result)->update(['result_checksum' => str_repeat('f', 64)]));
            $this->assertSqlState('42501', fn () => DB::table('generic_instrument_result_sources')
                ->where('result_id', $result)->delete());
        });
    }

    public function test_insert_guard_rejects_mismatched_source_copies_and_non_submitted_sessions(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            $fixture = $this->fixture();
            $row = $this->resultRow($fixture);
            foreach ([
                'attempt_no' => 2,
                'submitted_at' => now()->addSecond(),
                'answers_revision' => 2,
                'session_definition_version' => 'synthetic-v2',
                'session_definition_provenance' => 'counterfeit',
                'session_definition_checksum' => str_repeat('f', 64),
                'session_definition_payload' => json_encode(['counterfeit' => true], JSON_THROW_ON_ERROR),
            ] as $column => $value) {
                $this->assertSqlState('23514', fn () => DB::table('generic_instrument_results')->insert([
                    ...$row, $column => $value, 'public_id' => (string) Str::ulid(),
                ]));
            }

            $other = $this->fixture('created');
            $this->assertSqlState('23514', fn () => DB::table('generic_instrument_results')
                ->insert($this->resultRow($other)));
            $this->assertSqlState('23514', fn () => DB::table('generic_instrument_results')->insert([
                ...$row, 'assessment_case_id' => $other['case'], 'public_id' => (string) Str::ulid(),
            ]));
            $this->assertSame(0, DB::table('generic_instrument_results')->count());
        });
    }

    public function test_composite_version_identity_and_one_initial_result_per_session_cannot_mix(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            $fixture = $this->fixture();
            $row = $this->resultRow($fixture);
            $otherVersion = $this->instrumentVersion('synthetic-v2');

            $this->assertSqlState('23503', fn () => DB::table('generic_instrument_results')->insert([
                ...$row, 'instrument_version_id' => $otherVersion,
                'public_id' => (string) Str::ulid(),
            ]));
            DB::table('generic_instrument_results')->insert($row);
            $this->assertSqlState('23505', fn () => DB::table('generic_instrument_results')->insert([
                ...$row, 'public_id' => (string) Str::ulid(),
                'result_checksum' => str_repeat('f', 64),
            ]));
            $this->assertSame(1, DB::table('generic_instrument_results')->count());
        });
    }

    public function test_ordered_sources_are_parent_bound_unique_and_immutable(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            $fixture = $this->fixture();
            $result = DB::table('generic_instrument_results')->insertGetId($this->resultRow($fixture));
            DB::table('generic_instrument_result_sources')->insert($this->sourceRow($result));
            $this->assertSqlState('23505', fn () => DB::table('generic_instrument_result_sources')->insert([
                ...$this->sourceRow($result), 'source_code' => 'WA',
            ]));
            $this->assertSqlState('23505', fn () => DB::table('generic_instrument_result_sources')->insert([
                ...$this->sourceRow($result), 'ordinal' => 2,
            ]));
            $this->assertSqlState('23503', fn () => DB::table('generic_instrument_result_sources')
                ->insert($this->sourceRow($result + 99999)));
            $this->assertSame(1, DB::table('generic_instrument_result_sources')->count());
        });
    }

    public function test_two_runtime_connections_race_one_initial_result_for_the_same_session(): void
    {
        $fixture = app(RlsContextRunner::class)->runAsService(fn (): array => $this->fixture());
        $row = app(RlsContextRunner::class)->runAsService(fn (): array => $this->resultRow($fixture));
        $rows = [
            [...$row, 'public_id' => (string) Str::ulid()],
            [...$row, 'public_id' => (string) Str::ulid()],
        ];
        DB::commit();
        DB::purge('pgsql');
        $workers = [];

        try {
            $workers[] = $this->startInsertWorker($rows[0]);
            $workers[] = $this->startInsertWorker($rows[1]);
            foreach ($workers as $worker) {
                fwrite($worker['socket'], "go\n");
            }
            $read = [$workers[0]['socket'], $workers[1]['socket']];
            $write = null;
            $except = null;
            $this->assertSame(1, stream_select($read, $write, $except, 10));
            $holder = reset($read) === $workers[0]['socket'] ? 0 : 1;
            $waiter = $holder === 0 ? 1 : 0;
            $this->assertSame('inserted', $this->readInsertEvent($workers[$holder]['socket'])['event']);

            $deadline = microtime(true) + 5;
            do {
                $waiting = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [
                    $workers[$waiter]['backend'],
                ]);
                if ($waiting?->wait_event_type === 'Lock') {
                    break;
                }
                usleep(10_000);
            } while (microtime(true) < $deadline);
            $this->assertSame('Lock', $waiting?->wait_event_type);

            fwrite($workers[$holder]['socket'], "release\n");
            $committed = $this->readInsertEvent($workers[$holder]['socket']);
            $rejected = $this->readInsertEvent($workers[$waiter]['socket']);
            $this->assertSame('committed', $committed['event']);
            $this->assertSame('sqlstate', $rejected['event']);
            $this->assertSame('23505', $rejected['state']);

            app(RlsContextRunner::class)->runAsService(function () use ($fixture): void {
                $this->assertSame(1, DB::table('generic_instrument_results')
                    ->where('session_id', $fixture['session'])->count());
                $this->assertSame(0, DB::table('generic_instrument_result_sources')
                    ->whereIn('result_id', DB::table('generic_instrument_results')
                        ->select('id')->where('session_id', $fixture['session']))->count());
            });
        } finally {
            $this->stopInsertWorkers($workers);
            $this->asOwner(function (): void {
                DB::statement('TRUNCATE TABLE generic_instrument_result_sources, generic_instrument_results RESTART IDENTITY');
            });
        }
    }

    public function test_owner_rerun_is_unchanged_and_populated_down_refuses_atomically(): void
    {
        $this->asOwner(function (): void {
            DB::beginTransaction();
            try {
                DB::statement("SELECT set_config('app.role', 'service', true)");
                $fixture = $this->fixture();
                $result = DB::table('generic_instrument_results')->insertGetId($this->resultRow($fixture));
                DB::table('generic_instrument_result_sources')->insert($this->sourceRow($result));
                $this->assertSqlState('P0001', fn () => DB::table('generic_instrument_results')
                    ->where('id', $result)->update(['result_checksum' => str_repeat('f', 64)]));
                $this->assertSqlState('P0001', fn () => DB::table('generic_instrument_result_sources')
                    ->where('result_id', $result)->delete());
                $before = $this->definitions();
                $migration = require database_path('migrations/2026_09_13_000100_create_generic_instrument_result_ledger.php');

                $migration->up();
                $this->assertEquals($before, $this->definitions());
                try {
                    $migration->down();
                    $this->fail('Populated result history must refuse rollback.');
                } catch (RuntimeException $exception) {
                    $this->assertSame('Generic instrument result history prevents rollback.', $exception->getMessage());
                }
                $this->assertEquals($before, $this->definitions());
                $this->assertSame(1, DB::table('generic_instrument_results')->count());
                $this->assertSame(1, DB::table('generic_instrument_result_sources')->count());
            } finally {
                DB::rollBack();
            }
        });
    }

    public function test_owner_rerun_rejects_counterfeit_security_without_mutating_it(): void
    {
        $this->asOwner(function (): void {
            DB::beginTransaction();
            try {
                DB::unprepared(<<<'SQL'
                    DROP POLICY generic_instrument_results_service_select
                        ON generic_instrument_results;
                    CREATE POLICY generic_instrument_results_service_select
                        ON generic_instrument_results FOR SELECT TO psikotes_runtime
                        USING (app_private.app_role() = 'service' OR true);
                    SQL);
                $counterfeit = $this->definitions();

                $exception = null;
                try {
                    (require database_path('migrations/2026_09_13_000100_create_generic_instrument_result_ledger.php'))->up();
                } catch (RuntimeException $caught) {
                    $exception = $caught;
                }
                $this->assertNotNull($exception, 'Counterfeit result ledger security must be rejected.');
                $this->assertStringContainsString('policy predicate is not exact', $exception->getMessage());
                $this->assertEquals($counterfeit, $this->definitions());
            } finally {
                DB::rollBack();
            }
        });
    }

    public function test_owner_rerun_rejects_same_name_counterfeit_checks_without_mutation(): void
    {
        $this->asOwner(function (): void {
            DB::beginTransaction();
            try {
                DB::statement('ALTER TABLE generic_instrument_results DROP CONSTRAINT generic_instrument_results_contract_check');
                DB::statement('ALTER TABLE generic_instrument_results ADD CONSTRAINT generic_instrument_results_contract_check CHECK (true)');
                DB::statement('ALTER TABLE generic_instrument_result_sources DROP CONSTRAINT generic_instrument_result_sources_contract_check');
                DB::statement('ALTER TABLE generic_instrument_result_sources ADD CONSTRAINT generic_instrument_result_sources_contract_check CHECK (true)');
                $counterfeit = $this->definitions();

                $exception = null;
                try {
                    (require database_path('migrations/2026_09_13_000100_create_generic_instrument_result_ledger.php'))->up();
                } catch (RuntimeException $caught) {
                    $exception = $caught;
                }
                $this->assertNotNull($exception, 'Counterfeit result ledger checks must be rejected.');
                $this->assertStringContainsString('check constraint is not exact', $exception->getMessage());
                $this->assertEquals($counterfeit, $this->definitions());
            } finally {
                DB::rollBack();
            }
        });
    }

    public function test_owner_rerun_rejects_excess_runtime_sequence_grant_without_mutation(): void
    {
        $this->asOwner(function (): void {
            DB::beginTransaction();
            try {
                DB::statement('GRANT UPDATE ON SEQUENCE generic_instrument_results_id_seq TO psikotes_runtime');
                $counterfeit = $this->definitions();

                $exception = null;
                try {
                    (require database_path('migrations/2026_09_13_000100_create_generic_instrument_result_ledger.php'))->up();
                } catch (RuntimeException $caught) {
                    $exception = $caught;
                }
                $this->assertNotNull($exception, 'Excess runtime sequence privilege must be rejected.');
                $this->assertStringContainsString('not exact', $exception->getMessage());
                $this->assertEquals($counterfeit, $this->definitions());
            } finally {
                DB::rollBack();
            }
        });
    }

    public function test_owner_rerun_rejects_missing_runtime_sequence_grant_without_mutation(): void
    {
        $this->asOwner(function (): void {
            DB::beginTransaction();
            try {
                DB::statement('REVOKE SELECT ON SEQUENCE generic_instrument_result_sources_id_seq FROM psikotes_runtime');
                $counterfeit = $this->definitions();

                $exception = null;
                try {
                    (require database_path('migrations/2026_09_13_000100_create_generic_instrument_result_ledger.php'))->up();
                } catch (RuntimeException $caught) {
                    $exception = $caught;
                }
                $this->assertNotNull($exception, 'Missing runtime sequence privilege must be rejected.');
                $this->assertStringContainsString('not exact', $exception->getMessage());
                $this->assertEquals($counterfeit, $this->definitions());
            } finally {
                DB::rollBack();
            }
        });
    }

    public function test_owner_rerun_rejects_runtime_grant_option_without_mutation(): void
    {
        $this->asOwner(function (): void {
            DB::beginTransaction();
            try {
                DB::statement('GRANT SELECT ON generic_instrument_results TO psikotes_runtime WITH GRANT OPTION');
                $counterfeit = $this->definitions();

                $exception = null;
                try {
                    (require database_path('migrations/2026_09_13_000100_create_generic_instrument_result_ledger.php'))->up();
                } catch (RuntimeException $caught) {
                    $exception = $caught;
                }
                $this->assertNotNull($exception, 'Runtime WITH GRANT OPTION must be rejected.');
                $this->assertStringContainsString('ACL', $exception->getMessage());
                $this->assertEquals($counterfeit, $this->definitions());
            } finally {
                DB::rollBack();
            }
        });
    }

    public function test_owner_rerun_rejects_deferred_unique_constraint_without_mutation(): void
    {
        $this->asOwner(function (): void {
            DB::beginTransaction();
            try {
                DB::statement('ALTER TABLE generic_instrument_result_sources DROP CONSTRAINT generic_instrument_result_sources_order_unique');
                DB::statement('ALTER TABLE generic_instrument_result_sources ADD CONSTRAINT generic_instrument_result_sources_order_unique UNIQUE (result_id, ordinal) DEFERRABLE INITIALLY DEFERRED');
                $counterfeit = $this->definitions();

                $exception = null;
                try {
                    (require database_path('migrations/2026_09_13_000100_create_generic_instrument_result_ledger.php'))->up();
                } catch (RuntimeException $caught) {
                    $exception = $caught;
                }
                $this->assertNotNull($exception, 'Deferred result uniqueness must be rejected.');
                $this->assertStringContainsString('shape is not exact', $exception->getMessage());
                $this->assertEquals($counterfeit, $this->definitions());
            } finally {
                DB::rollBack();
            }
        });
    }

    public function test_contract_checks_reject_representative_invalid_parent_and_source_rows(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            $fixture = $this->fixture();
            $row = $this->resultRow($fixture);
            $this->assertSqlState('23514', fn () => DB::table('generic_instrument_results')->insert([
                ...$row, 'sealed_source_checksum' => str_repeat('A', 64),
            ]));

            $result = DB::table('generic_instrument_results')->insertGetId($row);
            $this->assertSqlState('23514', fn () => DB::table('generic_instrument_result_sources')->insert([
                ...$this->sourceRow($result), 'level' => 6,
            ]));
            $this->assertSame(0, DB::table('generic_instrument_result_sources')->count());
        });
    }

    public function test_sqlite_rerun_rejects_counterfeit_trigger_body_without_mutation(): void
    {
        $runtime = DB::getDefaultConnection();
        config()->set('database.connections.result_ledger_sqlite', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::setDefaultConnection('result_ledger_sqlite');
        Schema::clearResolvedInstance('db.schema');
        try {
            DB::statement(<<<'SQL'
                CREATE TABLE instrument_versions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL,
                    version TEXT NOT NULL, source_file TEXT NOT NULL, checksum TEXT NOT NULL,
                    UNIQUE (code, version)
                )
                SQL);
            DB::statement(<<<'SQL'
                CREATE TABLE test_sessions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT NOT NULL,
                    assessment_case_id INTEGER NOT NULL, participant_id INTEGER NOT NULL,
                    test_type TEXT NOT NULL, attempt_no INTEGER NOT NULL,
                    status TEXT NOT NULL, submitted_at TEXT NULL, answers_revision INTEGER NOT NULL,
                    session_definition_version TEXT NULL,
                    session_definition_provenance TEXT NULL,
                    session_definition_checksum TEXT NULL,
                    session_definition_payload TEXT NULL
                )
                SQL);
            DB::statement('CREATE UNIQUE INDEX test_sessions_grant_scope_unique ON test_sessions (id, assessment_case_id, participant_id, test_type)');
            $migration = require database_path('migrations/2026_09_13_000100_create_generic_instrument_result_ledger.php');
            $migration->up();
            DB::unprepared(<<<'SQL'
                DROP TRIGGER generic_instrument_results_guard_update;
                CREATE TRIGGER generic_instrument_results_guard_update
                BEFORE UPDATE ON generic_instrument_results FOR EACH ROW
                BEGIN SELECT RAISE(ABORT, 'counterfeit'); END;
                SQL);
            $counterfeit = DB::select("SELECT type,name,sql FROM sqlite_master WHERE name LIKE 'generic_instrument_result%' OR name='instrument_versions_result_scope_unique' ORDER BY type,name");

            $exception = null;
            try {
                $migration->up();
            } catch (RuntimeException $caught) {
                $exception = $caught;
            }
            $this->assertNotNull($exception, 'Counterfeit SQLite guard must be rejected.');
            $this->assertStringContainsString('SQLite definition is not exact', $exception->getMessage());
            $this->assertEquals($counterfeit, DB::select("SELECT type,name,sql FROM sqlite_master WHERE name LIKE 'generic_instrument_result%' OR name='instrument_versions_result_scope_unique' ORDER BY type,name"));
        } finally {
            DB::setDefaultConnection($runtime);
            Schema::clearResolvedInstance('db.schema');
            DB::purge('result_ledger_sqlite');
            config()->set('database.connections.result_ledger_sqlite', null);
        }
    }

    /** @return array<string,mixed> */
    private function fixture(string $status = 'submitted'): array
    {
        $key = (string) Str::ulid();
        $branch = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => $key,
            'organization_code' => $key, 'display_name' => $key,
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $branch, 'referral_branch_id' => $branch,
            'referral_source' => 'default', 'source_system' => 'R2_SCHEMA_TEST',
            'full_name' => $key, 'phone' => '620000000000',
        ]);
        $case = DB::table('assessment_cases')->insertGetId([
            'public_id' => (string) Str::ulid(), 'participant_id' => $participant,
            'organization_id' => $branch, 'package_id' => null, 'origin' => 'DIRECT_PUBLIC',
            'intended_field_snapshot' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $definition = $this->definition();
        $started = now()->subMinute();
        $submitted = now();
        $sessionPublicId = (string) Str::ulid();
        $session = DB::table('test_sessions')->insertGetId([
            'public_id' => $sessionPublicId, 'participant_id' => $participant,
            'assessment_case_id' => $case, 'test_type' => 'ist', 'attempt_no' => 1,
            'authorization_id' => (string) Str::ulid(), 'allocation_intent_id' => (string) Str::ulid(),
            'duration_seconds' => 60, 'status' => $status,
            'answers_revision' => $status === 'created' ? 0 : 1,
            'started_at' => $status === 'created' ? null : $started,
            'ends_at' => $status === 'created' ? null : $started->copy()->addMinutes(2),
            'submitted_at' => $status === 'created' ? null : $submitted,
            'session_definition_version' => $definition['version'],
            'session_definition_provenance' => $definition['provenance'],
            'session_definition_checksum' => $definition['checksum'],
            'session_definition_payload' => json_encode($definition, JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $instrumentVersion = $this->instrumentVersion('synthetic-'.$key);

        return compact(
            'branch', 'participant', 'case', 'session', 'sessionPublicId',
            'submitted', 'definition', 'instrumentVersion',
        ) + ['status' => $status];
    }

    private function instrumentVersion(string $version): int
    {
        $payload = json_encode(['version' => $version], JSON_THROW_ON_ERROR);

        return DB::table('instrument_versions')->insertGetId([
            'code' => 'ist', 'version' => $version, 'source_file' => $version.'.json',
            'checksum' => hash('sha256', $payload), 'payload' => $payload,
            'is_active' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string,mixed> */
    private function definition(): array
    {
        $definition = [
            'instrument' => 'ist', 'version' => 'synthetic-v1',
            'provenance' => 'synthetic-test-fixture', 'checksum' => '',
            'total_duration_seconds' => 60,
            'subtests' => [['code' => 'SE', 'duration_seconds' => 60, 'item_count' => 1]],
            'randomization' => 'fixed', 'seed' => null, 'generator' => null,
        ];
        $definition['checksum'] = SessionDefinition::checksumFor($definition);

        return $definition;
    }

    /** @param array<string,mixed> $fixture
     * @return array<string,mixed>
     */
    private function resultRow(array $fixture): array
    {
        $instrument = DB::table('instrument_versions')->where('id', $fixture['instrumentVersion'])->first();
        $payload = json_encode(['resultContractVersion' => 'ist-result:v1'], JSON_THROW_ON_ERROR);

        return [
            'public_id' => (string) Str::ulid(), 'assessment_case_id' => $fixture['case'],
            'session_id' => $fixture['session'], 'participant_id' => $fixture['participant'],
            'session_public_id' => $fixture['sessionPublicId'], 'instrument_code' => 'ist',
            'attempt_no' => 1, 'submitted_at' => $fixture['submitted'] ?? now(),
            'answers_revision' => $fixture['status'] === 'created' ? 1 : 1,
            'sealed_source_checksum' => str_repeat('a', 64),
            'session_definition_version' => $fixture['definition']['version'],
            'session_definition_provenance' => $fixture['definition']['provenance'],
            'session_definition_checksum' => $fixture['definition']['checksum'],
            'session_definition_payload' => json_encode($fixture['definition'], JSON_THROW_ON_ERROR),
            'instrument_version_id' => $fixture['instrumentVersion'],
            'instrument_version' => $instrument->version,
            'instrument_source_file' => $instrument->source_file,
            'instrument_checksum' => $instrument->checksum,
            'result_contract_version' => 'ist-result:v1', 'engine_version' => 'ist-scoring:v1',
            'result_payload' => $payload,
            'result_checksum' => hash('sha256', $payload), 'created_at' => now(),
        ];
    }

    /** @return array<string,mixed> */
    private function sourceRow(int $result): array
    {
        return [
            'result_id' => $result, 'ordinal' => 1, 'source_code' => 'SE',
            'raw_score' => 1, 'standard_score' => 100, 'source_score' => 100,
            'level' => 3, 'category' => 'synthetic', 'band_low' => 90,
            'band_high' => 109, 'created_at' => now(),
        ];
    }

    private function assertSqlState(string $state, callable $operation): void
    {
        DB::beginTransaction();
        try {
            $operation();
            $this->fail("Expected SQLSTATE {$state}.");
        } catch (QueryException $exception) {
            $this->assertSame($state, $exception->errorInfo[0] ?? null, $exception->getMessage());
        } finally {
            DB::rollBack();
        }
    }

    /** @param array<string,mixed> $row
     * @return array{pid:int,backend:int,socket:resource}
     */
    private function startInsertWorker(array $row): array
    {
        $this->assertTrue(function_exists('pcntl_fork'), 'Result uniqueness concurrency requires pcntl; never skip.');
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($pair === false || ($pid = pcntl_fork()) === -1) {
            throw new RuntimeException('Unable to create result insert worker.');
        }
        if ($pid === 0) {
            fclose($pair[0]);
            stream_set_timeout($pair[1], 20);
            try {
                DB::purge('pgsql');
                $identity = DB::selectOne(
                    'SELECT pg_backend_pid() pid,current_user name,rolsuper,rolbypassrls FROM pg_roles WHERE rolname=current_user',
                );
                if ($identity->name !== 'psikotes_runtime' || $identity->rolsuper || $identity->rolbypassrls) {
                    throw new RuntimeException('Result insert worker must be runtime NOBYPASSRLS.');
                }
                DB::statement("SET lock_timeout = '12s'");
                DB::statement("SET statement_timeout = '15s'");
                $this->writeInsertEvent($pair[1], ['event' => 'ready', 'backend' => (int) $identity->pid]);
                if (fgets($pair[1]) !== "go\n") {
                    throw new RuntimeException('Result insert barrier timed out.');
                }
                app(RlsContextRunner::class)->runAsService(function () use ($row, $pair): void {
                    DB::table('generic_instrument_results')->insert($row);
                    $this->writeInsertEvent($pair[1], ['event' => 'inserted']);
                    if (fgets($pair[1]) !== "release\n") {
                        throw new RuntimeException('Result commit barrier timed out.');
                    }
                });
                $this->writeInsertEvent($pair[1], ['event' => 'committed']);
            } catch (QueryException $exception) {
                $this->writeInsertEvent($pair[1], [
                    'event' => 'sqlstate', 'state' => $exception->errorInfo[0] ?? null,
                ]);
            } catch (Throwable $exception) {
                $this->writeInsertEvent($pair[1], [
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
        $ready = $this->readInsertEvent($pair[0]);
        $this->assertSame('ready', $ready['event'], json_encode($ready, JSON_THROW_ON_ERROR));

        return ['pid' => $pid, 'backend' => $ready['backend'], 'socket' => $pair[0]];
    }

    /** @param resource $socket
     * @return array<string,mixed>
     */
    private function readInsertEvent($socket): array
    {
        $line = fgets($socket);
        if (! is_string($line)) {
            throw new RuntimeException('Result insert worker did not report an event.');
        }
        $event = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($event) || ! is_string($event['event'] ?? null)) {
            throw new RuntimeException('Result insert worker event is invalid.');
        }

        return $event;
    }

    /** @param resource $socket
     * @param  array<string,mixed>  $event
     */
    private function writeInsertEvent($socket, array $event): void
    {
        fwrite($socket, json_encode($event, JSON_THROW_ON_ERROR)."\n");
    }

    /** @param list<array{pid:int,backend:int,socket:resource}> $workers */
    private function stopInsertWorkers(array $workers): void
    {
        foreach ($workers as $worker) {
            fclose($worker['socket']);
            pcntl_waitpid($worker['pid'], $status);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
    }

    /** @return array<string,mixed> */
    private function definitions(): array
    {
        return [
            'tables' => DB::select("SELECT relname,relrowsecurity,relforcerowsecurity FROM pg_class WHERE relname IN ('generic_instrument_results','generic_instrument_result_sources') ORDER BY relname"),
            'constraints' => DB::select("SELECT conrelid::regclass::text relation,conname,pg_get_constraintdef(oid,false) definition FROM pg_constraint WHERE conrelid IN ('generic_instrument_results'::regclass,'generic_instrument_result_sources'::regclass) ORDER BY relation,conname"),
            'indexes' => DB::select("SELECT indexname,indexdef FROM pg_indexes WHERE schemaname='public' AND (tablename IN ('generic_instrument_results','generic_instrument_result_sources') OR indexname='instrument_versions_result_scope_unique') ORDER BY indexname"),
            'triggers' => DB::select("SELECT tgrelid::regclass::text relation,tgname,pg_get_triggerdef(oid,false) definition FROM pg_trigger WHERE NOT tgisinternal AND tgrelid IN ('generic_instrument_results'::regclass,'generic_instrument_result_sources'::regclass) ORDER BY relation,tgname"),
            'policies' => DB::select("SELECT tablename,policyname,cmd,roles,qual,with_check FROM pg_policies WHERE tablename IN ('generic_instrument_results','generic_instrument_result_sources') ORDER BY tablename,policyname"),
            'acl' => DB::select(<<<'SQL'
                SELECT class.relname,pg_get_userbyid(class.relowner) owner,
                    pg_get_userbyid(acl.grantor) grantor,
                    CASE WHEN acl.grantee=0 THEN 'PUBLIC' ELSE pg_get_userbyid(acl.grantee) END grantee,
                    acl.privilege_type,acl.is_grantable
                FROM pg_class class
                CROSS JOIN LATERAL aclexplode(COALESCE(
                    class.relacl,
                    acldefault(CASE WHEN class.relkind='S' THEN 'S'::"char" ELSE 'r'::"char" END, class.relowner)
                )) acl
                WHERE class.oid IN (
                    'public.generic_instrument_results'::regclass,
                    'public.generic_instrument_result_sources'::regclass,
                    'public.generic_instrument_results_id_seq'::regclass,
                    'public.generic_instrument_result_sources_id_seq'::regclass
                ) AND acl.grantee <> class.relowner
                ORDER BY class.relname,grantee,acl.privilege_type
                SQL),
            'sequences' => DB::select(<<<'SQL'
                SELECT class.relname,pg_get_userbyid(class.relowner) owner,
                    has_sequence_privilege('psikotes_runtime', class.oid, 'USAGE') runtime_usage,
                    has_sequence_privilege('psikotes_runtime', class.oid, 'SELECT') runtime_select,
                    has_sequence_privilege('psikotes_runtime', class.oid, 'UPDATE') runtime_update
                FROM pg_class class
                WHERE class.relkind='S' AND class.relname IN (
                    'generic_instrument_results_id_seq',
                    'generic_instrument_result_sources_id_seq'
                ) ORDER BY class.relname
                SQL),
        ];
    }

    private function asOwner(callable $callback): void
    {
        $runtime = DB::getDefaultConnection();
        $config = config('database.connections.'.$runtime);
        config()->set('database.connections.result_ledger_owner', [...$config, 'username' => 'org_test_owner']);
        DB::setDefaultConnection('result_ledger_owner');
        Schema::clearResolvedInstance('db.schema');
        try {
            $this->assertSame('org_test_owner', DB::selectOne('SELECT current_user AS name')->name);
            $callback();
        } finally {
            DB::setDefaultConnection($runtime);
            Schema::clearResolvedInstance('db.schema');
            DB::purge('result_ledger_owner');
            config()->set('database.connections.result_ledger_owner', null);
        }
    }
}
