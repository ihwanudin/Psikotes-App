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
            'result_payload', 'result_checksum', 'created_at',
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
                        ON generic_instrument_results FOR SELECT TO psikotes_runtime USING (true);
                    SQL);
                $counterfeit = $this->definitions();

                try {
                    (require database_path('migrations/2026_09_13_000100_create_generic_instrument_result_ledger.php'))->up();
                    $this->fail('Counterfeit result ledger security must be rejected.');
                } catch (RuntimeException $exception) {
                    $this->assertStringContainsString('policy predicate is not exact', $exception->getMessage());
                }
                $this->assertEquals($counterfeit, $this->definitions());
            } finally {
                DB::rollBack();
            }
        });
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
            'result_contract_version' => 'ist-result:v1', 'result_payload' => $payload,
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

    /** @return array<string,mixed> */
    private function definitions(): array
    {
        return [
            'tables' => DB::select("SELECT relname,relrowsecurity,relforcerowsecurity FROM pg_class WHERE relname IN ('generic_instrument_results','generic_instrument_result_sources') ORDER BY relname"),
            'constraints' => DB::select("SELECT conrelid::regclass::text relation,conname,pg_get_constraintdef(oid,false) definition FROM pg_constraint WHERE conrelid IN ('generic_instrument_results'::regclass,'generic_instrument_result_sources'::regclass) ORDER BY relation,conname"),
            'indexes' => DB::select("SELECT indexname,indexdef FROM pg_indexes WHERE schemaname='public' AND (tablename IN ('generic_instrument_results','generic_instrument_result_sources') OR indexname='instrument_versions_result_scope_unique') ORDER BY indexname"),
            'triggers' => DB::select("SELECT tgrelid::regclass::text relation,tgname,pg_get_triggerdef(oid,false) definition FROM pg_trigger WHERE NOT tgisinternal AND tgrelid IN ('generic_instrument_results'::regclass,'generic_instrument_result_sources'::regclass) ORDER BY relation,tgname"),
            'policies' => DB::select("SELECT tablename,policyname,cmd,roles,qual,with_check FROM pg_policies WHERE tablename IN ('generic_instrument_results','generic_instrument_result_sources') ORDER BY tablename,policyname"),
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
