<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Domain\AssessmentSessions\SessionDefinition;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\OrganizationPaymentTestCase;

/**
 * F2 timed-segments stage 6 (2026-09-22). Raw INSERT/UPDATE through this
 * test connection, bypassing SubtestNext/SessionDefinition PHP entirely, so
 * these prove the DATABASE itself enforces the shape and mutability
 * invariants added in stage 1 (2026_09_22_010000_add_timed_segments_to_
 * test_sessions.php's test_sessions_segment_check CHECK and the extended
 * guard_test_sessions_identity_revision() trigger) - not just that
 * SubtestNext, which only ever writes valid, monotonically-increasing
 * states, happens to behave correctly. Stage 1's own verification ran the
 * full existing suite to confirm no regression, but never exercised these
 * new invariants' own edge cases directly; this file closes that gap.
 *
 * SQLite only: the trigger's defensive participant-role branch (Postgres
 * only, SQLite has no role/RLS concept at all) is proven separately in
 * tests/Postgres/TestSessionsSegmentSecurityTest.php.
 */
final class TestSessionsSegmentContractTest extends OrganizationPaymentTestCase
{
    use RefreshDatabase;

    private int $attempt = 0;

    // ═══════════════════════════════════════════════
    // CHECK CONSTRAINT: shape
    // ═══════════════════════════════════════════════

    public function test_created_session_requires_all_three_segment_columns_null(): void
    {
        $participant = $this->participant();
        $this->assertRejected(fn () => DB::table('test_sessions')->insert(
            $this->createdRow($participant) + ['current_segment_index' => 0],
        ));
    }

    public function test_in_progress_session_accepts_all_three_segment_columns_null(): void
    {
        $participant = $this->participant();
        DB::table('test_sessions')->insert($this->inProgressRow($participant));

        $this->assertSame(1, DB::table('test_sessions')->where('participant_id', $participant)->count());
    }

    public function test_in_progress_session_rejects_a_negative_segment_index(): void
    {
        $participant = $this->participant();
        $this->assertRejected(fn () => DB::table('test_sessions')->insert(
            $this->inProgressRow($participant) + $this->segmentColumns(-1, self::STARTED, self::STARTED),
        ));
    }

    public function test_in_progress_session_rejects_an_index_without_became_current_at(): void
    {
        $participant = $this->participant();
        $this->assertRejected(fn () => DB::table('test_sessions')->insert(
            $this->inProgressRow($participant) + $this->segmentColumns(0, null, null),
        ));
    }

    public function test_became_current_at_before_the_sessions_own_started_at_is_rejected(): void
    {
        $participant = $this->participant();
        $before = '2026-09-08 02:59:59.000000+07:00';
        $this->assertRejected(fn () => DB::table('test_sessions')->insert(
            $this->inProgressRow($participant) + $this->segmentColumns(0, $before, $before),
        ));
    }

    public function test_segment_started_at_before_became_current_at_is_rejected(): void
    {
        $participant = $this->participant();
        $before = '2026-09-08 02:59:59.000000+07:00';
        $this->assertRejected(fn () => DB::table('test_sessions')->insert(
            $this->inProgressRow($participant) + $this->segmentColumns(0, self::STARTED, $before),
        ));
    }

    public function test_segment_started_at_may_be_null_while_segment_index_and_became_current_at_are_set(): void
    {
        $participant = $this->participant();
        DB::table('test_sessions')->insert(
            $this->inProgressRow($participant) + $this->segmentColumns(0, self::STARTED, null),
        );

        $this->assertSame(1, DB::table('test_sessions')->where('participant_id', $participant)->count());
    }

    // ═══════════════════════════════════════════════
    // TRIGGER: mutability
    // ═══════════════════════════════════════════════

    public function test_segment_columns_may_be_set_on_the_created_to_in_progress_transition(): void
    {
        $participant = $this->participant();
        $id = DB::table('test_sessions')->insertGetId($this->createdRow($participant));

        DB::table('test_sessions')->where('id', $id)->update([
            'status' => 'in_progress',
            'started_at' => self::STARTED,
            'ends_at' => self::ENDS,
            ...$this->segmentColumns(0, self::STARTED, self::STARTED),
        ]);

        $this->assertSame(0, (int) DB::table('test_sessions')->where('id', $id)->value('current_segment_index'));
    }

