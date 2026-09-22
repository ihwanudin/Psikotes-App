<?php

declare(strict_types=1);

namespace App\Domain\AssessmentSessions;

use DateTimeImmutable;

/**
 * F2 timed-segments stage 4 (2026-09-22). What TimedSegmentTransitionPolicy::
 * decide() resolves a POST /sessions/:id/subtest/next request to. When
 * $accepted is true, $index/$becameCurrentAt/$startedAt are the new
 * current-segment state to persist (SubtestNext's job, inside its own
 * locked transaction -- this class only decides, it never writes).
 */
final readonly class TimedSegmentTransitionDecision
{
    public function __construct(
        public bool $accepted,
        public AssessmentSessionStatus $status,
        public ?AssessmentSessionErrorCode $errorCode,
        public ?int $index = null,
        public ?DateTimeImmutable $becameCurrentAt = null,
        public ?DateTimeImmutable $startedAt = null,
    ) {}
}
