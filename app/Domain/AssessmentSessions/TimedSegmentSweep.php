<?php

declare(strict_types=1);

namespace App\Domain\AssessmentSessions;

use DateTimeImmutable;

/**
 * F2 timed-segments stage 2 (2026-09-22), implementing revision 2 of
 * tasks/handoffs/f2/timed-segments-plan.md: given a session's flattened
 * segment list and whatever segment state is currently stored (which may be
 * null -- no timed-segments write has happened yet for this session), work
 * out what the CURRENT segment state actually is right now, by walking
 * forward through SCHEDULED boundaries -- never `now()` at evaluation time.
 * A participant who goes offline for an hour is caught up to wherever the
 * schedule says they should be, never granted a fresh full window.
 *
 * Pure and side-effect free: this class never reads or writes test_sessions
 * itself. Lead's explicit decision (2026-09-22): callers -- GetAssessmentSession,
 * autosave's range check, and (later) SubtestNext -- each call this fresh
 * from whatever is currently persisted, compute-only, exactly the way
 * AssessmentSessionDeadlinePolicy's whole-session remaining_seconds already
 * works. Persisting the result back to test_sessions.current_segment_* is
 * SubtestNext's job alone (it already has to lock the row for its own
 * transition) -- deliberately not this class's, and not GetAssessmentSession's
 * either. This also means enforcement never trusts a stored index that could
 * be stale: a participant can't "hold open" an old segment by simply never
 * calling subtest/next, since every read recomputes from the schedule.
 *
 * Relationship to the whole-session `ends_at` (revision 1's formula:
 * started_at + sum(duration_seconds) + sum(reading_cap_seconds), computed at
 * session start -- see AllocateAndStartAssessmentSession/StartAssessmentSession):
 * with no early finish, walking every segment's own deadline from
 * $sessionStartedAt necessarily lands on exactly $sessionStartedAt +
 * totalDurationSeconds + totalReadingCapSeconds for the LAST segment's
 * deadline -- i.e. exactly `ends_at`. So $expired here and "now() > ends_at"
 * (AssessmentSessionDeadlinePolicy's own check, unchanged) can never
 * disagree in that case; proven in TimedSegmentSweepTest::
 * test_expiry_boundary_matches_the_sessions_own_ends_at_with_no_early_finish().
 * `ends_at` stays the authoritative outer ceiling: a future early finish
 * (allow_early_finish, revision 3 -- not built yet) can only ever move a
 * segment's own transition EARLIER (it uses real now() as the next segment's
 * became_current_at, never later than the schedule it replaces), so
 * $expired here can become true before `ends_at` but never after it.
 * `ends_at` is never recomputed or shortened by an early finish.
 *
 * Boundary operator, fixed 2026-09-22 during stage 5: a segment deadline
 * (reading cap or timed window) must be checked with `now() <= deadline`,
 * INCLUSIVE, the same as AssessmentSessionDeadlinePolicy's own
 * `receivedAt > endsAt` (i.e. `receivedAt === endsAt` is still accepted).
 * An earlier exclusive `<` here meant the exact boundary instant genuinely
 * disagreed with the whole-session policy -- caught by
 * AutosaveAssessmentAnswersTest exercising a request landing exactly on a
 * segment boundary, not by TimedSegmentSweepTest's own unit tests, which
 * only checked the boundary INSTANT matched arithmetically, never the
 * operator at that instant.
 */
final class TimedSegmentSweep
{
    /**
     * @param  list<TimedSegment>  $segments  The whole session's segments, in
     *                                        order (SessionDefinition::$segments).
     * @param  int|null  $storedIndex  test_sessions.current_segment_index, or
     *                                 null if no timed-segments write has
     *                                 happened for this session yet.
     * @param  DateTimeImmutable|null  $storedBecameCurrentAt  test_sessions.
     *                                                         current_segment_became_current_at.
     * @param  DateTimeImmutable|null  $storedStartedAt  test_sessions.
     *                                                   current_segment_started_at.
     * @param  DateTimeImmutable  $sessionStartedAt  test_sessions.started_at --
     *                                               the anchor used when $storedIndex is null.
     */
    public function evaluate(
        array $segments,
        ?int $storedIndex,
        ?DateTimeImmutable $storedBecameCurrentAt,
        ?DateTimeImmutable $storedStartedAt,
        DateTimeImmutable $sessionStartedAt,
        DateTimeImmutable $now,
    ): TimedSegmentSweepResult {
        if ($segments === []) {
            throw new InvalidAssessmentSessionState('Timed segment sweep requires at least one segment.');
        }

        if ($storedIndex === null) {
            $index = 0;
            $becameCurrentAt = $sessionStartedAt;
            $startedAt = $this->startOf($segments[0], $becameCurrentAt);
        } else {
            if ($storedIndex < 0 || ! isset($segments[$storedIndex]) || $storedBecameCurrentAt === null) {
                throw new InvalidAssessmentSessionState('Persisted timed segment state is inconsistent.');
            }
            $index = $storedIndex;
            $becameCurrentAt = $storedBecameCurrentAt;
            $startedAt = $storedStartedAt;
        }

        while (true) {
            $segment = $segments[$index];

            if ($startedAt === null) {
                // Waiting out a reading gap. reading_cap_seconds = 0 never
                // reaches here: startOf() already resolved it to
                // $becameCurrentAt, so there is nothing to wait out.
                $capDeadline = $becameCurrentAt->modify("+{$segment->readingCapSeconds} seconds");
                if ($now <= $capDeadline) {
                    return new TimedSegmentSweepResult($index, $becameCurrentAt, null, false);
                }

                // The cap elapsed without confirmation -- the timed window
                // starts automatically, same segment, same became_current_at.
                $startedAt = $capDeadline;

                continue;
            }

            $timedDeadline = $startedAt->modify("+{$segment->durationSeconds} seconds");
            if ($now <= $timedDeadline) {
                return new TimedSegmentSweepResult($index, $becameCurrentAt, $startedAt, false);
            }

            $nextIndex = $index + 1;
            if (! isset($segments[$nextIndex])) {
                return new TimedSegmentSweepResult($index, $becameCurrentAt, $startedAt, true);
            }

            $becameCurrentAt = $timedDeadline;
            $index = $nextIndex;
            $startedAt = $this->startOf($segments[$index], $becameCurrentAt);
        }
    }

    private function startOf(TimedSegment $segment, DateTimeImmutable $becameCurrentAt): ?DateTimeImmutable
    {
        return $segment->readingCapSeconds === 0 ? $becameCurrentAt : null;
    }
}
