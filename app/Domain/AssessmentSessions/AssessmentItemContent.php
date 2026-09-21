<?php

declare(strict_types=1);

namespace App\Domain\AssessmentSessions;

/**
 * Stage 1 (2026-09-21): structural envelope only. Per-instrument item shape
 * (which fields belong in each $items entry -- text, options, image
 * references) is deliberately not fixed here: it depends on the "Soal"
 * extraction session's file formats, which are not final yet (Lead
 * sign-off, 2026-09-21 plan approval). Whatever a reader puts in an item's
 * array MUST already be whitelisted by that reader -- this class carries
 * whatever a reader hands it, it does not itself filter or validate field
 * content.
 */
final readonly class AssessmentItemContent
{
    /**
     * Each reader defines its own subtest shape (always a `code` and
     * `items` list; extra keys such as `answer_type`/`instructions` are up
     * to the reader) -- see this class's own doc comment above.
     *
     * @param  list<array<string, mixed>>  $subtests
     */
    public function __construct(
        public GenericAssessmentInstrument $instrument,
        public string $version,
        public array $subtests,
    ) {}
}
