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

final class TestSessionCaseIdentityMigrationTest extends OrganizationPaymentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->migrate('down');
    }

    public function test_it_backfills_only_unique_candidates_and_keeps_zero_candidate_nullable(): void
    {
        $unique = $this->graph('unique');
        $uniqueCase = $this->createCase($unique);
        $uniqueSession = $this->createSession($unique['participant']);
        $zero = $this->graph('zero');
        $zeroSession = $this->createSession($zero['participant']);

        $this->migrate('up');
        $this->migrate('up');

        $this->assertSame($uniqueCase, DB::table('test_sessions')->where('id', $uniqueSession)->value('assessment_case_id'));
        $this->assertNull(DB::table('test_sessions')->where('id', $zeroSession)->value('assessment_case_id'));
        $columns = collect(DB::select("PRAGMA table_info('test_sessions')"))->keyBy('name');
        $this->assertSame(0, (int) $columns['assessment_case_id']->notnull);

        $foreign = collect(DB::select("PRAGMA foreign_key_list('test_sessions')"))
            ->groupBy('id')->first(fn ($rows): bool => $rows->count() === 2 && $rows->first()->table === 'assessment_cases');
        $this->assertNotNull($foreign);
        $this->assertSame(['assessment_case_id', 'participant_id'], $foreign->sortBy('seq')->pluck('from')->all());
        $this->assertSame(['id', 'participant_id'], $foreign->sortBy('seq')->pluck('to')->all());
    }

    public function test_ambiguous_or_corrupt_history_aborts_without_partial_backfill(): void
    {
        $unique = $this->graph('unique');
        $this->createCase($unique);
        $session = $this->createSession($unique['participant']);
        $ambiguous = $this->graph('ambiguous');
        $this->createCase($ambiguous);
        $this->createCase($ambiguous);
        $this->createSession($ambiguous['participant']);

        try {
            $this->migrate('up');
            $this->fail('Ambiguous history must abort.');
        } catch (RuntimeException $exception) {
            $this->assertStringStartsWith('Test session case backfill aborted:', $exception->getMessage());
        }

        $this->assertNull(DB::table('test_sessions')->where('id', $session)->value('assessment_case_id'));
    }

    #[DataProvider('invalidBoundHistory')]
    public function test_missing_or_cross_participant_bound_history_aborts_atomically(string $scenario): void
    {
        $unique = $this->graph('unique-'.$scenario);
        $this->createCase($unique);
        $uniqueSession = $this->createSession($unique['participant']);
        $invalid = $this->graph('invalid-'.$scenario);
        $invalidSession = $this->createSession($invalid['participant']);
        if ($scenario === 'mismatch') {
            $other = $this->graph('other-'.$scenario);
            $wrong = $this->createCase($other);
            DB::table('test_sessions')->where('id', $invalidSession)->update(['assessment_case_id' => $wrong]);
        } else {
            DB::statement('PRAGMA foreign_keys=OFF');
            DB::table('test_sessions')->where('id', $invalidSession)->update(['assessment_case_id' => 999999]);
            DB::statement('PRAGMA foreign_keys=ON');
        }

        try {
            $this->migrate('up');
            $this->fail('Invalid bound history must abort.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('existing binding', $exception->getMessage());
        }
        $this->assertNull(DB::table('test_sessions')->where('id', $uniqueSession)->value('assessment_case_id'));
    }

    /** @return iterable<string,array{string}> */
    public static function invalidBoundHistory(): iterable
    {
        yield 'missing case' => ['missing'];
        yield 'cross participant case' => ['mismatch'];
    }

    public function test_guards_exact_parent_and_immutable_case_and_created_at_but_allow_lifecycle_updates(): void
    {
        $first = $this->graph('first');
        $firstCase = $this->createCase($first);
        $session = $this->createSession($first['participant']);
        $this->migrate('up');

        $started = now();
        DB::table('test_sessions')->where('id', $session)->update([
            'status' => 'in_progress', 'started_at' => $started, 'ends_at' => $started->copy()->addHour(),
        ]);
        $this->assertSame('in_progress', DB::table('test_sessions')->where('id', $session)->value('status'));
        $this->assertRejected(fn () => DB::table('test_sessions')->where('id', $session)
            ->update(['assessment_case_id' => null]));
        $this->assertRejected(fn () => DB::table('test_sessions')->where('id', $session)
            ->update(['created_at' => now()->addSecond()]));

        $other = $this->graph('other');
        $otherCase = $this->createCase($other);
        $this->assertRejected(fn () => DB::table('test_sessions')->insert([
            ...$this->sessionRow($first['participant'], 2, 'papi'), 'assessment_case_id' => $otherCase,
        ]));
        DB::table('test_sessions')->insert([
            ...$this->sessionRow($first['participant'], 2, 'papi'), 'assessment_case_id' => $firstCase,
        ]);
        $this->assertRejected(fn () => DB::table('test_sessions')->insert($this->sessionRow($other['participant'])));
        $zero = $this->graph('zero');
        DB::table('test_sessions')->insert($this->sessionRow($zero['participant']));
    }

    public function test_case_scoped_uniques_apply_only_to_bound_rows_and_old_uniques_remain(): void
    {
        $graph = $this->graph('indexes');
        $case = $this->createCase($graph);
        $this->migrate('up');

        $base = $this->sessionRow($graph['participant']);
        DB::table('test_sessions')->insert([...$base, 'assessment_case_id' => $case]);
        foreach (['attempt_no', 'authorization_id', 'allocation_intent_id'] as $field) {
            $row = $this->sessionRow($graph['participant']);
            $row[$field] = $base[$field];
            $row['assessment_case_id'] = $case;
            $this->assertRejected(fn () => DB::table('test_sessions')->insert($row));
        }

        $indexes = collect(DB::select("PRAGMA index_list('test_sessions')"))->pluck('name')->all();
        foreach ([
            'test_sessions_attempt_unique', 'test_sessions_authorization_unique',
            'test_sessions_allocation_intent_unique', 'test_sessions_case_attempt_unique',
            'test_sessions_case_authorization_unique', 'test_sessions_case_allocation_intent_unique',
            'test_sessions_case_one_active_unique',
        ] as $name) {
            $this->assertContains($name, $indexes);
        }
    }

    public function test_populated_down_refuses_without_delta(): void
    {
        $graph = $this->graph('populated');
        $case = $this->createCase($graph);
        $session = $this->createSession($graph['participant']);
        $this->migrate('up');
        $before = $this->definitions();

        try {
            $this->migrate('down');
            $this->fail('Populated history must refuse rollback.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Test session case history prevents rollback.', $exception->getMessage());
        }
        $this->assertSame($case, DB::table('test_sessions')->where('id', $session)->value('assessment_case_id'));
        $this->assertEquals($before, $this->definitions());

    }

    public function test_empty_down_up_roundtrip_preserves_cross_table_triggers(): void
    {
        $before = collect(DB::select("SELECT name,sql FROM sqlite_master WHERE type='trigger' AND sql IS NOT NULL AND lower(sql) LIKE '%test_sessions%' ORDER BY name"))
            ->pluck('sql', 'name')->all();
        $this->migrate('up');
        $this->migrate('down');
        $this->migrate('up');
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $triggers = collect(DB::select("SELECT name,sql FROM sqlite_master WHERE type='trigger'"))->pluck('sql', 'name')->all();
        foreach (['answers_parent_contract_insert', 'answers_parent_contract_update', 'test_sessions_identity_revision_guard'] as $name) {
            $this->assertArrayHasKey($name, $triggers);
            $this->assertSame($before[$name], $triggers[$name]);
        }
        $graph = $this->graph('answer-parent-guard');
        $session = $this->createSession($graph['participant']);
        $this->assertRejected(fn () => DB::table('answers')->insert([
            'session_id' => $session, 'item_no' => 1, 'value' => json_encode(1),
            'revision' => 1, 'answered_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    #[DataProvider('sqliteCorruptions')]
    public function test_rerun_rejects_each_wrong_sqlite_definition_without_delta(string $component): void
    {
        $this->migrate('up');
        $this->corruptSqlite($component);
        $before = $this->definitions();
        try {
            $this->migrate('up');
            $this->fail("Wrong {$component} definition must be rejected.");
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('partial SQLite enforcement', $exception->getMessage());
        }
        $this->assertEquals($before, $this->definitions());
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    /** @return iterable<string,array{string}> */
    public static function sqliteCorruptions(): iterable
    {
        yield 'parent unique columns' => ['parent_unique'];
        yield 'foreign source columns' => ['foreign_source'];
        yield 'foreign referenced columns' => ['foreign_reference'];
        yield 'foreign delete action' => ['foreign_delete'];
        yield 'case index columns' => ['index_columns'];
        yield 'case index predicate' => ['index_predicate'];
        yield 'active case index predicate' => ['active_index_predicate'];
        yield 'insert guard body' => ['insert_guard'];
        yield 'update guard body' => ['update_guard'];
    }

    /** @return array{branch:int,package:int,participant:int} */
    private function graph(string $suffix): array
    {
        $key = (string) Str::ulid();
        $branch = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => $suffix,
            'organization_code' => $key, 'display_name' => $suffix,
        ]);
        $package = DB::table('packages')->insertGetId([
            'code' => 'PKG-'.$key, 'name' => $suffix, 'amount' => 0, 'currency' => 'IDR',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $branch, 'referral_branch_id' => $branch, 'referral_source' => 'default',
            'package_id' => $package, 'source_system' => 'DIRECT_PUBLIC',
            'full_name' => $suffix, 'phone' => '620000000000',
        ]);

        return compact('branch', 'package', 'participant');
    }

    /** @param array{branch:int,package:int,participant:int} $graph */
    private function createCase(array $graph): int
    {
        return DB::table('assessment_cases')->insertGetId([
            'public_id' => (string) Str::ulid(), 'participant_id' => $graph['participant'],
            'organization_id' => $graph['branch'], 'package_id' => $graph['package'],
            'origin' => 'DIRECT_PUBLIC', 'intended_field_snapshot' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function createSession(int $participant): int
    {
        return DB::table('test_sessions')->insertGetId($this->sessionRow($participant));
    }

    /** @return array<string,mixed> */
    private function sessionRow(int $participant, int $attempt = 1, string $testType = 'ist'): array
    {
        return [
            'public_id' => (string) Str::ulid(), 'participant_id' => $participant,
            'test_type' => $testType, 'attempt_no' => $attempt,
            'authorization_id' => (string) Str::ulid(), 'allocation_intent_id' => (string) Str::ulid(),
            'duration_seconds' => 3600, 'status' => 'created', 'answers_revision' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ];
    }

    /** @return array<string,mixed> */
    private function definitions(): array
    {
        return [
            'columns' => DB::select("PRAGMA table_info('test_sessions')"),
            'case_indexes' => DB::select("SELECT name,sql FROM sqlite_master WHERE type='index' AND tbl_name='assessment_cases' ORDER BY name"),
            'indexes' => DB::select("PRAGMA index_list('test_sessions')"),
            'index_sql' => DB::select("SELECT name,sql FROM sqlite_master WHERE type='index' AND tbl_name='test_sessions' ORDER BY name"),
            'foreign' => DB::select("PRAGMA foreign_key_list('test_sessions')"),
            'triggers' => DB::select("SELECT name, sql FROM sqlite_master WHERE type='trigger' AND tbl_name='test_sessions' ORDER BY name"),
        ];
    }

    private function corruptSqlite(string $component): void
    {
        if ($component === 'parent_unique') {
            DB::statement('DROP INDEX assessment_cases_session_scope_unique');
            DB::statement('CREATE UNIQUE INDEX assessment_cases_session_scope_unique ON assessment_cases (participant_id,id)');

            return;
        }
        if (str_starts_with($component, 'foreign_')) {
            $this->rebuildWithWrongForeign($component);

            return;
        }
        if (str_starts_with($component, 'index_') || $component === 'active_index_predicate') {
            $index = $component === 'active_index_predicate'
                ? 'test_sessions_case_one_active_unique'
                : 'test_sessions_case_attempt_unique';
            DB::statement("DROP INDEX {$index}");
            $sql = $component === 'index_columns'
                ? 'CREATE UNIQUE INDEX test_sessions_case_attempt_unique ON test_sessions (assessment_case_id,attempt_no,test_type) WHERE assessment_case_id IS NOT NULL'
                : ($component === 'active_index_predicate'
                    ? "CREATE UNIQUE INDEX test_sessions_case_one_active_unique ON test_sessions (assessment_case_id,test_type) WHERE assessment_case_id IS NOT NULL AND status='created'"
                    : 'CREATE UNIQUE INDEX test_sessions_case_attempt_unique ON test_sessions (assessment_case_id,test_type,attempt_no) WHERE assessment_case_id IS NULL');
            DB::statement($sql);

            return;
        }
        $name = $component === 'insert_guard' ? 'test_sessions_case_insert_guard' : 'test_sessions_case_update_guard';
        $event = $component === 'insert_guard' ? 'INSERT' : 'UPDATE';
        DB::connection()->getPdo()->exec("DROP TRIGGER {$name}");
        DB::connection()->getPdo()->exec("CREATE TRIGGER {$name} BEFORE {$event} ON test_sessions FOR EACH ROW BEGIN SELECT 1; END;");
    }

    private function rebuildWithWrongForeign(string $component): void
    {
        $triggers = DB::select("SELECT name,sql FROM sqlite_master WHERE type='trigger' AND sql IS NOT NULL AND lower(sql) LIKE '%test_sessions%' ORDER BY name");
        $indexes = DB::select("SELECT name,sql FROM sqlite_master WHERE type='index' AND tbl_name='test_sessions' AND sql IS NOT NULL ORDER BY name");
        foreach ($triggers as $trigger) {
            DB::connection()->getPdo()->exec('DROP TRIGGER "'.str_replace('"', '""', (string) $trigger->name).'"');
        }
        foreach ($indexes as $index) {
            DB::connection()->getPdo()->exec('DROP INDEX "'.str_replace('"', '""', (string) $index->name).'"');
        }
        if ($component === 'foreign_source' || $component === 'foreign_reference') {
            DB::statement('CREATE UNIQUE INDEX synthetic_session_scope_unique ON assessment_cases (participant_id,id)');
        }
        Schema::table('test_sessions', function (Blueprint $table) use ($component): void {
            $table->dropForeign(['assessment_case_id', 'participant_id']);
            $source = $component === 'foreign_source'
                ? ['participant_id', 'assessment_case_id']
                : ['assessment_case_id', 'participant_id'];
            $reference = $component === 'foreign_reference' || $component === 'foreign_source'
                ? ['participant_id', 'id']
                : ['id', 'participant_id'];
            $foreign = $table->foreign($source, 'test_sessions_case_scope_fk')
                ->references($reference)->on('assessment_cases');
            $component === 'foreign_delete' ? $foreign->cascadeOnDelete() : $foreign->restrictOnDelete();
        });
        foreach ($indexes as $index) {
            DB::connection()->getPdo()->exec((string) $index->sql);
        }
        foreach ($triggers as $trigger) {
            DB::connection()->getPdo()->exec((string) $trigger->sql);
        }
    }

    private function migrate(string $direction): void
    {
        $migration = require database_path('migrations/2026_09_09_000400_harden_test_session_case_identity.php');
        $operation = [$migration, $direction];
        if (! is_callable($operation)) {
            throw new RuntimeException("Migration operation {$direction} is unavailable.");
        }
        $operation();
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
