<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use InvalidArgumentException;

final class KraepelinBandMapper
{
    /** @var list<string> */
    private const FACTORS = ['Panker', 'Tianker', 'Hanker', 'Janker'];

    /** @var array<string, array<string, list<array{lo: int|float|null, hi: int|float|null, score: int, category: string, level: int}>>> */
    private array $bands = [];

    /** @var array<string, array<string, string>> */
    private array $directions = [];

    /** @var array<string, array<string, string>> */
    private array $fallbacks;

    /**
     * @param  array<mixed>  $scoreBands
     * @param  array<mixed>  $groupFallbacks
     */
    public function __construct(array $scoreBands, array $groupFallbacks = [])
    {
        if (! array_is_list($scoreBands) || $scoreBands === []) {
            throw new InvalidArgumentException('Kraepelin score bands must be a non-empty list.');
        }

        $categoryLevels = [];

        foreach ($scoreBands as $band) {
            if (! is_array($band)
                || ! array_key_exists('factor', $band)
                || ! array_key_exists('group', $band)
                || ! array_key_exists('score', $band)
                || ! array_key_exists('lo', $band)
                || ! array_key_exists('hi', $band)
                || ! array_key_exists('category', $band)
                || ! is_string($band['factor'])
                || ! in_array($band['factor'], self::FACTORS, true)
                || ! is_string($band['group'])
                || $band['group'] === ''
                || ! is_int($band['score'])
                || $band['score'] < 1
                || $band['score'] > 10
                || ! self::isBoundary($band['lo'])
                || ! self::isBoundary($band['hi'])
                || ! is_string($band['category'])
                || $band['category'] === '') {
                throw new InvalidArgumentException('Kraepelin score band definition is invalid.');
            }

            if ($band['lo'] !== null && $band['hi'] !== null && $band['lo'] > $band['hi']) {
                throw new InvalidArgumentException('Kraepelin score band lower bound cannot exceed its upper bound.');
            }

            $level = intdiv($band['score'] + 1, 2);
            $knownLevel = $categoryLevels[$band['category']] ?? null;

            if ($knownLevel !== null && $knownLevel !== $level) {
                throw new InvalidArgumentException('Each Kraepelin category must map to exactly one derived level.');
            }

            $categoryLevels[$band['category']] = $level;
            $this->bands[$band['group']][$band['factor']][] = [
                'lo' => $band['lo'],
                'hi' => $band['hi'],
                'score' => $band['score'],
                'category' => $band['category'],
                'level' => $level,
            ];
        }

        $levelCategories = [];

        foreach ($categoryLevels as $category => $level) {
            $knownCategory = $levelCategories[$level] ?? null;

            if ($knownCategory !== null && $knownCategory !== $category) {
                throw new InvalidArgumentException('Each Kraepelin category must map to exactly one derived level.');
            }

            $levelCategories[$level] = $category;
        }

        foreach ($this->bands as $group => $factorBands) {
            $configuredFactors = array_keys($factorBands);
            sort($configuredFactors);
            $expectedFactors = self::FACTORS;
            sort($expectedFactors);

            if ($configuredFactors !== $expectedFactors) {
                throw new InvalidArgumentException('Each Kraepelin norm group must define exactly four factors.');
            }

            foreach ($factorBands as $factor => $bands) {
                usort($bands, static function (array $left, array $right): int {
                    if ($left['lo'] === null) {
                        return -1;
                    }

                    if ($right['lo'] === null) {
                        return 1;
                    }

                    return $left['lo'] <=> $right['lo'];
                });

                $this->validateCoverage($bands);
                $direction = $this->deriveDirection($bands);
                $levels = array_values(array_unique(array_column($bands, 'level')));
                sort($levels);

                if ($levels !== range(1, 5)) {
                    throw new InvalidArgumentException('Each Kraepelin factor must cover derived levels one through five.');
                }

                $this->bands[$group][$factor] = $bands;
                $this->directions[$group][$factor] = $direction;
            }
        }

        $this->fallbacks = $this->validateFallbacks($groupFallbacks);
    }

