<?php

declare(strict_types=1);

namespace Tests\Unit\AssessmentSessions;

use App\Domain\AssessmentSessions\AssessmentSessionErrorCode;
use App\Domain\AssessmentSessions\AssessmentSessionStatus;
use App\Domain\AssessmentSessions\TimedSegment;
use App\Domain\AssessmentSessions\TimedSegmentSweepResult;
use App\Domain\AssessmentSessions\TimedSegmentTransitionPolicy;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * F2 timed-segments stage 4 (2026-09-22). Revision 3 of
 * tasks/handoffs/f2/timed-segments-plan.md's two-meaning subtest/next
 * semantics, plus the whole-session deadline gate every other write path
 * already shares.
 */
final class TimedSegmentTransitionPolicyTest extends TestCase
{
    private const NOW = '2026-09-22T08:10:00+00:00';

    public function test_confirming_a_reading_gap_is_always_permitted_regardless_of_allow_early_finish(): void
    {
        $segments = [new TimedSegment('ME_MEMORIZE', 180, 30, false)];
        $swept = new TimedSegmentSweepResult(0, $this->at('2026-09-22T08:09:50+00:00'), null, false);

        $decision = (new TimedSegmentTransitionPolicy)->decide(
            AssessmentSessionStatus::InProgress,
            $this->at('2026-09-22T09:00:00+00:00'),
            $this->at(self::NOW),
            $segments,
            $swept,
        );

        $this->assertTrue($decision->accepted);
        $this->assertSame(0, $decision->index);
        $this->assertEquals($this->at('2026-09-22T08:09:50+00:00'), $decision->becameCurrentAt);
        $this->assertEquals($this->at(self::NOW), $decision->startedAt, 'started_at must be the real request time, not a scheduled boundary.');
    }

    public function test_early_finish_mid_window_is_rejected_when_the_current_segment_disallows_it(): void
    {
        $segments = [
            new TimedSegment('SE', 300, 0, false),
            new TimedSegment('WA', 600, 0, false),
        ];
        $swept = new TimedSegmentSweepResult(0, $this->at('2026-09-22T08:00:00+00:00'), $this->at('2026-09-22T08:00:00+00:00'), false);

        $decision = (new TimedSegmentTransitionPolicy)->decide(
            AssessmentSessionStatus::InProgress,
            $this->at('2026-09-22T09:00:00+00:00'),
            $this->at(self::NOW),
            $segments,
            $swept,
        );

        $this->assertFalse($decision->accepted);
        $this->assertSame(AssessmentSessionErrorCode::InvalidSessionTransition, $decision->errorCode);
    }

    public function test_early_finish_mid_window_advances_to_the_next_segment_using_real_now(): void
    {
        $segments = [
            new TimedSegment('SE', 300, 0, true),
            new TimedSegment('WA', 600, 30, false),
        ];
        $swept = new TimedSegmentSweepResult(0, $this->at('2026-09-22T08:00:00+00:00'), $this->at('2026-09-22T08:00:00+00:00'), false);

        $decision = (new TimedSegmentTransitionPolicy)->decide(
            AssessmentSessionStatus::InProgress,
            $this->at('2026-09-22T09:00:00+00:00'),
            $this->at(self::NOW),
            $segments,
            $swept,
        );

        $this->assertTrue($decision->accepted);
        $this->assertSame(1, $decision->index);
        $this->assertEquals($this->at(self::NOW), $decision->becameCurrentAt, 'No time is granted or lost - the next segment becomes current at the real early-finish instant.');
        $this->assertNull($decision->startedAt, 'The next segment has its own 30s reading cap, so it waits rather than starting immediately.');
    }

    public function test_early_finish_on_the_last_segment_is_rejected_with_nothing_to_advance_into(): void
    {
        $segments = [new TimedSegment('SE', 300, 0, true)];
        $swept = new TimedSegmentSweepResult(0, $this->at('2026-09-22T08:00:00+00:00'), $this->at('2026-09-22T08:00:00+00:00'), false);

        $decision = (new TimedSegmentTransitionPolicy)->decide(
            AssessmentSessionStatus::InProgress,
            $this->at('2026-09-22T09:00:00+00:00'),
            $this->at(self::NOW),
            $segments,
            $swept,
        );

        $this->assertFalse($decision->accepted);
        $this->assertSame(AssessmentSessionErrorCode::InvalidSessionTransition, $decision->errorCode);
    }

    /**
     * A swept-expired segment list (nothing left to act on) can happen
     * BEFORE the session's own ends_at - e.g. every segment consumed early
     * via allow_early_finish, leaving unused reading-cap slack on the
     * whole-session clock. This must reject on its own merits, not because
     * the whole-session deadline gate happened to fire first.
     */
    public function test_a_segment_list_already_swept_to_expired_is_rejected_with_nothing_to_act_on(): void
    {
        $segments = [new TimedSegment('SE', 300, 0, true)];
        $swept = new TimedSegmentSweepResult(0, $this->at('2026-09-22T08:00:00+00:00'), $this->at('2026-09-22T08:00:00+00:00'), true);

        $decision = (new TimedSegmentTransitionPolicy)->decide(
            AssessmentSessionStatus::InProgress,
            $this->at('2026-09-22T09:00:00+00:00'),
            $this->at(self::NOW),
            $segments,
            $swept,
        );

        $this->assertFalse($decision->accepted);
        $this->assertSame(AssessmentSessionErrorCode::InvalidSessionTransition, $decision->errorCode);
    }

    public function test_a_created_session_is_rejected_by_the_same_whole_session_deadline_gate_every_write_path_shares(): void
    {
        $segments = [new TimedSegment('SE', 300, 0, false)];
        $swept = new TimedSegmentSweepResult(0, $this->at(self::NOW), $this->at(self::NOW), false);

        $decision = (new TimedSegmentTransitionPolicy)->decide(
            AssessmentSessionStatus::Created,
            null,
            $this->at(self::NOW),
            $segments,
            $swept,
        );

        $this->assertFalse($decision->accepted);
        $this->assertSame(AssessmentSessionErrorCode::SessionNotStarted, $decision->errorCode);
    }

    public function test_a_request_past_the_sessions_own_ends_at_is_rejected_and_seals_to_expired(): void
    {
        $segments = [new TimedSegment('SE', 300, 0, false)];
        $swept = new TimedSegmentSweepResult(0, $this->at(self::NOW), $this->at(self::NOW), false);

        $decision = (new TimedSegmentTransitionPolicy)->decide(
            AssessmentSessionStatus::InProgress,
            $this->at('2026-09-22T08:00:00+00:00'),
            $this->at(self::NOW),
            $segments,
            $swept,
        );

        $this->assertFalse($decision->accepted);
        $this->assertSame(AssessmentSessionStatus::Expired, $decision->status);
        $this->assertSame(AssessmentSessionErrorCode::DeadlineExceeded, $decision->errorCode);
    }

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time);
    }
}
