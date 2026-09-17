<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Domain\AssessmentSessions\SessionDefinition;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;

final class TestSessionDefinitionSnapshotSchemaTest extends OrganizationPaymentTestCase
{
    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            RefreshDatabaseState::$migrated = false;
        }
    }

    use RefreshDatabase;

    public function test_snapshot_columns_are_nullable_additive_and_unindexed(): void
    {
        $session = $this->createSession();
        $columns = collect(DB::select("PRAGMA table_info('test_sessions')"))->keyBy('name');

        foreach ($this->snapshotColumns() as $column) {
            $this->assertArrayHasKey($column, $columns);
            $this->assertSame(0, (int) $columns[$column]->notnull, $column);
            $this->assertNull(DB::table('test_sessions')->where('id', $session)->value($column));
        }

        $indexes = collect(DB::select("PRAGMA index_list('test_sessions')"))->pluck('name')->all();
        $this->assertSame([], array_values(array_filter(
            $indexes,
            static fn (string $name): bool => str_contains($name, 'definition_checksum'),
        )));
    }

    public function test_snapshot_is_all_null_or_an_exact_consistent_session_definition(): void
    {
        $definition = $this->fixedDefinition();
        $valid = $this->sessionRow() + $this->snapshot($definition);
        DB::table('test_sessions')->insert($valid);

        $partial = $this->sessionRow();
        $partial['session_definition_version'] = $definition['version'];
        $this->assertRejected(fn () => DB::table('test_sessions')->insert($partial));

        $mismatch = $this->sessionRow() + $this->snapshot($definition);
        $mismatch['duration_seconds'] = 61;
        $this->assertRejected(fn () => DB::table('test_sessions')->insert($mismatch));

        $extra = $definition;
        $extra['unexpected'] = true;
        $this->assertRejected(fn () => DB::table('test_sessions')->insert(
            $this->sessionRow() + $this->snapshot($extra),
        ));
    }

    public function test_snapshot_rejects_every_payload_and_scalar_shape_violation(): void
    {
        $definition = $this->fixedDefinition();
        $validRow = $this->sessionRow() + $this->snapshot($definition);
        $scalarMismatches = [
            'version mismatch' => ['session_definition_version' => 'other-version'],
            'provenance mismatch' => ['session_definition_provenance' => 'other-provenance'],
            'checksum mismatch' => ['session_definition_checksum' => str_repeat('a', 64)],
        ];
        foreach ($scalarMismatches as $label => $change) {
            $this->assertRejected(
                fn () => DB::table('test_sessions')->insert(array_replace($validRow, $change)),
                $label,
            );
        }

        $invalid = [];
        $invalid['instrument mismatch'] = $this->changed($definition, 'instrument', 'papi');
        $invalid['duration mismatch'] = $this->changed($definition, 'total_duration_seconds', 61);
        $invalid['missing top-level field'] = array_diff_key($definition, ['generator' => true]);

        $subtestExtra = $definition;
        $subtestExtra['subtests'][0]['unexpected'] = true;
        $invalid['subtest extra field'] = $subtestExtra;
        $subtestZero = $definition;
        $subtestZero['subtests'][0]['item_count'] = 0;
        $invalid['subtest non-positive count'] = $subtestZero;
        $duplicate = $definition;
        $duplicate['subtests'] = [
            ['code' => 'DUP', 'duration_seconds' => 30, 'item_count' => 1],
            ['code' => 'DUP', 'duration_seconds' => 30, 'item_count' => 1],
        ];
        $invalid['duplicate subtest code'] = $duplicate;
        $fixedSeed = $definition;
        $fixedSeed['seed'] = 'forbidden';
        $invalid['fixed seed'] = $fixedSeed;

        foreach ($invalid as $label => $payload) {
            $this->assertRejected(
                fn () => DB::table('test_sessions')->insert($this->sessionRow() + $this->snapshot($payload)),
                $label,
            );
        }

        $nonObject = $validRow;
        $nonObject['session_definition_payload'] = '[]';
        $this->assertRejected(fn () => DB::table('test_sessions')->insert($nonObject), 'non-object top level');

        $uppercase = $this->sessionRow() + $this->snapshot($definition);
        $uppercase['session_definition_checksum'] = strtoupper($definition['checksum']);
        $uppercase['session_definition_payload'] = str_replace(
            $definition['checksum'],
            strtoupper($definition['checksum']),
            (string) $uppercase['session_definition_payload'],
        );
        $this->assertRejected(fn () => DB::table('test_sessions')->insert($uppercase), 'uppercase checksum');

        $badIdentity = $definition;
        $badIdentity['version'] = "synthetic\u{200B}v1";
        $this->assertRejected(
            fn () => DB::table('test_sessions')->insert($this->sessionRow() + $this->snapshot($badIdentity)),
            'format character in identity',
        );
    }

    public function test_kraepelin_snapshot_accepts_only_the_canonical_seeded_matrix(): void
    {
        $definition = $this->kraepelinDefinition();
        DB::table('test_sessions')->insert($this->sessionRow(duration: 750, testType: 'kraepelin')
            + $this->snapshot($definition));

        $stringColumns = $definition;
        $stringColumns['generator']['columns'] = '50';
        $this->assertRejected(fn () => DB::table('test_sessions')->insert(
            $this->sessionRow(duration: 750, testType: 'kraepelin') + $this->snapshot($stringColumns),
        ));

        $wrongItems = $definition;
        $wrongItems['subtests'][0]['item_count'] = 1349;
        $this->assertRejected(fn () => DB::table('test_sessions')->insert(
            $this->sessionRow(duration: 750, testType: 'kraepelin') + $this->snapshot($wrongItems),
        ));

        $badSeed = $definition;
        $badSeed['seed'] = "seed\u{00A0}";
        $this->assertRejected(fn () => DB::table('test_sessions')->insert(
            $this->sessionRow(duration: 750, testType: 'kraepelin') + $this->snapshot($badSeed),
        ));
    }

    public function test_existing_sessions_and_grants_remain_null_without_backfill(): void
    {
        $this->migrate('down');
        $graph = $this->directGraph('historical', false);
        DB::table('test_session_grants')->insert($this->grantRow($graph));

        $this->migrate('up');

        $this->assertDatabaseHas('test_session_grants', ['test_session_id' => $graph['session']]);
        foreach ($this->snapshotColumns() as $column) {
            $this->assertNull(DB::table('test_sessions')->where('id', $graph['session'])->value($column));
        }
    }

    public function test_snapshot_is_immutable_while_session_lifecycle_can_advance(): void
    {
        $definition = $this->fixedDefinition();
        $session = DB::table('test_sessions')->insertGetId(
            $this->sessionRow() + $this->snapshot($definition),
        );
        $started = now();

        DB::table('test_sessions')->where('id', $session)->update([
            'status' => 'in_progress',
            'started_at' => $started,
            'ends_at' => $started->copy()->addSeconds(60),
        ]);
        $this->assertSame('in_progress', DB::table('test_sessions')->where('id', $session)->value('status'));
        $this->assertRejected(fn () => DB::table('test_sessions')->where('id', $session)->update([
            'session_definition_version' => 'synthetic-v2',
        ]));
        $definition['subtests'][0]['item_count'] = 2;
        $definition['checksum'] = SessionDefinition::checksumFor($definition);
        $this->assertRejected(fn () => DB::table('test_sessions')->where('id', $session)->update(
            $this->snapshot($definition),
        ));
    }

    public function test_new_grant_without_snapshot_remains_compatible_during_expand_window(): void
    {
        $missing = $this->directGraph('missing-snapshot', false);
        DB::table('test_session_grants')->insert($this->grantRow($missing));
        $this->assertDatabaseHas('test_session_grants', ['test_session_id' => $missing['session']]);

        $complete = $this->directGraph('complete-snapshot', true);
        DB::table('test_session_grants')->insert($this->grantRow($complete));
        $this->assertDatabaseHas('test_session_grants', ['test_session_id' => $complete['session']]);
    }

    public function test_populated_snapshot_prevents_rollback_without_delta(): void
    {
        $definition = $this->fixedDefinition();
        $session = DB::table('test_sessions')->insertGetId(
            $this->sessionRow() + $this->snapshot($definition),
        );
        $before = $this->snapshotDefinitions();

        try {
            $this->migrate('down');
            $this->fail('Populated definition history must refuse rollback.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Test session definition snapshot history prevents rollback.',
                $exception->getMessage(),
            );
        }

        $this->assertEquals($before, $this->snapshotDefinitions());
        $this->assertDatabaseHas('test_sessions', [
            'id' => $session,
            'session_definition_checksum' => $definition['checksum'],
        ]);
    }

    public function test_rerun_rejects_a_counterfeit_sqlite_guard_without_delta(): void
    {
        DB::unprepared('DROP TRIGGER test_sessions_definition_snapshot_insert_guard');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER test_sessions_definition_snapshot_insert_guard
            BEFORE INSERT ON test_sessions FOR EACH ROW BEGIN SELECT 1; END
            SQL);
        $before = $this->snapshotDefinitions();

        try {
            $this->migrate('up');
            $this->fail('Counterfeit definition guard must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('partial SQLite snapshot enforcement', $exception->getMessage());
        }

        $this->assertEquals($before, $this->snapshotDefinitions());
    }

    /** @return list<string> */
    private function snapshotColumns(): array
    {
        return [
            'session_definition_version',
            'session_definition_provenance',
            'session_definition_checksum',
            'session_definition_payload',
        ];
    }

    /** @return array<string, mixed> */
    private function fixedDefinition(): array
    {
        $definition = [
            'instrument' => 'ist',
            'version' => 'synthetic-v1',
            'provenance' => 'synthetic-test-fixture',
            'checksum' => '',
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
        $definition['checksum'] = SessionDefinition::checksumFor($definition);

        return $definition;
    }

    /** @return array<string, mixed> */
    private function kraepelinDefinition(): array
    {
        $definition = [
            'instrument' => 'kraepelin',
            'version' => 'synthetic-v1',
            'provenance' => 'synthetic-test-fixture',
            'checksum' => '',
            'total_duration_seconds' => 750,
            'subtests' => [[
                'code' => 'SYNTHETIC',
                'duration_seconds' => 750,
                'item_count' => 1350,
            ]],
            'randomization' => 'seeded',
            'seed' => 'synthetic-seed',
            'generator' => [
                'algorithm' => 'synthetic-generator',
                'version' => 'synthetic-v1',
                'columns' => 50,
                'seconds_per_column' => 15,
                'numbers_per_column' => 28,
                'answer_slots_per_column' => 27,
            ],
        ];
        $definition['checksum'] = SessionDefinition::checksumFor($definition);

        return $definition;
    }

    /** @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function changed(array $definition, string $key, mixed $value): array
    {
        $definition[$key] = $value;

        return $definition;
    }

    /** @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function snapshot(array $definition): array
    {
        return [
            'session_definition_version' => $definition['version'],
            'session_definition_provenance' => $definition['provenance'],
            'session_definition_checksum' => $definition['checksum'],
            'session_definition_payload' => json_encode($definition, JSON_THROW_ON_ERROR),
        ];
    }

    private function createSession(): int
    {
        return DB::table('test_sessions')->insertGetId($this->sessionRow());
    }

    /** @return array<string, mixed> */
    private function sessionRow(?int $participant = null, int $duration = 60, string $testType = 'ist'): array
    {
        if ($participant === null) {
            $key = (string) Str::ulid();
            $branch = DB::table('branches')->insertGetId([
                'code' => $key, 'ref_code' => $key, 'name' => $key,
                'organization_code' => $key, 'display_name' => $key,
            ]);
            $participant = DB::table('participants')->insertGetId([
                'branch_id' => $branch, 'referral_branch_id' => $branch, 'referral_source' => 'default',
                'source_system' => 'P4_TEST', 'full_name' => $key, 'phone' => '620000000000',
            ]);
        }

        return [
            'public_id' => (string) Str::ulid(), 'participant_id' => $participant,
            'assessment_case_id' => null, 'test_type' => $testType, 'attempt_no' => 1,
            'authorization_id' => (string) Str::ulid(), 'allocation_intent_id' => (string) Str::ulid(),
            'duration_seconds' => $duration, 'status' => 'created', 'answers_revision' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ];
    }

    /** @return array{branch:int,participant:int,case:int,order:int,entitlement:int,session:int} */
    private function directGraph(string $suffix, bool $withSnapshot): array
    {
        $key = (string) Str::ulid();
        $branch = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => $suffix,
            'organization_code' => $key, 'display_name' => $suffix,
        ]);
        $package = DB::table('packages')->insertGetId([
            'code' => 'PKG-'.$key, 'name' => $suffix, 'amount' => 99000,
            'currency' => 'IDR', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['dass21', 'ist'] as $sort => $type) {
            DB::table('package_items')->insert([
                'package_id' => $package, 'test_type' => $type, 'sort_order' => $sort,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $branch, 'referral_branch_id' => $branch, 'referral_source' => 'default',
            'package_id' => $package, 'source_system' => 'DIRECT_PUBLIC',
            'full_name' => $suffix, 'phone' => '620000000000',
        ]);
        $publicId = (string) Str::ulid();
        $case = DB::table('assessment_cases')->insertGetId([
            'public_id' => $publicId, 'participant_id' => $participant,
            'organization_id' => $branch, 'package_id' => $package,
            'origin' => 'DIRECT_PUBLIC', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $method = DB::table('payment_methods')->insertGetId([
            'code' => 'METHOD-'.$key, 'display_name' => $suffix, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $order = DB::table('orders')->insertGetId([
            'public_id' => $publicId, 'participant_id' => $participant,
            'assessment_case_id' => $case, 'payment_method_id' => $method,
            'status' => 'paid', 'amount' => 99000, 'currency' => 'IDR', 'paid_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['dass21', 'ist'] as $type) {
            $id = DB::table('entitlements')->insertGetId([
                'participant_id' => $participant, 'order_id' => $order, 'test_type' => $type,
                // Generic instruments are case-scoped after the identity migration;
                // DASS deliberately remains outside that scope.
                'assessment_case_id' => $type === 'dass21' ? null : $case,
                'status' => 'ready', 'ready_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($type === 'ist') {
                $entitlement = $id;
            }
        }
        $row = $this->sessionRow($participant);
        $row['assessment_case_id'] = $case;
        if ($withSnapshot) {
            $row += $this->snapshot($this->fixedDefinition());
        }
        $session = DB::table('test_sessions')->insertGetId($row);

        return compact('branch', 'participant', 'case', 'order', 'entitlement', 'session');
    }

    /** @param array{branch:int,participant:int,case:int,order:int,entitlement:int,session:int} $graph
     * @return array<string,mixed>
     */
    private function grantRow(array $graph): array
    {
        return [
            'test_session_id' => $graph['session'], 'assessment_case_id' => $graph['case'],
            'participant_id' => $graph['participant'], 'organization_id' => $graph['branch'],
            'test_type' => 'ist', 'origin' => 'DIRECT_PUBLIC', 'grant_kind' => 'entitlement',
            'order_id' => $graph['order'], 'entitlement_id' => $graph['entitlement'],
            'created_at' => now(),
        ];
    }

    /** @return array<string,mixed> */
    private function snapshotDefinitions(): array
    {
        return [
            'columns' => DB::select("PRAGMA table_info('test_sessions')"),
            'triggers' => DB::select(<<<'SQL'
                SELECT name,sql FROM sqlite_master WHERE type='trigger'
                  AND name LIKE '%definition_snapshot%' ORDER BY name
                SQL),
            'indexes' => DB::select("SELECT name,sql FROM sqlite_master WHERE type='index' AND tbl_name='test_sessions' ORDER BY name"),
        ];
    }

    private function migrate(string $direction): void
    {
        $migration = require database_path('migrations/2026_09_10_000100_add_test_session_definition_snapshots.php');
        if (! in_array($direction, ['up', 'down'], true) || ! method_exists($migration, $direction)) {
            throw new RuntimeException("Migration operation {$direction} is unavailable.");
        }
        (new ReflectionMethod($migration, $direction))->invoke($migration);
    }

    private function assertRejected(callable $operation, string $label = ''): void
    {
        try {
            $operation();
            $this->fail('Expected database rejection'.($label === '' ? '.' : ": {$label}."));
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
