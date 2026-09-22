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

    /**
     * F2 (2026-09-21): renamed from test_kraepelin_snapshot_accepts_only_the_canonical_seeded_matrix.
     * Kraepelin numbers are fixed, not seeded (owner decision, 2026-09-21) --
     * the canonical Kraepelin snapshot is now 'fixed' randomization with a
     * null seed, the same contract ist/papi/rmib already use. The old
     * 'seeded' mode and any non-null seed are now themselves the invalid
     * cases, not just malformed seed strings.
     */
    public function test_kraepelin_snapshot_accepts_only_the_canonical_fixed_matrix(): void
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

        $seededMode = $definition;
        $seededMode['randomization'] = 'seeded';
        $this->assertRejected(fn () => DB::table('test_sessions')->insert(
            $this->sessionRow(duration: 750, testType: 'kraepelin') + $this->snapshot($seededMode),
        ));

        $nonNullSeed = $definition;
        $nonNullSeed['seed'] = 'synthetic-seed';
        $this->assertRejected(fn () => DB::table('test_sessions')->insert(
            $this->sessionRow(duration: 750, testType: 'kraepelin') + $this->snapshot($nonNullSeed),
        ));
    }

    // ═══════════════════════════════════════════════
    // F2 timed-segments stage 3b (2026-09-22) --
    // tasks/handoffs/f2/timed-segments-plan.md. Raw INSERT/UPDATE through
    // this test connection, bypassing SessionDefinition's PHP validation
    // entirely, so these prove the DATABASE itself enforces the new shape
    // and duration invariants -- not just the PHP layer in front of it.
    // ═══════════════════════════════════════════════

    public function test_snapshot_accepts_any_independent_combination_of_the_optional_subtest_fields(): void
    {
        $readingCapOnly = $this->fixedDefinition();
        $readingCapOnly['subtests'][0]['reading_cap_seconds'] = 15;
        $readingCapOnly['checksum'] = SessionDefinition::checksumFor($readingCapOnly);
        DB::table('test_sessions')->insert(
            $this->sessionRow(duration: 75) + $this->snapshot($readingCapOnly),
        );

        $earlyFinishOnly = $this->fixedDefinition();
        $earlyFinishOnly['subtests'][0]['allow_early_finish'] = true;
        $earlyFinishOnly['checksum'] = SessionDefinition::checksumFor($earlyFinishOnly);
        DB::table('test_sessions')->insert(
            $this->sessionRow(duration: 60) + $this->snapshot($earlyFinishOnly),
        );

        $bothNoSegments = $this->fixedDefinition();
        $bothNoSegments['subtests'][0]['reading_cap_seconds'] = 10;
        $bothNoSegments['subtests'][0]['allow_early_finish'] = true;
        $bothNoSegments['checksum'] = SessionDefinition::checksumFor($bothNoSegments);
        DB::table('test_sessions')->insert(
            $this->sessionRow(duration: 70) + $this->snapshot($bothNoSegments),
        );

        $segmentsOnly = $this->fixedDefinition();
        $segmentsOnly['subtests'][0]['segments'] = [
            ['code' => 'SYNTHETIC_A', 'duration_seconds' => 20, 'reading_cap_seconds' => 5, 'allow_early_finish' => false],
            ['code' => 'SYNTHETIC_B', 'duration_seconds' => 40, 'reading_cap_seconds' => 0, 'allow_early_finish' => true],
        ];
        $segmentsOnly['checksum'] = SessionDefinition::checksumFor($segmentsOnly);
        DB::table('test_sessions')->insert(
            $this->sessionRow(duration: 65) + $this->snapshot($segmentsOnly),
        );

        $this->assertSame(4, DB::table('test_sessions')->count());
    }

    public function test_snapshot_rejects_a_subtest_with_the_wrong_key_count_even_with_optional_fields_present(): void
    {
        $tooFew = $this->fixedDefinition();
        unset($tooFew['subtests'][0]['item_count']);
        $tooFew['subtests'][0]['reading_cap_seconds'] = 10;
        $this->assertRejected(fn () => DB::table('test_sessions')->insert(
            $this->sessionRow(duration: 70) + $this->snapshot($tooFew),
        ), 'missing a required field alongside a valid optional one');

        $unknown = $this->fixedDefinition();
        $unknown['subtests'][0]['reading_cap_seconds'] = 10;
        $unknown['subtests'][0]['unexpected'] = true;
        $this->assertRejected(fn () => DB::table('test_sessions')->insert(
            $this->sessionRow(duration: 70) + $this->snapshot($unknown),
        ), 'unknown field alongside a valid optional one');

        $wrongType = $this->fixedDefinition();
        $wrongType['subtests'][0]['reading_cap_seconds'] = '10';
        $this->assertRejected(fn () => DB::table('test_sessions')->insert(
            $this->sessionRow(duration: 70) + $this->snapshot($wrongType),
        ), 'reading_cap_seconds as a string');

        $negative = $this->fixedDefinition();
        $negative['subtests'][0]['reading_cap_seconds'] = -1;
        $this->assertRejected(fn () => DB::table('test_sessions')->insert(
            $this->sessionRow(duration: 59) + $this->snapshot($negative),
        ), 'negative reading_cap_seconds');

        $wrongBoolType = $this->fixedDefinition();
        $wrongBoolType['subtests'][0]['allow_early_finish'] = 'true';
        $this->assertRejected(fn () => DB::table('test_sessions')->insert(
            $this->sessionRow(duration: 60) + $this->snapshot($wrongBoolType),
        ), 'allow_early_finish as a string');
    }

    public function test_snapshot_rejects_malformed_segments_shape(): void
    {
        $emptyArray = $this->fixedDefinition();
        $emptyArray['subtests'][0]['segments'] = [];
        $this->assertRejected(fn () => DB::table('test_sessions')->insert(
            $this->sessionRow(duration: 60) + $this->snapshot($emptyArray),
        ), 'empty segments array');

        $missingKey = $this->fixedDefinition();
        $missingKey['subtests'][0]['segments'] = [
            ['code' => 'SYNTHETIC_A', 'duration_seconds' => 60, 'reading_cap_seconds' => 0],
        ];
        $this->assertRejected(fn () => DB::table('test_sessions')->insert(
            $this->sessionRow(duration: 60) + $this->snapshot($missingKey),
        ), 'segment missing allow_early_finish');

        $extraKey = $this->fixedDefinition();
        $extraKey['subtests'][0]['segments'] = [
            ['code' => 'SYNTHETIC_A', 'duration_seconds' => 60, 'reading_cap_seconds' => 0, 'allow_early_finish' => false, 'unexpected' => true],
        ];
        $this->assertRejected(fn () => DB::table('test_sessions')->insert(
            $this->sessionRow(duration: 60) + $this->snapshot($extraKey),
        ), 'segment with an unexpected key');

        $wrongDurationType = $this->fixedDefinition();
        $wrongDurationType['subtests'][0]['segments'] = [
            ['code' => 'SYNTHETIC_A', 'duration_seconds' => '60', 'reading_cap_seconds' => 0, 'allow_early_finish' => false],
        ];
        $this->assertRejected(fn () => DB::table('test_sessions')->insert(
            $this->sessionRow(duration: 60) + $this->snapshot($wrongDurationType),
        ), 'segment duration_seconds as a string');

        $collidingCode = $this->fixedDefinition();
        $collidingCode['subtests'][0]['segments'] = [
            ['code' => 'SYNTHETIC', 'duration_seconds' => 60, 'reading_cap_seconds' => 0, 'allow_early_finish' => false],
        ];
        $this->assertRejected(fn () => DB::table('test_sessions')->insert(
            $this->sessionRow(duration: 60) + $this->snapshot($collidingCode),
        ), 'segment code colliding with its own parent subtest code');
    }

    public function test_snapshot_rejects_segment_durations_not_summing_to_their_parent_subtest(): void
    {
        $definition = $this->fixedDefinition();
        $definition['subtests'][0]['segments'] = [
            ['code' => 'SYNTHETIC_A', 'duration_seconds' => 20, 'reading_cap_seconds' => 0, 'allow_early_finish' => false],
            ['code' => 'SYNTHETIC_B', 'duration_seconds' => 30, 'reading_cap_seconds' => 0, 'allow_early_finish' => false],
        ];

        $this->assertRejected(fn () => DB::table('test_sessions')->insert(
            $this->sessionRow(duration: 60) + $this->snapshot($definition),
        ), 'segments summing to 50, parent subtest duration is 60');
    }

    /**
     * The duration invariant this whole extension exists for (revision 1 of
     * tasks/handoffs/f2/timed-segments-plan.md): duration_seconds must be
     * exactly total_duration_seconds PLUS the reading cap sum -- neither
     * the old formula (ignoring the cap) nor an arbitrary other value.
     */
    public function test_snapshot_rejects_a_duration_that_ignores_the_reading_cap(): void
    {
        $definition = $this->fixedDefinition();
        $definition['subtests'][0]['reading_cap_seconds'] = 15;
        $definition['checksum'] = SessionDefinition::checksumFor($definition);

        // The pre-stage-3 formula: duration_seconds = total_duration_seconds
        // alone, ignoring the 15s reading cap entirely.
        $this->assertRejected(fn () => DB::table('test_sessions')->insert(
            $this->sessionRow(duration: 60) + $this->snapshot($definition),
        ), 'duration_seconds omits the reading cap');

        // An arbitrary other value is equally wrong.
        $this->assertRejected(fn () => DB::table('test_sessions')->insert(
            $this->sessionRow(duration: 100) + $this->snapshot($definition),
        ), 'duration_seconds is an arbitrary value');

        // Exactly total_duration_seconds + reading cap succeeds.
        DB::table('test_sessions')->insert(
            $this->sessionRow(duration: 75) + $this->snapshot($definition),
        );
        $this->assertSame(1, DB::table('test_sessions')->count());
    }

    /**
     * The Kraepelin branch has its own hardcoded 750 check (generator
     * shape is fixed: 50 columns x 15s) -- this must account for a reading
     * cap exactly the same way the general path does, not stay pinned at
     * a literal 750 regardless of the cap.
     */
    public function test_kraepelin_snapshot_duration_accounts_for_a_reading_cap(): void
    {
        $definition = $this->kraepelinDefinition();
        $definition['subtests'][0]['reading_cap_seconds'] = 20;
        $definition['checksum'] = SessionDefinition::checksumFor($definition);

        $this->assertRejected(fn () => DB::table('test_sessions')->insert(
            $this->sessionRow(duration: 750, testType: 'kraepelin') + $this->snapshot($definition),
        ), 'Kraepelin duration_seconds stays literally 750, ignoring the reading cap');

        DB::table('test_sessions')->insert(
            $this->sessionRow(duration: 770, testType: 'kraepelin') + $this->snapshot($definition),
        );
        $this->assertSame(1, DB::table('test_sessions')->count());
    }

    /**
     * Lead's explicit ask (2026-09-22): confirm no OTHER invariant in this
     * same trigger was disturbed by how the total duration is now
     * recomputed -- item_count is entirely unrelated to duration/reading
     * caps, so the Kraepelin 1350-item check must still fire exactly as
     * before, reading-cap fields present or not.
     */
    public function test_item_count_invariant_is_unaffected_by_reading_cap_fields(): void
    {
        $definition = $this->kraepelinDefinition();
        $definition['subtests'][0]['reading_cap_seconds'] = 5;
        $definition['subtests'][0]['item_count'] = 1349;
        $definition['checksum'] = SessionDefinition::checksumFor($definition);

        $this->assertRejected(fn () => DB::table('test_sessions')->insert(
            $this->sessionRow(duration: 755, testType: 'kraepelin') + $this->snapshot($definition),
        ), 'wrong item_count still rejected with a reading cap present');
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
            'randomization' => 'fixed',
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
