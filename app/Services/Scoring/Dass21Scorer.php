<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use InvalidArgumentException;

final class Dass21Scorer
{
    /** @var list<string> */
    private const SCALES = ['D', 'A', 'S'];

    /** @var array<int, string> */
    private array $itemScales;

    /** @var array<string, list<int>> */
    private array $scaleItems;

    /** @var array<string, list<array{lo: int, hi: int|null, category: string, level: int}>> */
    private array $cutoffs;

    /** @var array<int, string> */
    private array $categoriesByLevel;

    private int $multiplier;

    /**
     * @param  array<mixed>  $items
     * @param  array<mixed>  $cutoffs
     */
    public function __construct(array $items, array $cutoffs, mixed $multiplier)
    {
        if (! is_int($multiplier) || $multiplier < 1) {
            throw new InvalidArgumentException('DASS-21 multiplier must be a positive integer.');
        }

        $this->multiplier = $multiplier;
        $this->itemScales = $this->validateItems($items);
        $this->scaleItems = array_fill_keys(self::SCALES, []);

        foreach ($this->itemScales as $item => $scale) {
            $this->scaleItems[$scale][] = $item;
        }

        $this->cutoffs = $this->validateCutoffs($cutoffs);
    }

    /**
     * @param  array<mixed>  $responses
     * @return array{
     *     subscales: array<string, array{
     *         raw_score: int,
     *         score_x2: int,
     *         category: string,
     *         level: int,
     *         item_count: int,
     *         item_scores: array<int, int>,
     *         cutoff: array{lo: int, hi: int|null}
     *     }>,
     *     general: array{category: string, level: int, basis_scales: list<string>},
     *     provenance: array{
     *         response_count: int,
     *         response_domain: array{min: int, max: int},
     *         multiplier: int,
     *         scale_item_counts: array<string, int>
     *     }
     * }
     */
    public function score(array $responses): array
    {
        if (! array_is_list($responses) || count($responses) !== 21) {
            throw new InvalidArgumentException('DASS-21 responses must contain exactly 21 answers.');
        }

        $answers = [];

        foreach ($responses as $response) {
            if (! is_array($response)
                || ! array_key_exists('item', $response)
                || ! array_key_exists('score', $response)
                || ! is_int($response['item'])
                || ! is_int($response['score'])) {
                throw new InvalidArgumentException('DASS-21 response must contain integer item and score.');
            }

            if ($response['score'] < 0 || $response['score'] > 3) {
                throw new InvalidArgumentException('DASS-21 response score must be between 0 and 3.');
            }

            if (array_key_exists($response['item'], $answers)) {
                throw new InvalidArgumentException('DASS-21 response item is duplicated.');
            }

            if (! array_key_exists($response['item'], $this->itemScales)) {
                throw new InvalidArgumentException('DASS-21 response references an unknown item.');
            }

            $answers[$response['item']] = $response['score'];
        }

        $subscales = [];

        foreach (self::SCALES as $scale) {
            $itemScores = [];

            foreach ($this->scaleItems[$scale] as $item) {
                $itemScores[$item] = $answers[$item];
            }

            $rawScore = array_sum($itemScores);
            $multipliedScore = $rawScore * $this->multiplier;
            $matchedCutoff = null;

            foreach ($this->cutoffs[$scale] as $cutoff) {
                if ($multipliedScore >= $cutoff['lo']
                    && ($cutoff['hi'] === null || $multipliedScore <= $cutoff['hi'])) {
                    $matchedCutoff = $cutoff;
                    break;
                }
            }

            if ($matchedCutoff === null) {
                throw new InvalidArgumentException('DASS-21 multiplied score does not match a configured cutoff.');
            }

            $subscales[$scale] = [
                'raw_score' => $rawScore,
                'score_x2' => $multipliedScore,
                'category' => $matchedCutoff['category'],
                'level' => $matchedCutoff['level'],
                'item_count' => count($itemScores),
                'item_scores' => $itemScores,
                'cutoff' => ['lo' => $matchedCutoff['lo'], 'hi' => $matchedCutoff['hi']],
            ];
        }

        $generalLevel = max(array_column($subscales, 'level'));
        $basisScales = [];

        foreach ($subscales as $scale => $result) {
            if ($result['level'] === $generalLevel) {
                $basisScales[] = $scale;
            }
        }

        return [
            'subscales' => $subscales,
            'general' => [
                'category' => $this->categoriesByLevel[$generalLevel],
                'level' => $generalLevel,
                'basis_scales' => $basisScales,
            ],
            'provenance' => [
                'response_count' => count($answers),
                'response_domain' => ['min' => 0, 'max' => 3],
                'multiplier' => $this->multiplier,
                'scale_item_counts' => array_map(count(...), $this->scaleItems),
            ],
        ];
    }