    public function test_segment_columns_may_advance_while_staying_in_progress(): void
    {
        $participant = $this->participant();
        $id = DB::table('test_sessions')->insertGetId(
            $this->inProgressRow($participant) + $this->segmentColumns(0, self::STARTED, self::STARTED),
        );

        DB::table('test_sessions')->where('id', $id)->update(
            $this->segmentColumns(1, '2026-09-08 03:05:00.000000+07:00', '2026-09-08 03:05:00.000000+07:00'),
        );

        $this->assertSame(1, (int) DB::table('test_sessions')->where('id', $id)->value('current_segment_index'));
    }

    public function test_segment_index_may_not_decrease(): void
    {
        $participant = $this->participant();
        $id = DB::table('test_sessions')->insertGetId(
            $this->inProgressRow($participant) + $this->segmentColumns(1, self::STARTED, self::STARTED),
        );

        $this->assertRejected(fn () => DB::table('test_sessions')->where('id', $id)->update(
            $this->segmentColumns(0, self::STARTED, self::STARTED),
        ));
    }

    public function test_segment_columns_may_not_change_on_a_transition_out_of_in_progress(): void
    {
        $participant = $this->participant();
        $id = DB::table('test_sessions')->insertGetId(
            $this->inProgressRow($participant) + $this->segmentColumns(0, self::STARTED, self::STARTED),
        );

        $this->assertRejected(fn () => DB::table('test_sessions')->where('id', $id)->update([
            'status' => 'submitted',
            'submitted_at' => '2026-09-08 03:10:00.000000+07:00',
            ...$this->segmentColumns(1, '2026-09-08 03:05:00.000000+07:00', '2026-09-08 03:05:00.000000+07:00'),
        ]));
    }

    public function test_a_submitted_sessions_frozen_segment_state_survives_the_transition(): void
    {
        $participant = $this->participant();
        $id = DB::table('test_sessions')->insertGetId(
            $this->inProgressRow($participant) + $this->segmentColumns(0, self::STARTED, self::STARTED),
        );

        DB::table('test_sessions')->where('id', $id)->update([
            'status' => 'submitted',
            'submitted_at' => '2026-09-08 03:10:00.000000+07:00',
        ]);

        $this->assertSame(0, (int) DB::table('test_sessions')->where('id', $id)->value('current_segment_index'));
    }

    // ─── Helpers ───

    private const STARTED = '2026-09-08 03:00:00.000000+07:00';

    private const ENDS = '2026-09-08 04:00:00.000000+07:00';

    /** @return array{start: int, index: int}|array<string, mixed> */
    private function segmentColumns(int $index, ?string $becameCurrentAt, ?string $startedAt): array
    {
        return [
            'current_segment_index' => $index,
            'current_segment_became_current_at' => $becameCurrentAt,
            'current_segment_started_at' => $startedAt,
        ];
    }

    /** @return array<string, mixed> */
    private function createdRow(int $participant): array
    {
        $definition = $this->fixedDefinition();

        return [
            'public_id' => (string) Str::ulid(), 'participant_id' => $participant, 'test_type' => 'ist',
            'attempt_no' => ++$this->attempt,
            'authorization_id' => (string) Str::ulid(), 'allocation_intent_id' => (string) Str::ulid(),
            'duration_seconds' => 3600, 'status' => 'created', 'answers_revision' => 0,
            'session_definition_version' => $definition->version,
            'session_definition_provenance' => $definition->provenance,
            'session_definition_checksum' => $definition->checksum,
            'session_definition_payload' => json_encode($definition->toArray(), JSON_THROW_ON_ERROR),
        ];
    }

    /** @return array<string, mixed> */
    private function inProgressRow(int $participant): array
    {
        return [...$this->createdRow($participant), 'status' => 'in_progress', 'started_at' => self::STARTED, 'ends_at' => self::ENDS];
    }

    private function fixedDefinition(): SessionDefinition
    {
        $source = [
            'instrument' => 'ist', 'version' => 'synthetic-v1',
            'provenance' => 'segment-contract-test-only', 'total_duration_seconds' => 3600,
            'subtests' => [['code' => 'SYN', 'duration_seconds' => 3600, 'item_count' => 5]],
            'randomization' => 'fixed', 'seed' => null, 'generator' => null,
        ];

        return SessionDefinition::fromArray([...$source, 'checksum' => SessionDefinition::checksumFor($source)]);
    }

    private function participant(): int
    {
        $key = (string) Str::ulid();
        $branch = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => 'Synthetic',
            'organization_code' => $key, 'display_name' => 'Synthetic',
        ]);

        return DB::table('participants')->insertGetId([
            'branch_id' => $branch, 'referral_branch_id' => $branch, 'referral_source' => 'default',
            'full_name' => 'Synthetic', 'phone' => '620000000000',
        ]);
    }

    private function assertRejected(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected a database rejection.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
