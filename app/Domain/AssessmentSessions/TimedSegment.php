<?php

declare(strict_types=1);

namespace App\Domain\AssessmentSessions;

/**
 * F2 timed-segments stage 2 (2026-09-22), per
 * tasks/handoffs/f2/timed-segments-plan.md. The smallest server-tracked
 * timed unit within a session -- one per subtest for most instruments
 * (PAPI/RMIB/DASS21/IST's single-phase subtests), or one per phase for a
 * multi-segment subtest (IST's ME: memorize/answer) or per column
 * (Kraepelin, mapped later, not built here). Built and validated only by
 * SessionDefinition::fromArray() from already-checksummed session data --
 * this class carries no validation of its own, matching
 * AssessmentSessionSnapshot's plain-DTO style elsewhere in this namespace.
 */
final readonly class TimedSegment
{
    public function __construct(
        public string $code,
        public int $durationSeconds,
        public int $readingCapSeconds,
        public bool $allowEarlyFinish,
    ) {}
}
