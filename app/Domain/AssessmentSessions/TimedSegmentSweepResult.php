<?php

declare(strict_types=1);

namespace App\Domain\AssessmentSessions;

use DateTimeImmutable;

/**
 * F2 timed-segments stage 2 (2026-09-22). What TimedSegmentSweep::evaluate()
 * resolves the current segment state to be, evaluated against a scheduled
 * boundary -- never against wall-clock elapsed time. $startedAt is null
 * while waiting out a reading gap (a real, observable state -- see
 * tasks/handoffs/f2/timed-segments-plan.md's response-shape section, not an
 * error). $expired means no next segment exists past the current one's
 * deadline: the session itself should transition to Expired, the same
 * whole-session path AssessmentSessionDeadlinePolicy already drives.
 */
final readonly class TimedSegmentSweepResult
{
    public function __construct(
        public int $index,
        public DateTimeImmutable $becameCurrentAt,
        public ?DateTimeImmutable $startedAt,
        public bool $expired,
    ) {}
}
