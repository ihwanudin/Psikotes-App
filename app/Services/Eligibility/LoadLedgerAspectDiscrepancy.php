<?php

declare(strict_types=1);

namespace App\Services\Eligibility;

use App\Domain\AssessmentResults\AspectSourceReadingStatus;
use App\Domain\Eligibility\AspectSourceDiscrepancyPolicy;
use App\Services\AssessmentResults\LoadGenericInstrumentResultSourcesForAspect;

/**
 * F2 G7-server-side-sources, Phase 1 (2026-09-21). The server-authoritative
 * replacement for feeding client-supplied `sources` into
 * AspectSourceDiscrepancyPolicy::evaluate() -- see that class's docblock and
 * tasks/handoffs/f2/g7-server-side-sources.md for the full history. This
 * class takes no `sources` parameter at all: it derives them from
 * generic_instrument_result_sources via LoadGenericInstrumentResultSourcesForAspect,
 * so there is nothing for a caller to forge.
 *
 * Every AspectSourceReadingStatus other than Found (NotFound, Ambiguous,
 * SourceMissingFromResult) maps to `level: null` uniformly -- none of them
 * produce a trustworthy level, and the policy's null handling already fails
 * closed (review_required=true, reason_code=SOURCE_INCOMPLETE) for any null.
 * This includes Kraepelin today: no code grades raw Kraepelin answers yet
 * (see the field-mapping handoff doc), so every Kraepelin-sourced aspect
 * reads NotFound for every case and is correctly, honestly flagged
 * SOURCE_INCOMPLETE rather than silently satisfied or skipped. No
 * Kraepelin-specific branch exists here on purpose.
 *
 * Requires an already-open service RLS context, exactly like the reader it
 * wraps (LoadGenericInstrumentResultSourcesForAspect::execute() throws
 * LogicException otherwise) -- this class does not open its own transaction,
 * so it cannot stack a new context boundary on top of an existing one.
 */
final readonly class LoadLedgerAspectDiscrepancy
{
    public function __construct(
        private LoadGenericInstrumentResultSourcesForAspect $reader,
        private AspectSourceDiscrepancyPolicy $policy,
    ) {}

    /**
     * @return array{
     *     type: 'aspect_source_discrepancy',
     *     review_required: bool,
     *     automatic_narrative_allowed: bool,
     *     reason_code: 'SOURCE_LEVEL_SPREAD'|'SOURCE_INCOMPLETE'|null,
     *     provenance: array{
     *         aspect: string,
     *         sources: list<array{source: string, level: int|null}>,
     *         minimum_level: int|null,
     *         maximum_level: int|null,
     *         spread: int|null
     *     }
     * }
     */
    public function execute(int $assessmentCaseId, string $aspect): array
    {
        $readings = $this->reader->execute($assessmentCaseId, $aspect);

        $sources = array_map(
            static fn ($reading): array => [
                'source' => $reading->configuredSource,
                'level' => $reading->status === AspectSourceReadingStatus::Found ? $reading->level : null,
            ],
            $readings,
        );

        return $this->policy->evaluate([
            'aspect' => $aspect,
            'sources' => $sources,
        ]);
    }
}
