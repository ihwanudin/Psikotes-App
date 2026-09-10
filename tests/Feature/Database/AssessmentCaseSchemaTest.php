<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;

final class AssessmentCaseSchemaTest extends OrganizationPaymentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $grants = require database_path('migrations/2026_09_09_000700_create_test_session_grants.php');
        $grants->down();
        $directCases = require database_path('migrations/2026_09_09_000600_bind_direct_public_orders_to_assessment_cases.php');
        $directCases->down();
        $selectionCases = require database_path('migrations/2026_09_09_000500_bind_legacy_selection_assessment_cases.php');
        $selectionCases->down();
        $sessionCases = require database_path('migrations/2026_09_09_000400_harden_test_session_case_identity.php');
        $sessionCases->down();
        $phaseTwo = require database_path('migrations/2026_09_09_000300_backfill_integrated_assessment_cases.php');
        $phaseTwo->down();
    }

    public function test_sqlite_schema_exposes_universal_case_and_nullable_phase_one_links(): void
    {
        $this->assertTrue(Schema::hasColumns('assessment_cases', [
            'id', 'public_id', 'participant_id', 'organization_id', 'package_id',
            'origin', 'intended_field_snapshot', 'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumn('assessment_participants', 'assessment_case_id'));
        $this->assertTrue(Schema::hasColumn('test_sessions', 'assessment_case_id'));

        $columns = collect(DB::select("PRAGMA table_info('assessment_cases')"))->keyBy('name');
        $this->assertSame(1, (int) $columns['id']->pk);
        $this->assertSame(1, (int) $columns['participant_id']->notnull);
        $this->assertSame(1, (int) $columns['organization_id']->notnull);
        $this->assertSame(0, (int) $columns['package_id']->notnull);
        $this->assertSame(0, (int) $columns['intended_field_snapshot']->notnull);

        $caseForeignKeys = collect(DB::select("PRAGMA foreign_key_list('assessment_cases')"));
        foreach ([
            'participant_id' => 'participants',
            'organization_id' => 'branches',
            'package_id' => 'packages',
        ] as $column => $table) {
            $foreign = $caseForeignKeys->first(function (object $candidate) use ($column, $table): bool {
                $values = (array) $candidate;

                return ($values['from'] ?? null) === $column && ($values['table'] ?? null) === $table;
            });
            $this->assertNotNull($foreign, $column);
            $this->assertSame('RESTRICT', ((array) $foreign)['on_delete'] ?? null, $column);
        }

        foreach (['assessment_participants', 'test_sessions'] as $table) {
            $foreign = collect(DB::select("PRAGMA foreign_key_list('{$table}')"))->first(function (object $candidate): bool {
                $values = (array) $candidate;

                return ($values['from'] ?? null) === 'assessment_case_id'
                    && ($values['table'] ?? null) === 'assessment_cases';
            });
            $this->assertNotNull($foreign, $table);
            $this->assertSame('RESTRICT', ((array) $foreign)['on_delete'] ?? null, $table);
        }

        $caseIndexes = collect(DB::select("PRAGMA index_list('assessment_cases')"));
        foreach ([
            'assessment_cases_public_id_unique',
            'assessment_cases_participant_idx',
            'assessment_cases_organization_idx',
            'assessment_cases_package_idx',
        ] as $name) {
            $this->assertNotNull($caseIndexes->firstWhere('name', $name), $name);
        }
        $attemptIndex = collect(DB::select("PRAGMA index_list('assessment_participants')"))
            ->firstWhere('name', 'assessment_participants_case_unique');
        $this->assertNotNull($attemptIndex);
        $this->assertSame(1, (int) $attemptIndex->unique);
        $this->assertNotNull(collect(DB::select("PRAGMA index_list('test_sessions')"))
            ->firstWhere('name', 'test_sessions_assessment_case_idx'));
    }

    public function test_sqlite_contract_rejects_invalid_identity_and_allows_each_optional_binding_once(): void
    {
        $graph = $this->graph();
        $case = $this->caseRow($graph);
        $caseId = DB::table('assessment_cases')->insertGetId($case);
        $otherOrganization = $this->createBranch();

        foreach ([
            ['public_id' => strtolower((string) Str::ulid())],
            ['public_id' => '8'.substr((string) Str::ulid(), 1)],
            ['origin' => 'SELF_PAY'],
            ['participant_id' => 999999],
            ['organization_id' => 999999],
            ['organization_id' => $otherOrganization],
            ['package_id' => 999999],
            ['intended_field_snapshot' => ''],
            ['intended_field_snapshot' => 'UNKNOWN'],
            ['created_at' => null],
            ['updated_at' => null],
        ] as $override) {
            $this->assertRejected(fn () => DB::table('assessment_cases')->insert([
                ...$this->caseRow($graph),
                'public_id' => (string) Str::ulid(),
                ...$override,
            ]));
        }

        DB::table('assessment_cases')->where('id', $caseId)->update([
            'package_id' => $graph['package'],
            'intended_field_snapshot' => 'KAIGO',
            'updated_at' => '2026-09-09 01:00:01+00',
        ]);
        $this->assertRejected(fn () => DB::table('assessment_cases')->where('id', $caseId)->update([
            'package_id' => null,
            'updated_at' => '2026-09-09 01:00:02+00',
        ]));
        $this->assertRejected(fn () => DB::table('assessment_cases')->where('id', $caseId)->update([
            'intended_field_snapshot' => 'UMUM',
            'updated_at' => '2026-09-09 01:00:02+00',
        ]));
        $this->assertRejected(fn () => DB::table('assessment_cases')->where('id', $caseId)->update([
            'origin' => 'INTEGRATED',
            'updated_at' => '2026-09-09 01:00:02+00',
        ]));
        $this->assertRejected(fn () => DB::table('assessment_cases')->where('id', $caseId)->update([
            'updated_at' => '2026-09-09 01:00:01+00',
        ]));
        $this->assertRejected(fn () => DB::table('assessment_cases')->where('id', $caseId)->delete());
    }

    public function test_sqlite_phase_one_links_are_nullable_referential_and_attempt_link_is_one_to_one(): void
    {
        $graph = $this->graph();
        $firstCase = DB::table('assessment_cases')->insertGetId($this->caseRow($graph));
        $secondCase = DB::table('assessment_cases')->insertGetId([
            ...$this->caseRow($graph), 'public_id' => (string) Str::ulid(),
        ]);
        $attempt = $this->assessmentParticipant($graph);
        $session = $this->createTestSession($graph['participant']);

        $this->assertNull(DB::table('assessment_participants')->where('id', $attempt)->value('assessment_case_id'));
        $this->assertNull(DB::table('test_sessions')->where('id', $session)->value('assessment_case_id'));
        DB::table('assessment_participants')->where('id', $attempt)->update(['assessment_case_id' => $firstCase]);
        DB::table('test_sessions')->where('id', $session)->update(['assessment_case_id' => $firstCase]);

        $secondAttempt = $this->assessmentParticipant($graph, 'candidate-2');
        $this->assertRejected(fn () => DB::table('assessment_participants')->where('id', $secondAttempt)
            ->update(['assessment_case_id' => $firstCase]));
        $this->assertRejected(fn () => DB::table('assessment_participants')->where('id', $secondAttempt)
            ->update(['assessment_case_id' => 999999]));
        $secondSession = $this->createTestSession($graph['participant'], 'papi');
        $this->assertRejected(fn () => DB::table('test_sessions')->where('id', $secondSession)
            ->update(['assessment_case_id' => 999999]));
        DB::table('assessment_participants')->where('id', $secondAttempt)->update(['assessment_case_id' => $secondCase]);
    }

    public function test_down_refuses_history_and_empty_roundtrip_preserves_parent_tables(): void
    {
        $migration = require database_path('migrations/2026_09_09_000200_create_assessment_cases.php');
        $graph = $this->graph();
        DB::beginTransaction();
        try {
            DB::table('assessment_cases')->insert($this->caseRow($graph));
            try {
                $migration->down();
                $this->fail('Populated assessment case rollback must be refused.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Assessment case history or bindings prevent rollback.', $exception->getMessage());
            }
            $this->assertTrue(Schema::hasTable('assessment_cases'));
        } finally {
            DB::rollBack();
        }

        $migration->down();
        $this->assertFalse(Schema::hasTable('assessment_cases'));
        $this->assertFalse(Schema::hasColumn('assessment_participants', 'assessment_case_id'));
        $this->assertFalse(Schema::hasColumn('test_sessions', 'assessment_case_id'));
        $this->assertTrue(Schema::hasTable('assessment_participants'));
        $this->assertTrue(Schema::hasTable('test_sessions'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('assessment_cases'));
    }

    public function test_successful_down_up_preserves_parent_rows_schema_triggers_and_sequences(): void
    {
        $migration = require database_path('migrations/2026_09_09_000200_create_assessment_cases.php');
        $graph = $this->graph();
        $attempt = $this->assessmentParticipant($graph);
        $session = $this->createTestSession($graph['participant']);
        DB::statement('CREATE INDEX assessment_participants_preservation_probe_idx ON assessment_participants (assessment_status, id)');
        DB::statement('CREATE INDEX test_sessions_preservation_probe_idx ON test_sessions (status, id)');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER assessment_participants_preservation_probe
            BEFORE UPDATE ON assessment_participants WHEN NEW.id IS NOT OLD.id
            BEGIN SELECT RAISE(ABORT, 'assessment participant id is immutable'); END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER test_sessions_preservation_probe
            BEFORE UPDATE ON test_sessions WHEN NEW.id IS NOT OLD.id
            BEGIN SELECT RAISE(ABORT, 'test session id is immutable'); END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER assessment_case_cross_table_preservation_probe
            AFTER UPDATE ON branches
            BEGIN
                SELECT count(*) FROM assessment_participants WHERE organization_id = NEW.id;
                SELECT count(*) FROM test_sessions
                    WHERE participant_id IN (SELECT id FROM participants WHERE branch_id = NEW.id);
            END
            SQL);

        $attemptRow = (array) DB::table('assessment_participants')->find($attempt);
        $sessionRow = (array) DB::table('test_sessions')->find($session);
        $preservedSchema = $this->parentSchemaWithoutCaseLinks();
        $dependentTriggers = $this->preservationProbeTriggers();
        $sequences = $this->parentSequences();
        $this->assertSame($attempt, $sequences['assessment_participants'] ?? null);
        $this->assertSame($session, $sequences['test_sessions'] ?? null);

        $migration->down();

        unset($attemptRow['assessment_case_id'], $sessionRow['assessment_case_id']);
        $this->assertEquals($attemptRow, (array) DB::table('assessment_participants')->find($attempt));
        $this->assertEquals($sessionRow, (array) DB::table('test_sessions')->find($session));
        $this->assertSame($preservedSchema, $this->parentSchemaWithoutCaseLinks());
        $this->assertSame($dependentTriggers, $this->preservationProbeTriggers());
        $this->assertSame($sequences, $this->parentSequences());
        $this->assertDatabaseMissing('sqlite_sequence', ['name' => 'assessment_participants_without_assessment_case']);
        $this->assertDatabaseMissing('sqlite_sequence', ['name' => 'test_sessions_without_assessment_case']);
        $this->assertFalse(Schema::hasTable('assessment_participants_without_assessment_case'));
        $this->assertFalse(Schema::hasTable('test_sessions_without_assessment_case'));
        $this->assertCaseSchemaPresent(false);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->assertSame(1, (int) DB::scalar('PRAGMA foreign_keys'));

        $migration->up();

        $this->assertEquals([...$attemptRow, 'assessment_case_id' => null], (array) DB::table('assessment_participants')->find($attempt));
        $this->assertEquals([...$sessionRow, 'assessment_case_id' => null], (array) DB::table('test_sessions')->find($session));
        $this->assertSame($preservedSchema, $this->parentSchemaWithoutCaseLinks());
        $this->assertSame($dependentTriggers, $this->preservationProbeTriggers());
        $this->assertSame($sequences, $this->parentSequences());
        $this->assertDatabaseMissing('sqlite_sequence', ['name' => 'assessment_participants_without_assessment_case']);
        $this->assertDatabaseMissing('sqlite_sequence', ['name' => 'test_sessions_without_assessment_case']);
        $this->assertCaseSchemaPresent(true);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->assertSame(1, (int) DB::scalar('PRAGMA foreign_keys'));
    }

    /** @return array{branch:int,package:int,participant:int,client:int} */
    private function graph(): array
    {
        $key = (string) Str::ulid();
        $branch = $this->createBranch($key);
        $package = DB::table('packages')->insertGetId([
            'code' => 'PKG-'.$key, 'name' => 'Synthetic', 'description' => null,
            'amount' => 0, 'currency' => 'IDR', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $branch, 'referral_branch_id' => $branch, 'referral_source' => 'default',
            'package_id' => $package, 'source_system' => 'DIRECT_PUBLIC',
            'full_name' => 'Synthetic', 'phone' => '620000000000',
        ]);
        $client = DB::table('integration_clients')->insertGetId([
            'organization_id' => $branch, 'client_id' => 'client-'.$key,
            'credential_reference' => 'synthetic', 'enabled' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return compact('branch', 'package', 'participant', 'client');
    }

    private function createBranch(?string $key = null): int
    {
        $key ??= (string) Str::ulid();

        return DB::table('branches')->insertGetId([
            'code' => $key, 'name' => 'Synthetic', 'ref_code' => $key,
            'organization_code' => $key, 'display_name' => 'Synthetic',
        ]);
    }

    /** @param array{branch:int,package:int,participant:int,client:int} $graph
     * @return array<string,mixed>
     */
    private function caseRow(array $graph): array
    {
        return [
            'public_id' => (string) Str::ulid(), 'participant_id' => $graph['participant'],
            'organization_id' => $graph['branch'], 'package_id' => null,
            'origin' => 'DIRECT_PUBLIC', 'intended_field_snapshot' => null,
            'created_at' => '2026-09-09 01:00:00+00', 'updated_at' => '2026-09-09 01:00:00+00',
        ];
    }

    /** @param array{branch:int,package:int,participant:int,client:int} $graph */
    private function assessmentParticipant(array $graph, string $candidate = 'candidate-1'): int
    {
        return DB::table('assessment_participants')->insertGetId([
            'integration_client_id' => $graph['client'], 'organization_id' => $graph['branch'],
            'participant_id' => $graph['participant'], 'package_id' => $graph['package'],
            'assessment_attempt_id' => (string) Str::ulid(), 'source_system' => 'SYNTHETIC',
            'external_candidate_id' => $candidate, 'funding_mode' => 'SPONSORED',
            'assessment_status' => 'READY', 'result_version' => 0,
            'idempotency_key' => 'key-'.$candidate, 'request_hash' => hash('sha256', $candidate),
            'logical_assessment_key' => hash('sha256', 'logical-'.$candidate),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function createTestSession(int $participant, string $testType = 'ist'): int
    {
        return DB::table('test_sessions')->insertGetId([
            'public_id' => (string) Str::ulid(), 'participant_id' => $participant,
            'test_type' => $testType, 'attempt_no' => random_int(1, 1000000),
            'authorization_id' => (string) Str::ulid(), 'allocation_intent_id' => (string) Str::ulid(),
            'duration_seconds' => 3600, 'status' => 'created', 'answers_revision' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string, array<string, mixed>> */
    private function parentSchemaWithoutCaseLinks(): array
    {
        $schema = [];
        foreach (['assessment_participants', 'test_sessions'] as $table) {
            $columns = collect(DB::select("PRAGMA table_info('{$table}')"))
                ->reject(fn (object $column): bool => $column->name === 'assessment_case_id')
                ->map(fn (object $column): array => [
                    'name' => (string) $column->name,
                    'type' => (string) $column->type,
                    'notnull' => (int) $column->notnull,
                    'default' => $column->dflt_value,
                    'primary' => (int) $column->pk,
                ])->sortBy('name')->values()->all();
            $indexes = collect(DB::select(
                "SELECT name, sql FROM sqlite_master WHERE type = 'index' AND tbl_name = ? ORDER BY name",
                [$table],
            ))->reject(fn (object $index): bool => str_contains(strtolower((string) $index->sql), 'assessment_case_id'))
                ->map(fn (object $index): array => ['name' => (string) $index->name, 'sql' => $index->sql])
                ->values()->all();
            $foreignKeys = collect(DB::select("PRAGMA foreign_key_list('{$table}')"))
                ->reject(fn (object $foreign): bool => $foreign->from === 'assessment_case_id')
                ->map(fn (object $foreign): array => [
                    'sequence' => (int) $foreign->seq,
                    'table' => (string) $foreign->table,
                    'from' => (string) $foreign->from,
                    'to' => (string) $foreign->to,
                    'on_update' => (string) $foreign->on_update,
                    'on_delete' => (string) $foreign->on_delete,
                    'match' => (string) $foreign->match,
                ])->sortBy(['table', 'sequence', 'from'])->values()->all();
            $triggers = collect(DB::select(
                "SELECT name, sql FROM sqlite_master WHERE type = 'trigger' AND tbl_name = ? ORDER BY name",
                [$table],
            ))->map(fn (object $trigger): array => ['name' => (string) $trigger->name, 'sql' => (string) $trigger->sql])
                ->values()->all();
            $schema[$table] = compact('columns', 'indexes', 'foreignKeys', 'triggers');
        }

        return $schema;
    }

    /** @return array<string, int> */
    private function parentSequences(): array
    {
        return collect(DB::select(<<<'SQL'
            SELECT name, seq FROM sqlite_sequence
            WHERE name IN ('assessment_participants', 'test_sessions')
            ORDER BY name
            SQL))->mapWithKeys(fn (object $sequence): array => [
            (string) $sequence->name => (int) $sequence->seq,
        ])->all();
    }

    /** @return array<string, string> */
    private function preservationProbeTriggers(): array
    {
        return collect(DB::select(<<<'SQL'
            SELECT name, sql FROM sqlite_master
            WHERE type = 'trigger' AND name LIKE '%preservation_probe'
            ORDER BY name
            SQL))->mapWithKeys(fn (object $trigger): array => [
            (string) $trigger->name => (string) $trigger->sql,
        ])->all();
    }

    private function assertCaseSchemaPresent(bool $present): void
    {
        $this->assertSame($present, Schema::hasTable('assessment_cases'));
        foreach (['assessment_participants', 'test_sessions'] as $table) {
            $this->assertSame($present, Schema::hasColumn($table, 'assessment_case_id'));
            $foreign = collect(DB::select("PRAGMA foreign_key_list('{$table}')"))
                ->contains(fn (object $candidate): bool => $candidate->from === 'assessment_case_id'
                    && $candidate->table === 'assessment_cases');
            $this->assertSame($present, $foreign);
        }
        foreach ([
            'assessment_participants' => 'assessment_participants_case_unique',
            'test_sessions' => 'test_sessions_assessment_case_idx',
        ] as $table => $index) {
            $exists = collect(DB::select("PRAGMA index_list('{$table}')"))->contains('name', $index);
            $this->assertSame($present, $exists);
        }
    }

    private function assertRejected(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected database rejection.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
