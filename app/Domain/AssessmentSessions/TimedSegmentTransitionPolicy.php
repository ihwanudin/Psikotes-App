<?php

declare(strict_types=1);

namespace App\Domain\AssessmentSessions;

use DateTimeImmutable;

/**
 * F2 timed-segments stage 4 (2026-09-22), implementing revision 3 of
 * tasks/handoffs/f2/timed-segments-plan.md: POST /sessions/:id/subtest/next
 * has two distinct meanings depending on the CURRENT segment state (already
 * caught up to schedule by TimedSegmentSweep before this is ever called --
 * see SubtestNext):
 *
 * - Still waiting out a reading gap (current segment's started_at is null):
 *   confirms the participant has read the instructions and starts the timed
 *   window, using the real request time as started_at. Always permitted
 *   regardless of allow_early_finish -- it only shortens the untimed
 *   reading buffer, never graded/timed time, so there is nothing to gate.
 * - Inside the timed window (started_at is not null): means "end this
 *   segment now, before its timer expires." Gated by the CURRENT segment's
 *   own allow_early_finish: true transitions immediately to the next
 *   segment using the real request time as its became_current_at (a
 *   genuine early transition, not a sweep catching up -- no time is
 *   granted, time is saved); false is rejected with
 *   INVALID_SESSION_TRANSITION and no state change.
 *
 * Two states this class rejects that the plan doc does not explicitly
 * design a meaning for (documented here rather than guessed silently):
 * the swept segment is already the LAST one and its own deadline has
 * passed ($swept->expired -- nothing left to act on; the participant's own
 * next action is POST /sessions/:id/submit, not this endpoint), and an
 * early-finish request on the LAST segment (there is no next segment to
 * advance into). Both reject with INVALID_SESSION_TRANSITION.
 *
 * The whole-session deadline (status must be in_progress and the request
 * must arrive before ends_at) is checked first, via the same
 * AssessmentSessionDeadlinePolicy every other write path already uses --
 * not duplicated logic, the identical rule.
 */
final class TimedSegmentTransitionPolicy
{
    /** @param  list<TimedSegment>  $segments */
    public function decide(
        AssessmentSessionStatus $status,
        ?DateTimeImmutable $sessionEndsAt,
        DateTimeImmutable $now,
        array $segments,
        TimedSegmentSweepResult $swept,
    ): TimedSegmentTransitionDecision {
        $deadline = (new AssessmentSessionDeadlinePolicy)->evaluateAnswerWrite($status, $sessionEndsAt, $now);
        if (! $deadline->accepted) {
            return new TimedSegmentTransitionDecision($deadline->accepted, $deadline->status, $deadline->errorCode);
        }

        if ($swept->expired) {
            return $this->reject($status);
        }

        if ($swept->startedAt === null) {
            return new TimedSegmentTransitionDecision(true, $status, null, $swept->index, $swept->becameCurrentAt, $now);
        }

        $current = $segments[$swept->index]
            ?? throw new InvalidAssessmentSessionState('Timed segment transition index is out of range.');
        $nextIndex = $swept->index + 1;

        if (! $current->allowEarlyFinish || ! isset($segments[$nextIndex])) {
            return $this->reject($status);
        }

        $next = $segments[$nextIndex];
        $nextStartedAt = $next->readingCapSeconds === 0 ? $now : null;

        return new TimedSegmentTransitionDecision(true, $status, null, $nextIndex, $now, $nextStartedAt);
    }

    private function reject(AssessmentSessionStatus $status): TimedSegmentTransitionDecision
    {
        return new TimedSegmentTransitionDecision(false, $status, AssessmentSessionErrorCode::InvalidSessionTransition);
    }
}