    /**
     * @param  array<mixed>  $items
     * @return array<int, string>
     */
    private function validateItems(array $items): array
    {
        if (! array_is_list($items) || count($items) !== 21) {
            throw new InvalidArgumentException('DASS-21 configuration must define exactly 21 items.');
        }

        $itemScales = [];
        $scaleCounts = array_fill_keys(self::SCALES, 0);

        foreach ($items as $item) {
            if (! is_array($item)
                || ! isset($item['item'], $item['scale'])
                || ! is_int($item['item'])
                || ! is_string($item['scale'])) {
                throw new InvalidArgumentException('DASS-21 item definition is invalid.');
            }

            if (! in_array($item['scale'], self::SCALES, true)) {
                throw new InvalidArgumentException('DASS-21 item scale must be D, A, or S.');
            }

            if (array_key_exists($item['item'], $itemScales)) {
                throw new InvalidArgumentException('DASS-21 item identifiers must cover 1 through 21 exactly once.');
            }

            $itemScales[$item['item']] = $item['scale'];
            $scaleCounts[$item['scale']]++;
        }

        ksort($itemScales);

        if (array_keys($itemScales) !== range(1, 21)) {
            throw new InvalidArgumentException('DASS-21 item identifiers must cover 1 through 21 exactly once.');
        }

        if (array_values($scaleCounts) !== [7, 7, 7]) {
            throw new InvalidArgumentException('Each DASS-21 scale must contain exactly seven items.');
        }

        return $itemScales;
    }

    /**
     * @param  array<mixed>  $cutoffs
     * @return array<string, list<array{lo: int, hi: int|null, category: string, level: int}>>
     */
    private function validateCutoffs(array $cutoffs): array
    {
        if (! array_is_list($cutoffs)) {
            throw new InvalidArgumentException('DASS-21 cutoffs must be a list.');
        }

        $parsed = array_fill_keys(self::SCALES, []);
        $categoriesByLevel = [];
        $levelsByCategory = [];

        foreach ($cutoffs as $cutoff) {
            if (! is_array($cutoff)
                || ! isset($cutoff['scale'], $cutoff['category'], $cutoff['level'], $cutoff['range_x2'])
                || ! is_string($cutoff['scale'])
                || ! in_array($cutoff['scale'], self::SCALES, true)
                || ! is_string($cutoff['category'])
                || $cutoff['category'] === ''
                || ! is_int($cutoff['level'])
                || $cutoff['level'] < 1
                || $cutoff['level'] > 5
                || ! is_string($cutoff['range_x2'])) {
                throw new InvalidArgumentException('DASS-21 cutoff definition is invalid.');
            }

            [$lo, $hi] = $this->parseRange($cutoff['range_x2']);
            $knownCategory = $categoriesByLevel[$cutoff['level']] ?? null;

            if ($knownCategory !== null && $knownCategory !== $cutoff['category']) {
                throw new InvalidArgumentException('Each DASS-21 severity level must use one canonical category.');
            }

            $knownLevel = $levelsByCategory[$cutoff['category']] ?? null;

            if ($knownLevel !== null && $knownLevel !== $cutoff['level']) {
                throw new InvalidArgumentException('Each DASS-21 severity level must use one canonical category.');
            }

            $categoriesByLevel[$cutoff['level']] = $cutoff['category'];
            $levelsByCategory[$cutoff['category']] = $cutoff['level'];
            $parsed[$cutoff['scale']][] = [
                'lo' => $lo,
                'hi' => $hi,
                'category' => $cutoff['category'],
                'level' => $cutoff['level'],
            ];
        }

        ksort($categoriesByLevel);

        if (array_keys($categoriesByLevel) !== range(1, 5)) {
            throw new InvalidArgumentException('Each DASS-21 scale must define levels one through five.');
        }

        foreach ($parsed as $scale => $scaleCutoffs) {
            usort($scaleCutoffs, static fn (array $left, array $right): int => $left['lo'] <=> $right['lo']);

            if (array_column($scaleCutoffs, 'level') !== range(1, 5)) {
                throw new InvalidArgumentException('Each DASS-21 scale must define levels one through five.');
            }

            if ($scaleCutoffs[0]['lo'] !== 0 || $scaleCutoffs[4]['hi'] !== null) {
                throw new InvalidArgumentException('DASS-21 cutoffs must cover the score domain without gaps or overlaps.');
            }

            for ($index = 1; $index < 5; $index++) {
                $previousHi = $scaleCutoffs[$index - 1]['hi'];

                if ($previousHi === null || $scaleCutoffs[$index]['lo'] !== $previousHi + 1) {
                    throw new InvalidArgumentException('DASS-21 cutoffs must cover the score domain without gaps or overlaps.');
                }
            }

            $parsed[$scale] = $scaleCutoffs;
        }

        $this->categoriesByLevel = $categoriesByLevel;

        return $parsed;
    }

    /** @return array{int, int|null} */
    private function parseRange(string $range): array
    {
        if (preg_match('/^\s*(\d+)\s*[–-]\s*(\d+)\s*$/u', $range, $matches) === 1) {
            $lo = (int) $matches[1];
            $hi = (int) $matches[2];

            if ($lo <= $hi) {
                return [$lo, $hi];
            }
        }

        if (preg_match('/^\s*≥\s*(\d+)\s*$/u', $range, $matches) === 1) {
            return [(int) $matches[1], null];
        }

        throw new InvalidArgumentException('DASS-21 cutoff range is invalid.');
    }
}
