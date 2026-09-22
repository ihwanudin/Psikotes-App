<?php

declare(strict_types=1);

namespace Tests\Unit\AssessmentSessions;

use App\Domain\AssessmentSessions\InvalidAssessmentSessionState;
use App\Domain\AssessmentSessions\TimedSegment;
use App\Domain\AssessmentSessions\TimedSegmentSweep;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * F2 timed-segments stage 2 (2026-09-22). Covers revision 2 of
 * tasks/handoffs/f2/timed-segments-plan.md: the lazy sweep must always
 * anchor on previously-scheduled boundaries, never on now() at evaluation
 * time, and Lead's explicit requirement (2026-09-22) to prove the sweep's
 * own expiry boundary and the session's own ends_at (revision 1's formula)
 * never disagree in the no-early-finish case.
 */
final class TimedSegmentSweepTest extends TestCase
{
    private const START = '2026-09-22T08:00:00+00:00';

    public function test_a_single_segment_still_within_its_window_is_not_expired(): void
    {
        $result = (new TimedSegmentSweep)->evaluate(
            [new TimedSegment('SE', 300, 0, false)],
            null,
            null,
            null,
            $this->at(self::START),
            $this->at('2026-09-22T08:04:59+00:00'),
        );

        $this->assertSame(0, $result->index);
        $this->assertEquals($this->at(self::START), $result->becameCurrentAt);
        $this->assertEquals($this->at(self::START), $result->startedAt);
        $this->assertFalse($result->expired);
    }

    public function test_the_last_segment_past_its_duration_expires_the_session(): void
    {
        // Inclusive boundary (matches AssessmentSessionDeadlinePolicy's own
        // receivedAt > endsAt, i.e. receivedAt === endsAt still accepted):
        // exactly at the deadline is still live, one tick past is expired.
        $atDeadline = (new TimedSegmentSweep)->evaluate(
            [new TimedSegment('SE', 300, 0, false)],
            null, null, null,
            $this->at(self::START),
            $this->at('2026-09-22T08:05:00+00:00'),
        );
        $this->assertFalse($atDeadline->expired);

        $result = (new TimedSegmentSweep)->evaluate(
            [new TimedSegment('SE', 300, 0, false)],
            null,
            null,
            null,
            $this->at(self::START),
            $this->at('2026-09-22T08:05:01+00:00'),
        );

        $this->assertSame(0, $result->index);
        $this->assertTrue($result->expired);
    }

    public function test_a_segment_with_a_reading_cap_waits_before_the_timed_window_starts(): void
    {
        $result = (new TimedSegmentSweep)->evaluate(
            [new TimedSegment('ME_MEMORIZE', 180, 30, false)],
            null,
            null,
            null,
            $this->at(self::START),
            $this->at('2026-09-22T08:00:29+00:00'),
        );

        $this->assertSame(0, $result->index);
        $this->assertEquals($this->at(self::START), $result->becameCurrentAt);
        $this->assertNull($result->startedAt, 'Waiting on the reading gap is a real, observable state, not started yet.');
        $this->assertFalse($result->expired);
    }

    public function test_an_unconfirmed_reading_cap_auto_starts_the_timed_window_on_the_same_segment(): void
    {
        // The cap has elapsed (30s), but not yet the 180s timed window measured
        // from when the cap elapsed -- still segment 0, now started.
        $result = (new TimedSegmentSweep)->evaluate(
            [new TimedSegment('ME_MEMORIZE', 180, 30, false)],
            null,
            null,
            null,
            $this->at(self::START),
            $this->at('2026-09-22T08:01:00+00:00'),
        );

        $this->assertSame(0, $result->index);
        $this->assertEquals($this->at(self::START), $result->becameCurrentAt);
        $this->assertEquals($this->at('2026-09-22T08:00:30+00:00'), $result->startedAt);
        $this->assertFalse($result->expired);
    }

    public function test_a_zero_reading_cap_segment_starts_the_instant_it_becomes_current(): void
    {
        // The degenerate/zero case: reading_cap_seconds = 0 must never
        // produce a "waiting" state at all, even evaluated at the exact
        // instant the segment becomes current.
        $result = (new TimedSegmentSweep)->evaluate(
            [new TimedSegment('SE', 300, 0, false)],
            null,
            null,
            null,
            $this->at(self::START),
            $this->at(self::START),
        );

        $this->assertEquals($this->at(self::START), $result->startedAt);
    }

    /**
     * The scenario Lead required explicitly (2026-09-22): start a session,
     * let TWO segments' worth of scheduled time pass with no request in
     * between, then make ONE request and assert the resulting state
     * reflects the scheduled boundaries -- never a fresh full window
     * anchored on now().
     */
    public function test_offline_across_two_full_segments_catches_up_to_the_scheduled_third_segment(): void
    {
        $segments = [
            new TimedSegment('SE', 300, 0, false),   // 08:00:00 - 08:05:00
            new TimedSegment('WA', 600, 0, false),    // 08:05:00 - 08:15:00
            new TimedSegment('AN', 900, 0, false),    // 08:15:00 - 08:30:00
        ];

        // Participant vanishes right after segment 0 becomes current and
        // returns 20 minutes later -- deep into segment 2 (AN), having
        // never sent a single request in between.
        $result = (new TimedSegmentSweep)->evaluate(
            $segments,
            null,
            null,
            null,
            $this->at(self::START),
            $this->at('2026-09-22T08:20:00+00:00'),
        );

        $this->assertSame(2, $result->index, 'Must land on AN (index 2), not stay on SE or a stale index.');
        $this->assertEquals($this->at('2026-09-22T08:15:00+00:00'), $result->becameCurrentAt);
        $this->assertEquals(
            $this->at('2026-09-22T08:15:00+00:00'),
            $result->startedAt,
            'AN must have started at its own SCHEDULED boundary (08:15:00), never at the wall-clock time this request happened to arrive (08:20:00).',
        );
        $this->assertFalse($result->expired, 'AN still has 10 more scheduled minutes at 08:20:00.');
    }

