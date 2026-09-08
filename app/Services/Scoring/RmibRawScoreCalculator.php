<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use InvalidArgumentException;

final readonly class RmibRawScoreCalculator
{
    /** @var array<int, array{code: string, name: string}> */
    private array $categories;

    /** @var array<string, int> */
    private array $rotation;

    /**
     * @param  array<mixed>  $categories
     * @param  array<mixed>  $rotation
     */
    public function __construct(array $categories, array $rotation)
    {
        if (! array_is_list($categories) || count($categories) !== 12) {
            throw new InvalidArgumentException('RMIB configuration must define exactly twelve categories.');
        }

        $categoryMap = [];

        foreach ($categories as $category) {
            if (! is_array($category)
                || ! isset($category['index'], $category['code'], $category['name'])
                || ! is_int($category['index'])
                || ! is_string($category['code'])
                || $category['code'] === ''
                || ! is_string($category['name'])
                || $category['name'] === '') {
                throw new InvalidArgumentException('RMIB category definition is invalid.');
            }

            if (array_key_exists($category['index'], $categoryMap)) {
                throw new InvalidArgumentException('RMIB category index is duplicated.');
            }

            $categoryMap[$category['index']] = [
                'code' => $category['code'],
                'name' => $category['name'],
            ];
        }

        ksort($categoryMap);

        if (array_keys($categoryMap) !== range(1, 12)) {
            throw new InvalidArgumentException('RMIB category indexes must cover one through twelve.');
        }

        if (! array_is_list($rotation) || count($rotation) !== 108) {
            throw new InvalidArgumentException('RMIB rotation must contain exactly 108 cells.');
        }

        $cellMap = [];
        $categoryOccurrences = array_fill_keys(range(1, 12), 0);

        foreach ($rotation as $cell) {
            if (! is_array($cell)
                || ! isset($cell['group'], $cell['position'], $cell['category'])
                || ! is_int($cell['group'])
                || ! is_int($cell['position'])
                || ! is_int($cell['category'])
                || $cell['group'] < 1
                || $cell['group'] > 9
                || $cell['position'] < 1
                || $cell['position'] > 12) {
                throw new InvalidArgumentException('RMIB rotation cell is invalid.');
            }

            if (! array_key_exists($cell['category'], $categoryMap)) {
                throw new InvalidArgumentException('RMIB rotation references an unknown category.');
            }

            $coordinate = self::coordinate($cell['group'], $cell['position']);

            if (array_key_exists($coordinate, $cellMap)) {
                throw new InvalidArgumentException('RMIB rotation cell is duplicated.');
            }

            $cellMap[$coordinate] = $cell['category'];
            $categoryOccurrences[$cell['category']]++;
        }

        foreach (range(1, 9) as $group) {
            foreach (range(1, 12) as $position) {
                if (! array_key_exists(self::coordinate($group, $position), $cellMap)) {
                    throw new InvalidArgumentException('RMIB rotation must cover every group and position.');
                }
            }
        }

        foreach ($categoryOccurrences as $occurrences) {
            if ($occurrences !== 9) {
                throw new InvalidArgumentException('Each RMIB category must occur exactly nine times.');
            }
        }

        $this->categories = $categoryMap;
        $this->rotation = $cellMap;
    }

    /**
     * @param  array<mixed>  $responses
     * @return array{
     *     categories: array<int, array{code: string, name: string, total: int, rank: int, cell_count: int}>,
     *     group_sums: array<int, int>,
     *     total_rank_sum: int,
     *     response_count: int,
     *     ranking_tie_breaker: string
     * }
     */
    public function calculate(array $responses): array
    {
        $categoryTotals = array_fill_keys(array_keys($this->categories), 0);
        $categoryCellCounts = array_fill_keys(array_keys($this->categories), 0);
        $groupSums = array_fill_keys(range(1, 9), 0);
        $groupRanks = array_fill_keys(range(1, 9), []);
        $seen = [];

        foreach ($responses as $response) {
            if (! is_array($response)
                || ! isset($response['group'], $response['position'], $response['rank'])
                || ! is_int($response['group'])
                || ! is_int($response['position'])
                || ! is_int($response['rank'])) {
                throw new InvalidArgumentException('RMIB response must contain integer group, position, and rank.');
            }

            if ($response['rank'] < 1 || $response['rank'] > 12) {
                throw new InvalidArgumentException('RMIB rank is outside 1 through 12.');
            }

            $coordinate = self::coordinate($response['group'], $response['position']);

            if (array_key_exists($coordinate, $seen)) {
                throw new InvalidArgumentException('RMIB response cell is duplicated.');
            }

            if (! array_key_exists($coordinate, $this->rotation)) {
                throw new InvalidArgumentException('RMIB response cell is outside the supplied rotation.');
            }

            if (array_key_exists($response['rank'], $groupRanks[$response['group']])) {
                throw new InvalidArgumentException('RMIB group must use each rank exactly once.');
            }

            $category = $this->rotation[$coordinate];
            $categoryTotals[$category] += $response['rank'];
            $categoryCellCounts[$category]++;
            $groupSums[$response['group']] += $response['rank'];
            $groupRanks[$response['group']][$response['rank']] = true;
            $seen[$coordinate] = true;
        }

        if (count($seen) !== 108) {
            throw new InvalidArgumentException('RMIB responses must contain all 108 configured cells.');
        }

        foreach ($groupSums as $group => $sum) {
            ksort($groupRanks[$group]);

            if (array_keys($groupRanks[$group]) !== range(1, 12) || $sum !== 78) {
                throw new InvalidArgumentException('Each RMIB group must contain ranks one through twelve and sum to 78.');
            }
        }

        if (array_sum($categoryTotals) !== 702) {
            throw new InvalidArgumentException('RMIB total rank sum must equal 702.');
        }

        $ranked = array_keys($categoryTotals);
        usort($ranked, static function (int $left, int $right) use ($categoryTotals): int {
            return [$categoryTotals[$left], $left] <=> [$categoryTotals[$right], $right];
        });
        $ranks = [];

        foreach ($ranked as $offset => $category) {
            $ranks[$category] = $offset + 1;
        }

        $results = [];

        foreach ($this->categories as $index => $category) {
            $results[$index] = [
                'code' => $category['code'],
                'name' => $category['name'],
                'total' => $categoryTotals[$index],
                'rank' => $ranks[$index],
                'cell_count' => $categoryCellCounts[$index],
            ];
        }

        return [
            'categories' => $results,
            'group_sums' => $groupSums,
            'total_rank_sum' => array_sum($categoryTotals),
            'response_count' => count($seen),
            'ranking_tie_breaker' => 'category_index',
        ];
    }

    private static function coordinate(int $group, int $position): string
    {
        return $group.':'.$position;
    }
}
