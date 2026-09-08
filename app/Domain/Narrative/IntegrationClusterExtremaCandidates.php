<?php

declare(strict_types=1);

namespace App\Domain\Narrative;

use InvalidArgumentException;

/**
 * @phpstan-type ExtremaCandidate array{aspect: string, level: int, source_position: int}
 */
final readonly class IntegrationClusterExtremaCandidates
{
    /** @var array{B: list<string>, C: list<string>} */
    private const CLUSTER_ASPECTS = [
        'B' => ['B1', 'B2', 'B3', 'B4'],
        'C' => ['C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7'],
    ];

    /**
     * @param  array<mixed>  $aspects
     * @return array{
     *     type: 'integration_cluster_extrema_candidates',
     *     cluster: 'B'|'C',
     *     highest_level: int,
     *     highest_candidates: list<ExtremaCandidate>,
     *     lowest_level: int,
     *     lowest_candidates: list<ExtremaCandidate>,
     *     review_required: bool,
     *     omitted_aspects: list<ExtremaCandidate>
     * }
     */
    public function candidates(string $cluster, array $aspects): array
    {
        $canonicalAspects = $this->canonicalAspects($cluster);
        $validated = $this->validateAspects($aspects, $canonicalAspects);
        $included = [];
        $omitted = [];
        $highestLevel = null;
        $lowestLevel = null;

        foreach ($validated as $position => $item) {
            $candidate = [
                'aspect' => $item['aspect'],
                'level' => $item['level'],
                'source_position' => $position + 1,
            ];

            if ($item['review_required']) {
                $omitted[] = $candidate;

                continue;
            }

            $included[] = $candidate;
            $highestLevel = $highestLevel === null ? $item['level'] : max($highestLevel, $item['level']);
            $lowestLevel = $lowestLevel === null ? $item['level'] : min($lowestLevel, $item['level']);
        }

        if ($highestLevel === null || $lowestLevel === null) {
            throw new InvalidArgumentException('Integration cluster has no resolved extrema authority.');
        }

        $highestCandidates = [];
        $lowestCandidates = [];
        foreach ($included as $candidate) {
            if ($candidate['level'] === $highestLevel) {
                $highestCandidates[] = $candidate;
            }
            if ($candidate['level'] === $lowestLevel) {
                $lowestCandidates[] = $candidate;
            }
        }

        /** @var 'B'|'C' $cluster */
        return [
            'type' => 'integration_cluster_extrema_candidates',
            'cluster' => $cluster,
            'highest_level' => $highestLevel,
            'highest_candidates' => $highestCandidates,
            'lowest_level' => $lowestLevel,
            'lowest_candidates' => $lowestCandidates,
            'review_required' => $omitted !== [],
            'omitted_aspects' => $omitted,
        ];
    }

    /** @return list<string> */
    private function canonicalAspects(string $cluster): array
    {
        if (! array_key_exists($cluster, self::CLUSTER_ASPECTS)) {
            throw new InvalidArgumentException('Integration extrema candidates support only clusters B and C.');
        }

        return self::CLUSTER_ASPECTS[$cluster];
    }

    /**
     * @param  array<mixed>  $aspects
     * @param  list<string>  $canonicalAspects
     * @return list<array{aspect: string, level: int, review_required: bool}>
     */
    private function validateAspects(array $aspects, array $canonicalAspects): array
    {
        if (! array_is_list($aspects) || count($aspects) !== count($canonicalAspects)) {
            throw new InvalidArgumentException('Integration extrema candidates require every cluster aspect exactly once.');
        }

        $validated = [];
        foreach ($aspects as $position => $item) {
            if (! is_array($item) || ! $this->hasExactKeys($item, ['aspect', 'level', 'review_required'])) {
                throw new InvalidArgumentException('Integration cluster aspect shape is invalid.');
            }

            $aspect = $item['aspect'];
            $level = $item['level'];
            $reviewRequired = $item['review_required'];
            if (! is_string($aspect) || $aspect !== $canonicalAspects[$position]) {
                throw new InvalidArgumentException('Integration cluster aspects must use canonical order.');
            }
            if (! is_int($level) || $level < 1 || $level > 5) {
                throw new InvalidArgumentException('Integration cluster aspect level is invalid.');
            }
            if (! is_bool($reviewRequired)) {
                throw new InvalidArgumentException('Integration cluster aspect review flag must be boolean.');
            }

            $validated[] = [
                'aspect' => $aspect,
                'level' => $level,
                'review_required' => $reviewRequired,
            ];
        }

        return $validated;
    }

    /**
     * @param  array<mixed>  $value
     * @param  list<string>  $expected
     */
    private function hasExactKeys(array $value, array $expected): bool
    {
        $keys = array_keys($value);
        sort($keys);
        sort($expected);

        return $keys === $expected;
    }
}
