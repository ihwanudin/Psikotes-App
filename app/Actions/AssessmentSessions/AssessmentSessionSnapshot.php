<?php

declare(strict_types=1);

namespace App\Actions\AssessmentSessions;

use App\Domain\AssessmentSessions\AssessmentSessionStatus;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\SessionDefinition;
use DateTimeImmutable;

/**
 * F2 session-http (2026-09-21). A read-only view of a session in ANY of its
 * six states, for GET /sessions/{id}. Deliberately not
 * AssessmentSessionAllocationResult: that class's constructor hard-requires
 * status===InProgress and a handful of freshly-computed invariants
 * appropriate to a just-allocated/just-replayed result (ULID shape,
 * endsAt matching the definition's duration exactly, serverTime inside the
 * active window) -- none of which make sense for "load whatever is actually
 * stored," including terminal states. This class carries no invariants
 * beyond basic types; GetAssessmentSession is responsible for only ever
 * constructing one from a real, already-validated database row.
 *
 * `remainingSeconds` is 0 for every non-InProgress status by construction
 * (GetAssessmentSession computes it, not this class) -- API_CONTRACT.md §98:
 * "remaining_seconds tidak pernah negatif." A closed session has no time
 * left by definition, however it closed.
 */
final readonly class AssessmentSessionSnapshot
{
    /**
     * F2 timed-segments stage 5 (2026-09-22): $currentSegmentIndex/
     * $currentSegmentBecameCurrentAt/$currentSegmentStartedAt are the raw
     * test_sessions.current_segment_* columns, unswept -- AssessmentSessionResource
     * runs TimedSegmentSweep itself to derive the client-facing current_segment,
     * the same compute-only pattern GetAssessmentSession already uses for
     * remainingSeconds. All three are null for a session that has never
     * subtest/next'd (or hasn't started at all), exactly as stored.
     */
    public function __construct(
        public string $sessionId,
        public GenericAssessmentInstrument $instrument,
        public AssessmentSessionStatus $status,
        public int $attemptNo,
        public int $answersRevision,
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $endsAt,
        public ?DateTimeImmutable $submittedAt,
        public DateTimeImmutable $serverTime,
        public int $remainingSeconds,
        public SessionDefinition $definition,
        public ?int $currentSegmentIndex = null,
        public ?DateTimeImmutable $currentSegmentBecameCurrentAt = null,
        public ?DateTimeImmutable $currentSegmentStartedAt = null,
    ) {}
}
