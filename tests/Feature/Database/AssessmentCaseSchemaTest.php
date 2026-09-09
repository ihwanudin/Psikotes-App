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
        foreach (['assessment_cases_public_id_unique', 'assessment_cases_participant_idx', 'assessment_cases_organization_idx'] as $name) {
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

        foreach ([
            ['public_id' => strtolower((string) Str::ulid())],
            ['public_id' => '8'.substr((string) Str::ulid(), 1)],
            ['origin' => 'SELF_PAY'],
            ['participant_id' => 999999],
            ['organization_id' => 999999],
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

    /** @return array{branch:int,package:int,participant:int,client:int} */
    private function graph(): array
    {
        $key = (string) Str::ulid();
        $branch = DB::table('branches')->insertGetId([
            'code' => $key, 'name' => 'Synthetic', 'ref_code' => $key,
            'organization_code' => $key, 'display_name' => 'Synthetic',
        ]);
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
