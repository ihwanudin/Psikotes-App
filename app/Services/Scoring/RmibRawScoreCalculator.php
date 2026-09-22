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
     * Psychologist's tiered incomplete-ranking rule (P3, 2026-09-21, ADR-0032
     * PR3): each of the 9 groups is classified independently before any
     * category total is computed --
     * - complete (all 12 positions present, one of each rank): used as-is.
     * - reconstructed (exactly 1 position missing, the other 11 ranks
     *   distinct): the missing position's rank is the one value in 1-12 not
     *   among the 11 given -- pigeonhole guarantees this is unique. Counts as
     *   a fully valid group, identical to a complete one, once reconstructed.
     * - excluded (2+ positions missing, and/or any rank used more than once
     *   in the group -- these two conditions are checked independently, not
     *   as alternatives, because a group can be both): dropped from every
     *   category's total (not just its own), not just averaged out -- see
     *   this class's docblock-adjacent RMIB rotation-symmetry note: each
     *   category appears in the rotation exactly once per group, so
     *   excluding a whole group reduces every category's cell_count by
     *   exactly 1, keeping cross-category comparison fair.
     *
     * 2+ excluded groups make the whole result `scorable: false` --
     * re-administration, not partial scoring (P3, explicit). Exactly 1
     * excluded group still produces a full, category-complete result, read
     * qualitatively (`review_required: true`).
     *
     * @param  array<mixed>  $responses
     * @return array{
     *     categories: array<int, array{code: string, name: string, total: int, rank: int, cell_count: int}>,
     *     group_sums: array<int, int>,
     *     total_rank_sum: int,
     *     response_count: int,
     *     ranking_status: 'ranked'|'ranked_with_ties',
     *     scorable: bool,
     *     excluded_groups: list<int>,
     *     review_required: bool,
     *     review_reason: string|null
     * }
     */
    public function calculate(array $responses): array
    {
        $seen = [];
        $groupPositions = array_fill_keys(range(1, 9), []);

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

            $seen[$coordinate] = true;
            $groupPositions[$response['group']][$response['position']] = $response['rank'];
        }

        $groupSums = [];
        $resolvedGroups = [];
        $excludedGroups = [];

        foreach (range(1, 9) as $group) {
            $positions = $groupPositions[$group];
            $rankCounts = array_count_values($positions);
            $hasDuplicateRank = count(array_filter($rankCounts, static fn (int $count): bool => $count > 1)) > 0;
            $missingCount = 12 - count($positions);

            if ($hasDuplicateRank || $missingCount >= 2) {
                $excludedGroups[] = $group;
                $groupSums[$group] = array_sum($positions);

                continue;
            }

            if ($missingCount === 1) {
                $missingPosition = self::onlyMissing(range(1, 12), array_keys($positions));
                $missingRank = self::onlyMissing(range(1, 12), array_values($positions));
                $positions[$missingPosition] = $missingRank;
            }

            // Explicit prerequisite check (P3, restated twice by Lead): never
            // treat a group as usable without verifying it resolves to
            // exactly ranks 1-12 summing to 78. For a genuinely
            // complete/reconstructed group this is mathematically guaranteed
            // by the checks above -- a failure here means this method's own
            // classification logic has a bug, not a participant data issue.
            $ranks = array_values($positions);
            $sum = array_sum($ranks);
            sort($ranks);
            if (count($ranks) !== 12 || $sum !== 78 || $ranks !== range(1, 12)) {
                throw new InvalidArgumentException('RMIB resolved group failed the ranks-one-through-twelve/sum-78 verification.');
            }

            $resolvedGroups[$group] = $positions;
            $groupSums[$group] = 78;
        }

        $categoryTotals = array_fill_keys(array_keys($this->categories), 0);
        $categoryCellCounts = array_fill_keys(array_keys($this->categories), 0);

        foreach ($resolvedGroups as $group => $positions) {
            foreach ($positions as $position => $rank) {
                $category = $this->rotation[self::coordinate($group, $position)];
                $categoryTotals[$category] += $rank;
                $categoryCellCounts[$category]++;
            }
        }

        $hasTies = count($resolvedGroups) > 0 && count(array_unique($categoryTotals)) !== 12;
        $ranks = [];
        foreach ($categoryTotals as $category => $total) {
            $ranks[$category] = 1 + count(array_filter(
                $categoryTotals,
                static fn (int $candidateTotal): bool => $candidateTotal < $total,
            ));
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

        // $excludedGroups was appended in the range(1, 9) loop above, so it
        // is already ascending -- no separate sort needed.
        $excludedCount = count($excludedGroups);

        return [
            'categories' => $results,
            'group_sums' => $groupSums,
            'total_rank_sum' => array_sum($categoryTotals),
            'response_count' => count($seen),
            'ranking_status' => $hasTies ? 'ranked_with_ties' : 'ranked',
            'scorable' => $excludedCount < 2,
            'excluded_groups' => $excludedGroups,
            'review_required' => $excludedCount >= 1,
            'review_reason' => match (true) {
                $excludedCount >= 2 => 'RMIB_MULTIPLE_GROUPS_INVALID',
                $excludedCount === 1 => 'RMIB_GROUP_EXCLUDED',
                default => null,
            },
        ];
    }

    /**
     * @param  list<int>  $domain
     * @param  list<int>  $present
     */
    private static function onlyMissing(array $domain, array $present): int
    {
        $missing = array_values(array_diff($domain, $present));

        if (count($missing) !== 1) {
            throw new InvalidArgumentException('RMIB group reconstruction expected exactly one missing value.');
        }

        return $missing[0];
    }

    private static function coordinate(int $group, int $position): string
    {
        return $group.':'.$position;
    }
}