    /**
     * @param  array<mixed>  $factors
     * @return array{
     *     group: string,
     *     factors: array<string, array{
     *         raw_factor: int|float,
     *         requested_group: string,
     *         applied_group: string,
     *         direction: string,
     *         band: array{lo: int|float|null, hi: int|float|null},
     *         source_score: int,
     *         category: string,
     *         level: int
     *     }>
     * }
     */
    public function map(array $factors, string $group): array
    {
        $factorNames = array_keys($factors);
        sort($factorNames);
        $expectedFactors = self::FACTORS;
        sort($expectedFactors);

        if ($factorNames !== $expectedFactors) {
            throw new InvalidArgumentException('Kraepelin result must contain exactly Panker, Tianker, Hanker, and Janker.');
        }

        if (! array_key_exists($group, $this->bands)) {
            throw new InvalidArgumentException('Kraepelin norm group is not configured.');
        }

        $mapped = [];

        foreach (self::FACTORS as $factor) {
            $value = $factors[$factor];

            if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
                throw new InvalidArgumentException('Kraepelin factor values must be finite integers or floats.');
            }

            $appliedGroup = $this->fallbacks[$group][$factor] ?? $group;
            $matchedBand = null;

            foreach ($this->bands[$appliedGroup][$factor] as $band) {
                if (($band['lo'] === null || $value >= $band['lo'])
                    && ($band['hi'] === null || $value <= $band['hi'])) {
                    $matchedBand = $band;
                    break;
                }
            }

            if ($matchedBand === null) {
                throw new InvalidArgumentException('Kraepelin factor does not match a configured score band.');
            }

            $mapped[$factor] = [
                'raw_factor' => $value,
                'requested_group' => $group,
                'applied_group' => $appliedGroup,
                'direction' => $this->directions[$appliedGroup][$factor],
                'band' => ['lo' => $matchedBand['lo'], 'hi' => $matchedBand['hi']],
                'source_score' => $matchedBand['score'],
                'category' => $matchedBand['category'],
                'level' => $matchedBand['level'],
            ];
        }

        return ['group' => $group, 'factors' => $mapped];
    }

    /**
     * @param  list<array{lo: int|float|null, hi: int|float|null, score: int, category: string, level: int}>  $bands
     */
    private function validateCoverage(array $bands): void
    {
        $precision = 0;

        foreach ($bands as $band) {
            foreach (['lo', 'hi'] as $boundary) {
                if ($band[$boundary] !== null) {
                    $precision = max($precision, self::decimalPlaces($band[$boundary]));
                }
            }
        }

        $step = 10 ** (-$precision);

        for ($index = 1, $count = count($bands); $index < $count; $index++) {
            $previousHi = $bands[$index - 1]['hi'];
            $currentLo = $bands[$index]['lo'];

            if ($previousHi === null || $currentLo === null
                || abs(($currentLo - $previousHi) - $step) > 1.0E-9) {
                throw new InvalidArgumentException('Kraepelin bands must cover their domain without gaps or overlaps.');
            }
        }
    }

    /**
     * @param  list<array{lo: int|float|null, hi: int|float|null, score: int, category: string, level: int}>  $bands
     */
    private function deriveDirection(array $bands): string
    {
        $scores = array_column($bands, 'score');
        $ascending = true;
        $descending = true;

        for ($index = 1, $count = count($scores); $index < $count; $index++) {
            $ascending = $ascending && $scores[$index] > $scores[$index - 1];
            $descending = $descending && $scores[$index] < $scores[$index - 1];
        }

        if ($ascending === $descending) {
            throw new InvalidArgumentException('Kraepelin band scores must be strictly monotonic.');
        }

        return $ascending ? 'higher_is_better' : 'lower_is_better';
    }

    /**
     * @param  array<mixed>  $fallbacks
     * @return array<string, array<string, string>>
     */
    private function validateFallbacks(array $fallbacks): array
    {
        $validated = [];

        foreach ($fallbacks as $requestedGroup => $factorFallbacks) {
            if (! is_string($requestedGroup)
                || ! array_key_exists($requestedGroup, $this->bands)
                || ! is_array($factorFallbacks)) {
                throw new InvalidArgumentException('Kraepelin group fallback definition is invalid.');
            }

            foreach ($factorFallbacks as $factor => $appliedGroup) {
                if (! is_string($factor)
                    || ! in_array($factor, self::FACTORS, true)
                    || ! is_string($appliedGroup)) {
                    throw new InvalidArgumentException('Kraepelin group fallback definition is invalid.');
                }

                if (! array_key_exists($appliedGroup, $this->bands)) {
                    throw new InvalidArgumentException('Kraepelin group fallback references an unknown group.');
                }

                $validated[$requestedGroup][$factor] = $appliedGroup;
            }
        }

        return $validated;
    }

    private static function isBoundary(mixed $value): bool
    {
        return $value === null
            || ((is_int($value) || is_float($value)) && is_finite((float) $value));
    }

    private static function decimalPlaces(int|float $value): int
    {
        if (is_int($value)) {
            return 0;
        }

        $formatted = rtrim(rtrim(sprintf('%.12F', $value), '0'), '.');
        $decimalPoint = strpos($formatted, '.');

        return $decimalPoint === false ? 0 : strlen($formatted) - $decimalPoint - 1;
    }
}
