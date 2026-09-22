<?php

declare(strict_types=1);

namespace Tests\Feature\AssessmentSessions;

use App\Actions\AssessmentSessions\SubtestNext;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Domain\AssessmentSessions\TimedSegmentTransitionPolicy;
use App\Security\RlsContextRunner;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\OrganizationPaymentTestCase;

/**
 * F2 timed-segments stage 4 (2026-09-22). Exercises the real SubtestNext
 * action end to end (not just TimedSegmentTransitionPolicy in isolation) --
 * row locking, the sweep-then-decide-then-persist sequence, and the
 * expiry-sealing path every other write action shares.
 */
final class SubtestNextTest extends OrganizationPaymentTestCase
{
    private int $attempt = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $command = $this->artisan('migrate', ['--force' => true]);
        if (is_int($command)) {
            $this->fail('The migration command did not return a test command wrapper.');
        }
        $command->assertExitCode(0);
    }

    public function test_confirming_a_reading_gap_starts_the_timed_window(): void
    {
        $participant = $this->participant();
        $sessionId = $this->sessionPublicId(
            $participant, segmentIndex: 0,
            becameCurrentAt: '2026-09-08 03:00:10+07:00', startedAt: null,
            readingCapSecondsOnFirstSegment: 60,
        );

        $result = $this->action($this->clock('2026-09-08 03:00:30+07:00'))->execute($participant, $sessionId);

        $this->assertTrue($result->accepted);
        $this->assertSame(0, $result->segmentIndex);
        $this->assertNotNull($result->startedAt);

        $row = DB::table('test_sessions')->where('public_id', $sessionId)->sole();
        $this->assertNotNull($row->current_segment_started_at);
    }

    public function test_early_finish_disallowed_on_the_current_segment_is_rejected_without_state_change(): void
    {
        $participant = $this->participant();
        $sessionId = $this->sessionPublicId(
            $participant, segmentIndex: 0,
            becameCurrentAt: '2026-09-08 03:00:00+07:00', startedAt: '2026-09-08 03:00:00+07:00',
        );
        $before = DB::table('test_sessions')->where('public_id', $sessionId)->sole();

        $result = $this->action($this->clock('2026-09-08 03:02:00+07:00'))->execute($participant, $sessionId);

        $this->assertFalse($result->accepted);
        $this->assertSame('INVALID_SESSION_TRANSITION', $result->errorCode);
        $after = DB::table('test_sessions')->where('public_id', $sessionId)->sole();
        $this->assertEquals($before, $after);
    }

    public function test_early_finish_allowed_on_the_current_segment_advances_using_real_now(): void
    {
        $participant = $this->participant();
        $sessionId = $this->sessionPublicId(
            $participant, segmentIndex: 0,
            becameCurrentAt: '2026-09-08 03:00:00+07:00', startedAt: '2026-09-08 03:00:00+07:00',
            allowEarlyFinishOnFirstSegment: true,
        );

        $result = $this->action($this->clock('2026-09-08 03:02:00+07:00'))->execute($participant, $sessionId);

        $this->assertTrue($result->accepted);
        $this->assertSame(1, $result->segmentIndex);

        $row = DB::table('test_sessions')->where('public_id', $sessionId)->sole();
        $this->assertSame(1, (int) $row->current_segment_index);
    }

    public function test_unknown_session_is_rejected(): void
    {
        $participant = $this->participant();

        $result = $this->action($this->clock('2026-09-08 03:00:00+07:00'))
            ->execute($participant, (string) Str::ulid());

        $this->assertFalse($result->accepted);
        $this->assertSame('SESSION_NOT_FOUND', $result->errorCode);
    }

    public function test_request_past_ends_at_is_rejected_and_seals_the_session_to_expired(): void
    {
        $participant = $this->participant();
        $sessionId = $this->sessionPublicId(
            $participant, segmentIndex: 0,
            becameCurrentAt: '2026-09-08 03:00:00+07:00', startedAt: '2026-09-08 03:00:00+07:00',
        );

        $result = $this->action($this->clock('2026-09-08 05:00:00+07:00'))->execute($participant, $sessionId);

        $this->assertFalse($result->accepted);
        $this->assertSame('DEADLINE_EXCEEDED', $result->errorCode);
        $this->assertSame('expired', DB::table('test_sessions')->where('public_id', $sessionId)->value('status'));
    }

    /**
     * SubtestNext reads the row exactly once (under lockForUpdate) and then
     * decides from that single snapshot - unlike AutosaveAssessmentAnswers/
     * ReportSigningService, there is no second read later in the same
     * transaction for a DB::listen callback to inject interference into,
     * so a single-connection "race" simulation here would either do
     * nothing observable or just duplicate
     * test_early_finish_disallowed_on_the_current_segment_is_rejected_without_state_change's
     * own pre-seeded-state coverage. The real proof that two genuinely
     * concurrent callers serialize (lockForUpdate blocks the second until
     * the first commits, and the second's fresh sweep then correctly
     * rejects rather than repeating the first's effect) needs two real
     * database connections and lives in
     * tests/Postgres/SubtestNextConcurrencyTest.php, per Lead's requirement
     * (2026-09-22).
     */
    private function action(callable $clock): SubtestNext
    {
        return new SubtestNext(
            $this->app->make(RlsContextRunner::class),
            new TimedSegmentTransitionPolicy,
            $clock(...),
        );
    }

    private function clock(string $time): callable
    {
        return static fn (): DateTimeImmutable => new DateTimeImmutable($time);
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

    private function sessionPublicId(
        int $participant,
        int $segmentIndex,
        string $becameCurrentAt,
        ?string $startedAt,
        bool $allowEarlyFinishOnFirstSegment = false,
        int $readingCapSecondsOnFirstSegment = 0,
    ): string {
        $publicId = (string) Str::ulid();
        $definitionSource = [
            'instrument' => 'ist', 'version' => 'synthetic-definition-v1',
            'provenance' => 'synthetic-subtest-next-test-only', 'total_duration_seconds' => 3600,
            'subtests' => [
                [
                    'code' => 'SE', 'duration_seconds' => 1800, 'item_count' => 5,
                    'allow_early_finish' => $allowEarlyFinishOnFirstSegment,
                    'reading_cap_seconds' => $readingCapSecondsOnFirstSegment,
                ],
                ['code' => 'WA', 'duration_seconds' => 1800, 'item_count' => 5],
            ],
            'randomization' => 'fixed', 'seed' => null, 'generator' => null,
        ];
        $definition = SessionDefinition::fromArray([
            ...$definitionSource, 'checksum' => SessionDefinition::checksumFor($definitionSource),
        ]);

        // Revision 1's formula (stage 3): duration_seconds/ends_at must cover
        // the reading cap on top of the timed duration, or the snapshot
        // guard trigger (stage 3b) rejects the insert outright.
        $durationSeconds = 3600 + $readingCapSecondsOnFirstSegment;
        $endsAt = (new DateTimeImmutable('2026-09-08 03:00:00.000000+07:00'))
            ->modify("+{$durationSeconds} seconds")
            ->format('Y-m-d H:i:s.uP');

        // The stage-1 CHECK/trigger compares these as text (SQLite has no
        // native timestamp type), so every timestamp column must share the
        // exact same microsecond-padded format - '...+07:00' sorts BEFORE
        // '....000000+07:00' lexicographically despite being the same
        // instant, which would fail the >= comparisons silently.
        $normalizedBecameCurrentAt = (new DateTimeImmutable($becameCurrentAt))->format('Y-m-d H:i:s.uP');
        $normalizedStartedAt = $startedAt === null ? null : (new DateTimeImmutable($startedAt))->format('Y-m-d H:i:s.uP');

        DB::table('test_sessions')->insert([
            'public_id' => $publicId, 'participant_id' => $participant, 'test_type' => 'ist',
            'attempt_no' => ++$this->attempt,
            'authorization_id' => (string) Str::ulid(), 'allocation_intent_id' => (string) Str::ulid(),
            'duration_seconds' => $durationSeconds, 'status' => 'in_progress', 'answers_revision' => 0,
            'started_at' => '2026-09-08 03:00:00.000000+07:00', 'ends_at' => $endsAt,
            'current_segment_index' => $segmentIndex,
            'current_segment_became_current_at' => $normalizedBecameCurrentAt,
            'current_segment_started_at' => $normalizedStartedAt,
            'session_definition_version' => $definition->version,
            'session_definition_provenance' => $definition->provenance,
            'session_definition_checksum' => $definition->checksum,
            'session_definition_payload' => json_encode($definition->toArray(), JSON_THROW_ON_ERROR),
        ]);

        return $publicId;
    }
}
