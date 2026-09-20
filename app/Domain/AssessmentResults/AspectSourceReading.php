<?php

declare(strict_types=1);

namespace App\Domain\AssessmentResults;

/**
 * F2 lane G7 (2026-09-20). One configured aspect source's reading, always
 * carrying its `instrumentCode` alongside any numeric value. This is
 * deliberate: tasks/handoffs/f2/generic-instrument-result-field-mapping.md
 * forbids comparing `standardScore` (or `band`, for RMIB) across instruments,
 * and a caller cannot destructure just the number without also holding the
 * instrument code that gives it meaning.
 *
 * `level` is the ONLY field safe to compare across instruments (the accepted
 * 18-aspect aggregation anchor, SCORING_ALGORITHM.md §8) and is the only
 * value this reader exposes beyond identity — `standardScore`, `sourceScore`,
 * `rawScore`, `category`, and `band` are per-instrument implementation detail
 * not meant for cross-instrument aggregation and are intentionally not
 * returned here.
 */
final readonly class AspectSourceReading
{
    public function __construct(
        public string $aspect,
        /** Exactly as database/seeders/data/aspect_sources.json spells it, e.g. "IST_IQ", "PAPI_R". */
        public string $configuredSource,
        public string $instrumentCode,
        public string $sourceCode,
        public AspectSourceReadingStatus $status,
        public ?int $level,
    ) {}
}
