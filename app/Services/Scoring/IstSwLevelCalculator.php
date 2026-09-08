<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use InvalidArgumentException;

final readonly class IstSwLevelCalculator
{
    /** @var list<array{score: int, lo: int|null, hi: int|null, category: string, level: int}> */
    private array $bands;

    /** @param array<mixed> $bands */
    public function __construct(array $bands)
    {
        if (! array_is_list($bands) || $bands === []) {
            throw new InvalidArgumentException('IST SW bands must be a non-empty list.');
        }

        $validated = [];

        foreach ($bands as $band) {
            if (! is_array($band)
                || ! array_key_exists('score', $band)
                || ! array_key_exists('lo', $band)
                || ! array_key_exists('hi', $band)
                || ! array_key_exists('category', $band)
                || ! is_int($band['score'])
                || (! is_int($band['lo']) && $band['lo'] !== null)
                || (! is_int($band['hi']) && $band['hi'] !== null)
                || ! is_string($band['category'])
                || trim($band['category']) === ''
                || ($band['lo'] === null && $band['hi'] === null)
                || (is_int($band['lo']) && is_int($band['hi']) && $band['lo'] > $band['hi'])) {
                throw new InvalidArgumentException('IST SW band definition is invalid.');
            }

            $validated[] = [
                'score' => $band['score'],
                'lo' => $band['lo'],
                'hi' => $band['hi'],
                'category' => $band['category'],
                'level' => 0,
            ];
        }

        $lastIndex = count($validated) - 1;

        if ($validated[0]['hi'] !== null || $validated[0]['lo'] === null
            || $validated[$lastIndex]['lo'] !== null || $validated[$lastIndex]['hi'] === null) {
            throw new InvalidArgumentException('IST SW bands must cover both unbounded edges.');
        }

        for ($index = 0; $index < $lastIndex; $index++) {
            $higher = $validated[$index];
            $lower = $validated[$index + 1];

            if ($higher['lo'] === null
                || $lower['hi'] === null
                || $lower['hi'] === PHP_INT_MAX
                || $higher['lo'] !== $lower['hi'] + 1
                || $higher['score'] !== $lower['score'] + 1) {
                throw new InvalidArgumentException('IST SW bands must be contiguous and ordered from high to low.');
            }
        }

        $categoryLevels = [];
        $previousCategory = null;
        $level = 0;

        foreach (array_reverse($validated) as $band) {
            if ($band['category'] !== $previousCategory) {
                if (array_key_exists($band['category'], $categoryLevels)) {
                    throw new InvalidArgumentException('IST SW category bands must be contiguous.');
                }

                $level++;
                $categoryLevels[$band['category']] = $level;
                $previousCategory = $band['category'];
            }
        }

        if ($level !== 5) {
            throw new InvalidArgumentException('IST SW bands must define exactly five ordered categories.');
        }

        foreach ($validated as $index => $band) {
            $validated[$index]['level'] = $categoryLevels[$band['category']];
        }

        $this->bands = $validated;
    }

    /**
     * @return array{
     *     standard_score: int,
     *     source_score: int,
     *     level: int,
     *     category: string,
     *     band: array{lo: int|null, hi: int|null}
     * }
     */
    public function calculate(mixed $standardScore): array
    {
        if (! is_int($standardScore)) {
            throw new InvalidArgumentException('IST standard score must be an integer.');
        }

        foreach ($this->bands as $band) {
            if (($band['lo'] === null || $standardScore >= $band['lo'])
                && ($band['hi'] === null || $standardScore <= $band['hi'])) {
                return [
                    'standard_score' => $standardScore,
                    'source_score' => $band['score'],
                    'level' => $band['level'],
                    'category' => $band['category'],
                    'band' => ['lo' => $band['lo'], 'hi' => $band['hi']],
                ];
            }
        }

        throw new InvalidArgumentException('IST standard score is outside the supplied bands.');
    }
}
