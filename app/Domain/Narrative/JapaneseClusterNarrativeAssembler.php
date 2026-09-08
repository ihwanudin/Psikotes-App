<?php

declare(strict_types=1);

namespace App\Domain\Narrative;

use InvalidArgumentException;

final readonly class JapaneseClusterNarrativeAssembler
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

    /** @param  array<mixed>  $narrativeBank */
    public function __construct(array $narrativeBank)
    {
        $this->bankByPair = $this->validateBank($narrativeBank);
    }

    /**
     * @param  array<mixed>  $aspects
     * @return array{
     *     type: 'cluster_narrative',
     *     language: 'jp',
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
            throw new InvalidArgumentException('Japanese narrative cluster is unknown.');
        }

        $validatedAspects = $this->validateAspects($cluster, $aspects);
        $sentences = [];
        $provenanceKeys = [];
        $omitted = [];

        foreach ($validatedAspects as $position => $assessment) {
            $pair = $this->pairKey($assessment['aspect'], $assessment['level']);
            if (! array_key_exists($pair, $this->bankByPair)) {
                throw new InvalidArgumentException('Japanese narrative bank is missing the requested aspect and level.');
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

            $sentences[] = $entry['text'];
            $provenanceKeys[] = $entry['key'];
        }

        /** @var 'A'|'B'|'C'|'D' $cluster */
        return [
            'type' => 'cluster_narrative',
            'language' => 'jp',
            'cluster' => $cluster,
            'narrative' => implode(' ', $sentences),
            'review_required' => $omitted !== [],
            'omitted_aspects' => $omitted,
            'provenance_keys' => $provenanceKeys,
            'connector_sequence' => [],
        ];
    }

    /**
     * @param  array<mixed>  $aspects
     * @return list<array{aspect: string, level: int, review_required: bool}>
     */
    private function validateAspects(string $cluster, array $aspects): array
    {
        if (! array_is_list($aspects) || $aspects === []) {
            throw new InvalidArgumentException('Japanese narrative aspects must be a non-empty list.');
        }

        $validated = [];
        $seen = [];

        foreach ($aspects as $item) {
            if (! is_array($item) || ! $this->hasExactKeys($item, ['aspect', 'level', 'review_required'])) {
                throw new InvalidArgumentException('Japanese narrative aspect shape is invalid.');
            }

            $aspect = $item['aspect'];
            $level = $item['level'];
            $reviewRequired = $item['review_required'];
            if (! is_string($aspect) || ! in_array($aspect, self::CLUSTER_ASPECTS[$cluster], true)) {
                throw new InvalidArgumentException('Japanese narrative aspect is unknown or belongs to another cluster.');
            }
            if (! is_int($level) || $level < 1 || $level > 5) {
                throw new InvalidArgumentException('Japanese narrative level must be an integer from one through five.');
            }
            if (! is_bool($reviewRequired)) {
                throw new InvalidArgumentException('Japanese narrative review flag must be boolean.');
            }
            if (isset($seen[$aspect])) {
                throw new InvalidArgumentException('Japanese narrative aspects must not contain duplicates.');
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
            throw new InvalidArgumentException('Japanese narrative bank must be a list.');
        }

        $knownAspects = array_merge(...array_values(self::CLUSTER_ASPECTS));
        $byPair = [];
        $seenKeys = [];

        foreach ($bank as $entry) {
            if (! is_array($entry) || ! $this->hasExactKeys($entry, ['key', 'aspect', 'level', 'text'])) {
                throw new InvalidArgumentException('Japanese narrative bank entry shape is invalid.');
            }

            $key = $entry['key'];
            $aspect = $entry['aspect'];
            $level = $entry['level'];
            $text = $entry['text'];
            if (! $this->isNonBlankTrimmedString($key) || ! $this->isNonBlankTrimmedString($text)) {
                throw new InvalidArgumentException('Japanese narrative key and text must be nonblank trimmed strings.');
            }
            if (! is_string($aspect) || ! in_array($aspect, $knownAspects, true)) {
                throw new InvalidArgumentException('Japanese narrative bank aspect is unknown.');
            }
            if (! is_int($level) || $level < 1 || $level > 5) {
                throw new InvalidArgumentException('Japanese narrative bank level must be an integer from one through five.');
            }

            $pair = $this->pairKey($aspect, $level);
            if (isset($byPair[$pair]) || isset($seenKeys[$key])) {
                throw new InvalidArgumentException('Japanese narrative keys and aspect-level pairs must be unique.');
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

    private function isNonBlankTrimmedString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '' && trim($value) === $value;
    }

    private function pairKey(string $aspect, int $level): string
    {
        return $aspect.'|'.$level;
    }
}
