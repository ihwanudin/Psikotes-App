<?php

declare(strict_types=1);

namespace App\Domain\Eligibility;

use InvalidArgumentException;

final class AspectSourceDiscrepancyPolicy
{
    /**
     * TODO(G7-data-gap): evaluate() menerima $sources murni dari klien tanpa
     * cross-check ke generic_instrument_result_sources di database. Klien secara
     * teknis bisa memalsukan level per-instrumen sehingga review_required menjadi
     * false padahal sebenarnya true, menyembunyikan sinyal peringatan G7 dari
     * psikolog. Ini TIDAK bisa mengubah validity/label/final_level yang
     * ditandatangani (sudah terkunci oleh ReviewedEligibilityDecision di layer
     * atasnya). Perlu aggregator dari generic_instrument_result_sources sebelum
     * sources dianggap otoritatif.
     */

    /** @var list<string> */
    private const ASPECTS = ['A1', 'A2', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'D1', 'D2', 'D3', 'D4', 'D5'];

    /**
     * @param  array<mixed>  $payload
     * @return array{
     *     type: 'aspect_source_discrepancy',
     *     review_required: bool,
     *     automatic_narrative_allowed: bool,
     *     reason_code: 'SOURCE_LEVEL_SPREAD'|null,
     *     provenance: array{
     *         aspect: string,
     *         sources: list<array{source: string, level: int}>,
     *         minimum_level: int,
     *         maximum_level: int,
     *         spread: int
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
                || ! is_int($source['level'])
                || $source['level'] < 1
                || $source['level'] > 5
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
        $minimum = min($levels);
        $maximum = max($levels);
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