    public function test_the_same_catch_up_from_a_persisted_mid_flight_state_also_anchors_on_schedule(): void
    {
        // Segment 1 (WA) was already persisted as current, started exactly
        // on schedule, then the participant vanished until deep into what
        // should now be segment 2 (AN).
        $segments = [
            new TimedSegment('SE', 300, 0, false),
            new TimedSegment('WA', 600, 0, false),
            new TimedSegment('AN', 900, 0, false),
        ];

        $result = (new TimedSegmentSweep)->evaluate(
            $segments,
            1,
            $this->at('2026-09-22T08:05:00+00:00'),
            $this->at('2026-09-22T08:05:00+00:00'),
            $this->at(self::START),
            $this->at('2026-09-22T08:20:00+00:00'),
        );

        $this->assertSame(2, $result->index);
        $this->assertEquals($this->at('2026-09-22T08:15:00+00:00'), $result->becameCurrentAt);
        $this->assertEquals($this->at('2026-09-22T08:15:00+00:00'), $result->startedAt);
    }

    public function test_offline_past_the_last_segment_expires_rather_than_inventing_a_further_index(): void
    {
        $segments = [
            new TimedSegment('SE', 300, 0, false),
            new TimedSegment('WA', 600, 0, false),
        ];

        $result = (new TimedSegmentSweep)->evaluate(
            $segments,
            null,
            null,
            null,
            $this->at(self::START),
            $this->at('2026-09-22T09:00:00+00:00'),
        );

        $this->assertSame(1, $result->index, 'Stays on the last real segment - there is nothing past it to advance into.');
        $this->assertTrue($result->expired);
    }

    /**
     * Lead's explicit requirement (2026-09-22): under normal conditions
     * (no early finish), the sweep's own "expired" boundary and the
     * session's ends_at (revision 1: started_at + sum(duration_seconds) +
     * sum(reading_cap_seconds)) must be exactly the same instant, so
     * AssessmentSessionDeadlinePolicy's own ends_at check and this sweep's
     * $expired flag can never disagree about whether the session is over.
     */
    public function test_expiry_boundary_matches_the_sessions_own_ends_at_with_no_early_finish(): void
    {
        $segments = [
            new TimedSegment('SE', 300, 0, false),
            new TimedSegment('ME_MEMORIZE', 180, 30, false),
            new TimedSegment('ME_ANSWER', 360, 0, false),
        ];
        $totalDurationSeconds = array_sum(array_map(static fn (TimedSegment $s): int => $s->durationSeconds, $segments));
        $totalReadingCapSeconds = array_sum(array_map(static fn (TimedSegment $s): int => $s->readingCapSeconds, $segments));
        $sessionStartedAt = $this->at(self::START);
        $endsAt = $sessionStartedAt->modify('+'.($totalDurationSeconds + $totalReadingCapSeconds).' seconds');

        $oneSecondBeforeEndsAt = (new TimedSegmentSweep)->evaluate(
            $segments, null, null, null, $sessionStartedAt, $endsAt->modify('-1 second'),
        );
        $this->assertFalse(
            $oneSecondBeforeEndsAt->expired,
            'One second before ends_at, the sweep must still consider the session live.',
        );

        $atEndsAt = (new TimedSegmentSweep)->evaluate(
            $segments, null, null, null, $sessionStartedAt, $endsAt,
        );
        $this->assertFalse(
            $atEndsAt->expired,
            'Exactly at ends_at, the sweep must agree the session is still live - the same instant AssessmentSessionDeadlinePolicy still accepts a write at (receivedAt > endsAt is the rejection, not >=).',
        );

        $oneSecondAfterEndsAt = (new TimedSegmentSweep)->evaluate(
            $segments, null, null, null, $sessionStartedAt, $endsAt->modify('+1 second'),
        );
        $this->assertTrue(
            $oneSecondAfterEndsAt->expired,
            'One second after ends_at, the sweep must agree the session is over.',
        );
    }

    public function test_it_rejects_an_empty_segment_list(): void
    {
        $this->expectException(InvalidAssessmentSessionState::class);

        (new TimedSegmentSweep)->evaluate([], null, null, null, $this->at(self::START), $this->at(self::START));
    }

    public function test_it_rejects_a_stored_index_out_of_range(): void
    {
        $this->expectException(InvalidAssessmentSessionState::class);

        (new TimedSegmentSweep)->evaluate(
            [new TimedSegment('SE', 300, 0, false)],
            5,
            $this->at(self::START),
            $this->at(self::START),
            $this->at(self::START),
            $this->at(self::START),
        );
    }

    public function test_it_rejects_a_stored_index_without_a_stored_became_current_at(): void
    {
        $this->expectException(InvalidAssessmentSessionState::class);

        (new TimedSegmentSweep)->evaluate(
            [new TimedSegment('SE', 300, 0, false)],
            0,
            null,
            null,
            $this->at(self::START),
            $this->at(self::START),
        );
    }

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time);
    }
}
