<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;

final class AssessmentCaseBackfillMigrationTest extends OrganizationPaymentTestCase
{
    private object $migration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->migration = require database_path('migrations/2026_09_09_000300_backfill_integrated_assessment_cases.php');
        $this->migration->down();
    }

    public function test_it_backfills_only_unbound_integrated_attempts_and_enforces_the_link(): void
    {
        $old = $this->graph('old');
        $existing = $this->graph('existing');
        $existingCase = DB::table('assessment_cases')->insertGetId($this->caseRow($existing));
        DB::table('assessment_participants')->where('id', $existing['attempt'])
            ->update(['assessment_case_id' => $existingCase]);
        $session = $this->createSession($old['participant']);
        $invitation = DB::table('assessment_invitations')->insertGetId([
            'public_id' => (string) Str::ulid(), 'assessment_participant_id' => $old['attempt'],
            'token_hash' => hash('sha256', 'phase-two-child'), 'active_marker' => true,
            'status' => 'PENDING', 'issue_number' => 1, 'expires_at' => now()->addHour(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->migration->up();
        $this->migration->up();

        $backfilled = DB::table('assessment_cases')->where('public_id', $old['alias'])->first();
        $this->assertNotNull($backfilled);
        $this->assertSame($old['participant'], $backfilled->participant_id);
        $this->assertSame($old['organization'], $backfilled->organization_id);
        $this->assertSame($old['package'], $backfilled->package_id);
        $this->assertSame('INTEGRATED', $backfilled->origin);
        $this->assertNull($backfilled->intended_field_snapshot);
        $this->assertSame($old['alias'], $backfilled->public_id);
        $this->assertSame($backfilled->id, DB::table('assessment_participants')
            ->where('id', $old['attempt'])->value('assessment_case_id'));
        $this->assertSame($existingCase, DB::table('assessment_participants')
            ->where('id', $existing['attempt'])->value('assessment_case_id'));
        $this->assertSame(2, DB::table('assessment_cases')->count());
        $this->assertNull(DB::table('test_sessions')->where('id', $session)->value('assessment_case_id'));
        $attemptColumns = collect(DB::select("PRAGMA table_info('assessment_participants')"))->keyBy('name');
        $this->assertSame(1, (int) $attemptColumns['assessment_case_id']->notnull);
        $attemptIndexes = collect(DB::select("PRAGMA index_list('assessment_participants')"))->pluck('name')->all();
        $this->assertContains('assessment_participants_case_unique', $attemptIndexes);
        $this->assertContains('assessment_participants_client_idempotency_unique', $attemptIndexes);
        $this->assertSame(2, collect(DB::select("PRAGMA foreign_key_list('assessment_participants')"))
            ->filter(fn (object $foreign): bool => $foreign->table === 'assessment_cases')->groupBy('id')->count());
        $this->assertSame($old['attempt'], DB::table('assessment_invitations')
            ->where('id', $invitation)->value('assessment_participant_id'));
        $triggerNames = collect(DB::select(<<<'SQL'
            SELECT name FROM sqlite_master
            WHERE type = 'trigger' AND tbl_name = 'assessment_participants'
            SQL))->pluck('name')->all();
        $this->assertContains('assessment_participants_checkout_funding_insert', $triggerNames);
        $this->assertContains('assessment_participants_checkout_funding_update', $triggerNames);
        $this->assertContains('assessment_participants_case_insert_guard', $triggerNames);
        $this->assertContains('assessment_participants_case_update_guard', $triggerNames);

        $invalidAlias = (string) Str::ulid();
        $invalidCase = DB::table('assessment_cases')->insertGetId([
            ...$this->caseRow([...$old, 'alias' => $invalidAlias]), 'public_id' => $invalidAlias,
        ]);
        try {
            $this->insertAttempt($old, $invalidAlias, 'invalid-funding-up', $invalidCase, null);
            $this->fail('Funding guard must survive the phase-two rebuild.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('assessment_participants_checkout_funding_check', $exception->getMessage());
        }

        $this->assertRejected(fn () => $this->insertAttempt($old, (string) Str::ulid(), 'late'));
        DB::table('test_sessions')->insert([
            'public_id' => (string) Str::ulid(), 'participant_id' => $old['participant'],
            'test_type' => 'papi', 'attempt_no' => 2, 'authorization_id' => (string) Str::ulid(),
            'allocation_intent_id' => (string) Str::ulid(), 'duration_seconds' => 3600,
            'status' => 'created', 'answers_revision' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_public_id_collision_aborts_without_partial_backfill(): void
    {
        $first = $this->graph('first');
        $second = $this->graph('second');
        DB::table('assessment_cases')->insert([
            ...$this->caseRow($second), 'public_id' => $first['alias'],
        ]);

        $this->assertMigrationFailsAtomically(2, 1);
    }

    public function test_bound_scope_mismatch_aborts_without_partial_backfill(): void
    {
        $first = $this->graph('first');
        $second = $this->graph('second');
        $wrongCase = DB::table('assessment_cases')->insertGetId($this->caseRow($second));
        DB::table('assessment_participants')->where('id', $first['attempt'])
            ->update(['assessment_case_id' => $wrongCase]);

        $this->assertMigrationFailsAtomically(2, 1, 1);
    }

    public function test_malformed_alias_fails_before_any_backfill(): void
    {
        $history = $this->graph('malformed');
        DB::table('assessment_participants')->where('id', $history['attempt'])
            ->update(['assessment_attempt_id' => strtolower($history['alias'])]);

        $this->assertMigrationFailsAtomically(1, 0);
    }

    public function test_missing_historical_timestamp_fails_before_any_backfill(): void
    {
        $history = $this->graph('missing-timestamp');
        DB::table('assessment_participants')->where('id', $history['attempt'])->update(['created_at' => null]);

        $this->assertMigrationFailsAtomically(1, 0);
    }

    public function test_late_binding_failure_rolls_back_inserted_case(): void
    {
        $this->graph('late-failure');
        $beforeTriggers = DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'trigger' AND tbl_name = 'assessment_participants' ORDER BY name");
        $beforeForeignKeys = DB::select("PRAGMA foreign_key_list('assessment_participants')");
        $beforeIndexes = DB::select("PRAGMA index_list('assessment_participants')");
        DB::statement('CREATE TABLE synthetic_phase_two_collision (id INTEGER PRIMARY KEY)');
        DB::statement('CREATE UNIQUE INDEX assessment_cases_integrated_scope_unique ON synthetic_phase_two_collision (id)');

        $this->assertMigrationFailsAtomically(1, 0);
        $this->assertEquals($beforeTriggers, DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'trigger' AND tbl_name = 'assessment_participants' ORDER BY name"));
        $this->assertEquals($beforeForeignKeys, DB::select("PRAGMA foreign_key_list('assessment_participants')"));
        $this->assertEquals($beforeIndexes, DB::select("PRAGMA index_list('assessment_participants')"));
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    #[DataProvider('sqliteEnforcementCorruptions')]
    public function test_rerun_rejects_one_wrong_sqlite_enforcement_component(string $component): void
    {
        $this->migration->up();
        $this->corruptSqliteEnforcement($component);
        $before = $this->sqliteEnforcementDefinitions();

        try {
            $this->migration->up();
            $this->fail("Wrong SQLite {$component} enforcement was accepted.");
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('partial SQLite enforcement', $exception->getMessage());
        }
        $this->assertEquals($before, $this->sqliteEnforcementDefinitions());
        $this->assertSame(0, DB::table('assessment_cases')->count());
        $this->assertSame(0, DB::table('assessment_participants')->count());
    }

    public static function sqliteEnforcementCorruptions(): iterable
    {
        yield 'unique columns' => ['unique_columns'];
        yield 'unique flag' => ['unique_flag'];
        yield 'foreign source and target order' => ['foreign_order'];
        yield 'foreign delete action' => ['foreign_delete'];
        yield 'insert guard body' => ['insert_guard'];
        yield 'update guard body' => ['update_guard'];
    }

    private function corruptSqliteEnforcement(string $component): void
    {
        if (str_starts_with($component, 'unique_')) {
            DB::statement('DROP INDEX assessment_cases_integrated_scope_unique');
            $columns = $component === 'unique_columns'
                ? 'id, public_id, organization_id, participant_id, package_id'
                : 'id, public_id, participant_id, organization_id, package_id';
            $unique = $component === 'unique_columns' ? 'UNIQUE ' : '';
            DB::statement("CREATE {$unique}INDEX assessment_cases_integrated_scope_unique ON assessment_cases ({$columns})");

            return;
        }

        if (str_starts_with($component, 'foreign_')) {
            $triggers = collect(DB::select("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND tbl_name = 'assessment_participants' AND sql IS NOT NULL"))
                ->pluck('sql')->all();
            Schema::table('assessment_participants', function (Blueprint $table) use ($component): void {
                $table->dropForeign([
                    'assessment_case_id', 'assessment_attempt_id', 'participant_id', 'organization_id', 'package_id',
                ]);
                $from = $component === 'foreign_order'
                    ? ['assessment_case_id', 'assessment_attempt_id', 'organization_id', 'participant_id', 'package_id']
                    : ['assessment_case_id', 'assessment_attempt_id', 'participant_id', 'organization_id', 'package_id'];
                $to = $component === 'foreign_order'
                    ? ['id', 'public_id', 'organization_id', 'participant_id', 'package_id']
                    : ['id', 'public_id', 'participant_id', 'organization_id', 'package_id'];
                $foreign = $table->foreign($from)->references($to)->on('assessment_cases');
                $component === 'foreign_delete' ? $foreign->cascadeOnDelete() : $foreign->restrictOnDelete();
            });
            foreach ($triggers as $trigger) {
                DB::unprepared($trigger);
            }

            return;
        }

        $name = $component === 'insert_guard'
            ? 'assessment_participants_case_insert_guard'
            : 'assessment_participants_case_update_guard';
        $event = $component === 'insert_guard' ? 'INSERT' : 'UPDATE';
        DB::unprepared("DROP TRIGGER {$name}");
        DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON assessment_participants FOR EACH ROW BEGIN SELECT 1; END;");
    }

    /** @return array<string,mixed> */
    private function sqliteEnforcementDefinitions(): array
    {
        return [
            'columns' => DB::select("PRAGMA table_info('assessment_participants')"),
            'case_indexes' => DB::select("PRAGMA index_list('assessment_cases')"),
            'case_scope_columns' => DB::select("PRAGMA index_info('assessment_cases_integrated_scope_unique')"),
            'attempt_foreign_keys' => DB::select("PRAGMA foreign_key_list('assessment_participants')"),
            'attempt_triggers' => DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'trigger' AND tbl_name = 'assessment_participants' ORDER BY name"),
        ];
    }

    public function test_down_refuses_populated_history(): void
    {
        $graph = $this->graph('populated');
        $this->migration->up();

        try {
            $this->migration->down();
            $this->fail('Populated rollback must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Integrated assessment case history prevents rollback.', $exception->getMessage());
        }

    }

    public function test_down_removes_empty_sqlite_enforcement(): void
    {
        $graph = $this->graph('temporary');
        DB::table('assessment_participants')->where('id', $graph['attempt'])->delete();
        $this->migration->up();
        $this->migration->down();

        $nullableAlias = (string) Str::ulid();
        $this->insertAttempt($graph, $nullableAlias, 'nullable-again');
        $this->assertNull(DB::table('assessment_participants')->latest('id')->value('assessment_case_id'));
        $this->migration->up();
        $invalidAlias = (string) Str::ulid();
        $case = DB::table('assessment_cases')->insertGetId([
            ...$this->caseRow([...$graph, 'alias' => $invalidAlias]), 'public_id' => $invalidAlias,
        ]);
        try {
            $this->insertAttempt($graph, $invalidAlias, 'invalid-funding', $case, null);
            $this->fail('Funding guard must survive the empty down/up roundtrip.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('assessment_participants_checkout_funding_check', $exception->getMessage());
        }
    }

    private function assertMigrationFailsAtomically(int $attempts, int $cases, int $bound = 0): void
    {
        try {
            $this->migration->up();
            $this->fail('Malformed history must abort migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringStartsWith('Integrated assessment case backfill aborted:', $exception->getMessage());
        }

        $this->assertSame($attempts, DB::table('assessment_participants')->count());
        $this->assertSame($cases, DB::table('assessment_cases')->count());
        $this->assertSame($bound, DB::table('assessment_participants')->whereNotNull('assessment_case_id')->count());
    }

    /** @return array{organization:int,participant:int,package:int,client:int,attempt:int,alias:string} */
    private function graph(string $suffix): array
    {
        $alias = (string) Str::ulid();
        $organization = DB::table('branches')->insertGetId([
            'code' => $alias, 'ref_code' => $alias, 'name' => 'Synthetic',
            'organization_code' => $alias, 'display_name' => 'Synthetic',
        ]);
        $package = DB::table('packages')->insertGetId([
            'code' => 'PKG-'.$alias, 'name' => 'Synthetic', 'amount' => 0,
            'currency' => 'IDR', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $organization, 'referral_branch_id' => $organization,
            'referral_source' => 'default', 'package_id' => $package,
            'source_system' => 'DIRECT_PUBLIC', 'full_name' => 'Synthetic', 'phone' => '620000000000',
        ]);
        $client = DB::table('integration_clients')->insertGetId([
            'organization_id' => $organization, 'client_id' => 'client-'.$alias,
            'credential_reference' => 'synthetic', 'enabled' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $attempt = $this->insertAttempt(compact('organization', 'participant', 'package', 'client'), $alias, $suffix);

        return compact('organization', 'participant', 'package', 'client', 'attempt', 'alias');
    }

    /** @param array{organization:int,participant:int,package:int,client:int} $graph */
    private function insertAttempt(
        array $graph,
        string $alias,
        string $suffix,
        ?int $case = null,
        ?string $fundingMode = 'SPONSORED',
    ): int {
        return DB::table('assessment_participants')->insertGetId([
            'integration_client_id' => $graph['client'], 'organization_id' => $graph['organization'],
            'participant_id' => $graph['participant'], 'package_id' => $graph['package'],
            'assessment_case_id' => $case, 'assessment_attempt_id' => $alias, 'source_system' => 'SYNTHETIC',
            'external_candidate_id' => 'candidate-'.$suffix, 'funding_mode' => $fundingMode,
            'assessment_status' => 'READY', 'result_version' => 0,
            'idempotency_key' => 'key-'.$suffix, 'request_hash' => hash('sha256', $suffix),
            'logical_assessment_key' => hash('sha256', 'logical-'.$suffix),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param array{organization:int,participant:int,package:int,alias:string} $graph
     * @return array<string, mixed>
     */
    private function caseRow(array $graph): array
    {
        return [
            'public_id' => $graph['alias'], 'participant_id' => $graph['participant'],
            'organization_id' => $graph['organization'], 'package_id' => $graph['package'],
            'origin' => 'INTEGRATED', 'intended_field_snapshot' => null,
            'created_at' => now(), 'updated_at' => now(),
        ];
    }

    private function createSession(int $participant): int
    {
        return DB::table('test_sessions')->insertGetId([
            'public_id' => (string) Str::ulid(), 'participant_id' => $participant,
            'test_type' => 'ist', 'attempt_no' => 1, 'authorization_id' => (string) Str::ulid(),
            'allocation_intent_id' => (string) Str::ulid(), 'duration_seconds' => 3600,
            'status' => 'created', 'answers_revision' => 0, 'created_at' => now(), 'updated_at' => now(),
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
