<?php

declare(strict_types=1);

namespace App\Domain\Narrative;

use InvalidArgumentException;

final readonly class ClusterNarrativeAssembler
{
    /** @var array<string, list<string>> */
    private const CLUSTER_ASPECTS = [
        'A' => ['A1', 'A2'],
        'B' => ['B1', 'B2', 'B3', 'B4'],
        'C' => ['C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7'],
        'D' => ['D1', 'D2', 'D3', 'D4', 'D5'],
    ];

    /** @var array<string, array{key: string, aspect: string, level: int, text: string}> */
    private array $bankByPair;

    /** @var array{additive: list<array{key: string, text: string}>, contrast: list<array{key: string, text: string}>} */
    private array $connectorPools;

    /**
     * @param  array<mixed>  $narrativeBank
     * @param  array<mixed>  $connectorPools
     */
    public function __construct(array $narrativeBank, array $connectorPools)
    {
        $this->bankByPair = $this->validateBank($narrativeBank);
        $this->connectorPools = $this->validateConnectorPools($connectorPools);
    }

    /**
     * @param  array<mixed>  $aspects
     * @return array{
     *     type: 'cluster_narrative',
     *     language: 'id',
     *     cluster: 'A'|'B'|'C'|'D',
     *     narrative: string,
     *     review_required: bool,
     *     omitted_aspects: list<array{aspect: string, level: int, position: int, provenance_key: string}>,
     *     provenance_keys: list<string>,
     *     connector_sequence: list<string>
     * }
     */
    public function assemble(string $cluster, array $aspects): array
    {
        if (! array_key_exists($cluster, self::CLUSTER_ASPECTS)) {
            throw new InvalidArgumentException('Narrative cluster is unknown.');
        }

        $validatedAspects = $this->validateAspects($cluster, $aspects);

        $included = [];
        $omitted = [];

        foreach ($validatedAspects as $position => $assessment) {
            $pair = $this->pairKey($assessment['aspect'], $assessment['level']);
            if (! array_key_exists($pair, $this->bankByPair)) {
                throw new InvalidArgumentException('Narrative bank is missing the requested aspect and level.');
            }

            $entry = $this->bankByPair[$pair];
            if ($assessment['review_required']) {
                $omitted[] = [
                    'aspect' => $assessment['aspect'],
                    'level' => $assessment['level'],
                    'position' => $position + 1,
                    'provenance_key' => $entry['key'],
                ];

                continue;
            }

            $included[] = [
                'aspect' => $assessment['aspect'],
                'level' => $assessment['level'],
                'key' => $entry['key'],
                'text' => $entry['text'],
            ];
        }

        $parts = [];
        $provenanceKeys = [];
        $connectorSequence = [];
        $previousConnectorKey = null;
        $previousLevel = null;

        foreach ($included as $index => $entry) {
            if ($index > 0 && $previousLevel !== null) {
                $kind = $entry['level'] >= $previousLevel ? 'additive' : 'contrast';
                $connector = $this->connectorForTransition(
                    $this->connectorPools[$kind],
                    $index - 1,
                    $previousConnectorKey,
                );
                $parts[] = $connector['text'];
                $connectorSequence[] = $connector['key'];
                $previousConnectorKey = $connector['key'];
            }

            $parts[] = $entry['text'];
            $provenanceKeys[] = $entry['key'];
            $previousLevel = $entry['level'];
        }

        /** @var 'A'|'B'|'C'|'D' $cluster */
        return [
            'type' => 'cluster_narrative',
            'language' => 'id',
            'cluster' => $cluster,
            'narrative' => implode(' ', $parts),
            'review_required' => $omitted !== [],
            'omitted_aspects' => $omitted,
            'provenance_keys' => $provenanceKeys,
            'connector_sequence' => $connectorSequence,
        ];
    }

    /**
     * @param  array<mixed>  $aspects
     * @return list<array{aspect: string, level: int, review_required: bool}>
     */
    private function validateAspects(string $cluster, array $aspects): array
    {
        if (! array_is_list($aspects) || $aspects === []) {
            throw new InvalidArgumentException('Narrative aspects must be a non-empty list.');
        }

        $validated = [];
        $seen = [];

        foreach ($aspects as $item) {
            if (! is_array($item) || ! $this->hasExactKeys($item, ['aspect', 'level', 'review_required'])) {
                throw new InvalidArgumentException('Narrative aspect shape is invalid.');
            }

            $aspect = $item['aspect'];
            $level = $item['level'];
            $reviewRequired = $item['review_required'];

            if (! is_string($aspect) || ! in_array($aspect, self::CLUSTER_ASPECTS[$cluster], true)) {
                throw new InvalidArgumentException('Narrative aspect is unknown or belongs to another cluster.');
            }
            if (! is_int($level) || $level < 1 || $level > 5) {
                throw new InvalidArgumentException('Narrative level must be an integer from one through five.');
            }
            if (! is_bool($reviewRequired)) {
                throw new InvalidArgumentException('Narrative review flag must be boolean.');
            }
            if (isset($seen[$aspect])) {
                throw new InvalidArgumentException('Narrative aspects must not contain duplicates.');
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
     * @param  array<mixed>  $bank
     * @return array<string, array{key: string, aspect: string, level: int, text: string}>
     */
    private function validateBank(array $bank): array
    {
        if (! array_is_list($bank)) {
            throw new InvalidArgumentException('Narrative bank must be a list.');
        }

        $knownAspects = array_merge(...array_values(self::CLUSTER_ASPECTS));
        $byPair = [];
        $seenKeys = [];

        foreach ($bank as $entry) {
            if (! is_array($entry) || ! $this->hasExactKeys($entry, ['key', 'aspect', 'level', 'text'])) {
                throw new InvalidArgumentException('Narrative bank entry shape is invalid.');
            }

            $key = $entry['key'];
            $aspect = $entry['aspect'];
            $level = $entry['level'];
            $text = $entry['text'];

            if (! $this->isNonEmptyTrimmedString($key) || ! $this->isNonEmptyTrimmedString($text)) {
                throw new InvalidArgumentException('Narrative bank key and text must be non-empty trimmed strings.');
            }
            if (! is_string($aspect) || ! in_array($aspect, $knownAspects, true)) {
                throw new InvalidArgumentException('Narrative bank aspect is unknown.');
            }
            if (! is_int($level) || $level < 1 || $level > 5) {
                throw new InvalidArgumentException('Narrative bank level must be an integer from one through five.');
            }

            $pair = $this->pairKey($aspect, $level);
            if (isset($byPair[$pair]) || isset($seenKeys[$key])) {
                throw new InvalidArgumentException('Narrative bank keys and aspect-level pairs must be unique.');
            }

            $seenKeys[$key] = true;
            $byPair[$pair] = [
                'key' => $key,
                'aspect' => $aspect,
                'level' => $level,
                'text' => $text,
            ];
        }

        return $byPair;
    }

    /**
     * @param  array<mixed>  $connectorPools
     * @return array{additive: list<array{key: string, text: string}>, contrast: list<array{key: string, text: string}>}
     */
    private function validateConnectorPools(array $connectorPools): array
    {
        if (! $this->hasExactKeys($connectorPools, ['additive', 'contrast'])) {
            throw new InvalidArgumentException('Narrative connector configuration is invalid.');
        }

        $validated = ['additive' => [], 'contrast' => []];
        $seenKeys = [];
        $seenTexts = [];

        foreach (['additive', 'contrast'] as $kind) {
            $pool = $connectorPools[$kind];
            if (! is_array($pool) || ! array_is_list($pool)) {
                throw new InvalidArgumentException('Narrative connector pool must be a list.');
            }

            foreach ($pool as $entry) {
                if (! is_array($entry) || ! $this->hasExactKeys($entry, ['key', 'text'])) {
                    throw new InvalidArgumentException('Narrative connector entry shape is invalid.');
                }

                $key = $entry['key'];
                $text = $entry['text'];
                if (! $this->isNonEmptyTrimmedString($key) || ! $this->isNonEmptyTrimmedString($text)) {
                    throw new InvalidArgumentException('Narrative connector key and text must be non-empty trimmed strings.');
                }
                if (isset($seenKeys[$key]) || isset($seenTexts[$text])) {
                    throw new InvalidArgumentException('Narrative connector keys and texts must be unique.');
                }

                $seenKeys[$key] = true;
                $seenTexts[$text] = true;
                $validated[$kind][] = ['key' => $key, 'text' => $text];
            }
        }

        return $validated;
    }

    /**
     * @param  list<array{key: string, text: string}>  $pool
     * @return array{key: string, text: string}
     */
    private function connectorForTransition(array $pool, int $transitionPosition, ?string $previousKey): array
    {
        if ($pool === []) {
            throw new InvalidArgumentException('Narrative connector pool required by the level transition is empty.');
        }

        $count = count($pool);
        for ($offset = 0; $offset < $count; $offset++) {
            $candidate = $pool[($transitionPosition + $offset) % $count];
            if ($candidate['key'] !== $previousKey) {
                return $candidate;
            }
        }

        throw new InvalidArgumentException('Narrative connector pool cannot avoid an immediate repetition.');
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

    private function isNonEmptyTrimmedString(mixed $value): bool
    {
        return is_string($value) && $value !== '' && trim($value) === $value;
    }

    private function pairKey(string $aspect, int $level): string
    {
        return $aspect.'|'.$level;
    }
}
