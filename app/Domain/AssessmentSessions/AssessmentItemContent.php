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
 *
 * $instructions (Stage 2 continuation, 2026-09-21): optional, per-instrument
 * administration text (e.g. PAPI's intro/example/answer_sheet_demo/closing)
 * so a single server-side source of truth covers both the questions and the
 * text that explains how to answer them -- a client never hardcodes this
 * text statically. Null for instruments/readers that don't have any (most
 * of them, today). Same whitelisting responsibility as $subtests: whatever
 * shape a reader puts here, that reader already validated it.
 *
 * $resolvedVariant (RMIB gender-track selection, 2026-09-21): set only when
 * AssessmentItemContentAuthority::contentFor() was called with
 * $lockedVariant === null (the one-time fresh-resolution call at
 * allocation) and the reader picked a variant -- the caller persists this
 * value and passes it back as $lockedVariant on every later call for the
 * same session. Null whenever $lockedVariant was already given, and null
 * for every reader without a variant axis. Never participant identity
 * itself (e.g. never a name or ID), only an opaque per-reader selector
 * (e.g. `'male'`/`'female'` for RMIB).
 */
final readonly class AssessmentItemContent
{
    /**
     * Each reader defines its own subtest shape (always a `code` and
     * `items` list; extra keys such as `answer_type`/`instructions` are up
     * to the reader) -- see this class's own doc comment above.
     *
     * @param  list<array<string, mixed>>  $subtests
     * @param  array<string, mixed>|null  $instructions
     */
    public function __construct(
        public GenericAssessmentInstrument $instrument,
        public string $version,
        public array $subtests,
        public ?array $instructions = null,
        public ?string $resolvedVariant = null,
    ) {}
}
