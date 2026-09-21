<?php

declare(strict_types=1);

namespace App\Domain\Eligibility;

use InvalidArgumentException;

final class AspectSourceDiscrepancyPolicy
{
    /**
     * G7-data-gap (2026-09-21): historically evaluate() received $sources
     * purely from the client with no cross-check against
     * generic_instrument_result_sources, so a client could fake a per-source
     * level and make review_required false when it should be true, hiding a
     * G7 warning signal from the psychologist. This method itself still
     * cannot see the database and still trusts whatever `sources` it is
     * given -- that has not changed. What changed (F2, Phase 1,
     * 2026-09-21): App\Services\Eligibility\LoadLedgerAspectDiscrepancy now
     * exists and computes `sources` from generic_instrument_result_sources
     * instead of accepting them from a caller, and `level: null` here (this
     * method) represents a configured source the ledger does not (yet) have
     * a Found reading for -- see the `null` handling below. This method is
     * still directly reachable with caller-supplied sources (ReportSigning's
     * live UI estimate, the psychologist-review demo fixture) and that
     * remains fine for those non-authoritative uses. It does NOT close the
     * gap for report signing: ReportSigningService.php:112 still evaluates
     * whatever the client submits, unchanged, until Phase 2 (blocked on the
     * F5 branch merging) swaps that call site to the ledger-backed
     * aggregator. See tasks/handoffs/f2/g7-server-side-sources.md.
     */

    /** @var list<string> */
    private const ASPECTS = ['A1', 'A2', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'D1', 'D2', 'D3', 'D4', 'D5'];

    /**
     * @param  array<mixed>  $payload
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
    public function evaluate(array $payload): array
    {
        if (! $this->hasExactKeys($payload, ['aspect', 'sources'])
            || ! is_string($payload['aspect'])
            || ! in_array($payload['aspect'], self::ASPECTS, true)
            || ! is_array($payload['sources'])
            || ! array_is_list($payload['sources'])
            || count($payload['sources']) < 1) {
            throw new InvalidArgumentException('Aspect source discrepancy input is invalid.');
        }

        $sources = [];
        $sourceNames = [];
        foreach ($payload['sources'] as $source) {
            if (! is_array($source)
                || ! $this->hasExactKeys($source, ['source', 'level'])
                || ! is_string($source['source'])
                || trim($source['source']) === ''
                || $source['source'] !== trim($source['source'])
                || ($source['level'] !== null && ! is_int($source['level']))
                || (is_int($source['level']) && ($source['level'] < 1 || $source['level'] > 5))
                || array_key_exists($source['source'], $sourceNames)) {
                throw new InvalidArgumentException('Aspect source discrepancy source is invalid.');
            }

            $sourceNames[$source['source']] = true;
            $sources[] = [
                'source' => $source['source'],
                'level' => $source['level'],
            ];
        }

        usort($sources, static fn (array $left, array $right): int => strcmp($left['source'], $right['source']));

        $levels = array_column($sources, 'level');
        $knownLevels = array_values(array_filter($levels, static fn (mixed $level): bool => $level !== null));
        $incomplete = count($knownLevels) < count($levels);

        if ($incomplete) {
            // A summary computed from a partial set is more dangerous than no
            // summary at all: `spread: 1` reads as "the sources nearly agree"
            // even when one configured source has no reading yet. Every KNOWN
            // level still appears individually in provenance.sources -- only
            // the summary numbers are withheld, forcing any downstream reader
            // to notice `reason_code` instead of trusting a partial spread.
            return [
                'type' => 'aspect_source_discrepancy',
                'review_required' => true,
                'automatic_narrative_allowed' => false,
                'reason_code' => 'SOURCE_INCOMPLETE',
                'provenance' => [
                    'aspect' => $payload['aspect'],
                    'sources' => $sources,
                    'minimum_level' => null,
                    'maximum_level' => null,
                    'spread' => null,
                ],
            ];
        }

        if ($knownLevels === []) {
            // Unreachable: sources is non-empty (checked above) and this
            // branch only runs when none of them are null, so $knownLevels
            // has the same count as $sources. Checked explicitly rather than
            // assumed, so a future change to the emptiness invariant fails
            // loud instead of calling min()/max() on nothing.
            throw new InvalidArgumentException('Aspect source discrepancy source is invalid.');
        }
        $minimum = min($knownLevels);
        $maximum = max($knownLevels);
        $spread = $maximum - $minimum;
        $reviewRequired = $spread >= 2;

        return [
            'type' => 'aspect_source_discrepancy',
            'review_required' => $reviewRequired,
            'automatic_narrative_allowed' => ! $reviewRequired,
            'reason_code' => $reviewRequired ? 'SOURCE_LEVEL_SPREAD' : null,
            'provenance' => [
                'aspect' => $payload['aspect'],
                'sources' => $sources,
                'minimum_level' => $minimum,
                'maximum_level' => $maximum,
                'spread' => $spread,
            ],
        ];
    }

    /**
     * @param  array<mixed>  $value
     * @param  list<string>  $expectedKeys
     */
    private function hasExactKeys(array $value, array $expectedKeys): bool
    {
        $actualKeys = array_keys($value);
        sort($actualKeys);
        sort($expectedKeys);

        return $actualKeys === $expectedKeys;
    }
}
