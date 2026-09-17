<?php

declare(strict_types=1);

namespace App\Domain\Narrative;

use InvalidArgumentException;

/**
 * @phpstan-type ChildNarrative array{
 *     type: 'cluster_narrative',
 *     language: 'id'|'jp',
 *     cluster: 'A'|'B'|'C'|'D',
 *     narrative: string,
 *     review_required: bool,
 *     omitted_aspects: list<array{aspect: string, level: int, position: int, provenance_key: string}>,
 *     provenance_keys: list<string>,
 *     connector_sequence: list<string>
 * }
 * @phpstan-type BilingualCluster array{id: ChildNarrative, jp: ChildNarrative}
 */
final readonly class BilingualClusterNarrativeComposer
{
    /** @var list<string> */
    private const ASPECTS = [
        'A1', 'A2',
        'B1', 'B2', 'B3', 'B4',
        'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7',
        'D1', 'D2', 'D3', 'D4', 'D5',
    ];

    private ClusterNarrativeAssembler $indonesianAssembler;

    private JapaneseClusterNarrativeAssembler $japaneseAssembler;

    public function __construct(ReportingNarrativeCatalog $catalog)
    {
        $inputs = $catalog->assemblerInputs();
        $this->indonesianAssembler = new ClusterNarrativeAssembler(
            $inputs['narrative_bank'],
            $inputs['connector_pools'],
        );
        $this->japaneseAssembler = new JapaneseClusterNarrativeAssembler($catalog->japaneseBank());
    }

    /**
     * @param  array<mixed>  $input
     * @return array{
     *     type: 'bilingual_cluster_narratives',
     *     clusters: array{A: BilingualCluster, B: BilingualCluster, C: BilingualCluster, D: BilingualCluster},
     *     review_required: bool,
     *     omitted_aspects: list<array{
     *         aspect: string,
     *         level: int,
     *         position: int,
     *         provenance: array{id: string, jp: string}
     *     }>
     * }
     */
    public function compose(array $input): array
    {
        if (array_keys($input) !== ['aspects'] || ! is_array($input['aspects'])) {
            throw new InvalidArgumentException('Bilingual narrative input must contain only an aspects list.');
        }

        $aspects = $this->validateAspects($input['aspects']);
        $partitioned = ['A' => [], 'B' => [], 'C' => [], 'D' => []];
        foreach ($aspects as $aspect) {
            $partitioned[$aspect['aspect'][0]][] = $aspect;
        }

        $clusters = [
            'A' => $this->composeCluster('A', $partitioned['A']),
            'B' => $this->composeCluster('B', $partitioned['B']),
            'C' => $this->composeCluster('C', $partitioned['C']),
            'D' => $this->composeCluster('D', $partitioned['D']),
        ];

        $omitted = [];
        foreach ($clusters as $cluster) {
            foreach ($cluster['id']['omitted_aspects'] as $index => $idOmitted) {
                $jpOmitted = $cluster['jp']['omitted_aspects'][$index];
                $position = array_search($idOmitted['aspect'], self::ASPECTS, true);
                if ($position === false
                    || $jpOmitted['aspect'] !== $idOmitted['aspect']
                    || $jpOmitted['level'] !== $idOmitted['level']) {
                    throw new InvalidArgumentException('Bilingual child narrative provenance is inconsistent.');
                }

                $omitted[] = [
                    'aspect' => $idOmitted['aspect'],
                    'level' => $idOmitted['level'],
                    'position' => $position + 1,
                    'provenance' => [
                        'id' => $idOmitted['provenance_key'],
                        'jp' => $jpOmitted['provenance_key'],
                    ],
                ];
            }
        }

        return [
            'type' => 'bilingual_cluster_narratives',
            'clusters' => $clusters,
            'review_required' => $omitted !== [],
            'omitted_aspects' => $omitted,
        ];
    }

    /**
     * @param  array<mixed>  $aspects
     * @return list<array{aspect: string, level: int, review_required: bool}>
     */
    private function validateAspects(array $aspects): array
    {
        if (! array_is_list($aspects) || count($aspects) !== count(self::ASPECTS)) {
            throw new InvalidArgumentException('Bilingual narrative requires all canonical aspects exactly once.');
        }

        $validated = [];
        $seen = [];
        foreach ($aspects as $position => $item) {
            if (! is_array($item) || ! $this->hasExactKeys($item, ['aspect', 'level', 'review_required'])) {
                throw new InvalidArgumentException('Bilingual narrative aspect shape is invalid.');
            }

            $aspect = $item['aspect'];
            $level = $item['level'];
            $reviewRequired = $item['review_required'];
            if (! is_string($aspect) || ! in_array($aspect, self::ASPECTS, true)) {
                throw new InvalidArgumentException('Bilingual narrative aspect is unknown.');
            }
            if (isset($seen[$aspect])) {
                throw new InvalidArgumentException('Bilingual narrative aspects must not contain duplicates.');
            }
            if ($aspect !== self::ASPECTS[$position]) {
                throw new InvalidArgumentException('Bilingual narrative aspects must use canonical order.');
            }
            if (! is_int($level) || $level < 1 || $level > 5) {
                throw new InvalidArgumentException('Bilingual narrative level must be an integer from one through five.');
            }
            if (! is_bool($reviewRequired)) {
                throw new InvalidArgumentException('Bilingual narrative review flag must be boolean.');
            }

            $seen[$aspect] = true;
            $validated[] = [
                'aspect' => $aspect,
                'level' => $level,
                'review_required' => $reviewRequired,
            ];
        }

        return $validated;
    }

    /**
     * @param  'A'|'B'|'C'|'D'  $cluster
     * @param  list<array{aspect: string, level: int, review_required: bool}>  $aspects
     * @return BilingualCluster
     */
    private function composeCluster(string $cluster, array $aspects): array
    {
        return [
            'id' => $this->indonesianAssembler->assemble($cluster, $aspects),
            'jp' => $this->japaneseAssembler->assemble($cluster, $aspects),
        ];
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
