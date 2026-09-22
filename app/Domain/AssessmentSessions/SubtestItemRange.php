<?php

declare(strict_types=1);

namespace App\Domain\AssessmentSessions;

/**
 * F2 timed-segments stage 5 (2026-09-22). The 1-indexed, inclusive item_no
 * range a single subtest owns. See SessionDefinition::currentSubtestItemRange().
 */
final readonly class SubtestItemRange
{
    public function __construct(
        public int $start,
        public int $end,
    ) {}
}
