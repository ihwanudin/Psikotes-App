<?php

declare(strict_types=1);

namespace Tests\Unit\Scoring;

use App\Services\Scoring\RmibRawScoreCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RmibRawScoreCalculatorTest extends TestCase
{
    public function test_canonical_cyclic_rank_fixture_produces_category_totals_and_ordinal_ranks(): void
    {
        $data = $this->canonicalData();
        $calculator = new RmibRawScoreCalculator($data['categories'], $data['rotation']);
        $responses = self::completeResponses();

        $result = $calculator->calculate($responses);

        $this->assertSame(108, $result['response_count']);
        $this->assertSame(702, $result['total_rank_sum']);
        $this->assertSame('ranked', $result['ranking_status']);
        $this->assertFalse($result['review_required']);
        $this->assertNull($result['review_reason']);
        $this->assertSame(array_fill(1, 9, 78), $result['group_sums']);
        $this->assertSame(
            [1 => 9, 2 => 18, 3 => 27, 4 => 36, 5 => 45, 6 => 54, 7 => 63, 8 => 72, 9 => 81, 10 => 90, 11 => 99, 12 => 108],
            array_map(static fn (array $category): int => $category['total'], $result['categories']),
        );
        $this->assertSame(
            [1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5, 6 => 6, 7 => 7, 8 => 8, 9 => 9, 10 => 10, 11 => 11, 12 => 12],
            array_map(static fn (array $category): int => $category['rank'], $result['categories']),
        );
        $this->assertSame('Out', $result['categories'][1]['code']);
        $this->assertSame('Outdoor', $result['categories'][1]['name']);
        $this->assertSame(9, $result['categories'][1]['cell_count']);
    }

    public function test_tied_category_totals_use_excel_compatible_competition_ranking(): void
    {
        $data = $this->canonicalData();
        $calculator = new RmibRawScoreCalculator($data['categories'], $data['rotation']);
        $responses = array_map(
            static fn (array $cell): array => [
                'group' => $cell['group'],
                'position' => $cell['position'],
                'rank' => $cell['position'],
            ],
            $data['rotation'],
        );

        $result = $calculator->calculate($responses);

        $this->assertSame(108, $result['response_count']);
        $this->assertSame(702, $result['total_rank_sum']);
        $this->assertSame(array_fill(1, 9, 78), $result['group_sums']);
        $this->assertSame('ranked_with_ties', $result['ranking_status']);
        $this->assertFalse($result['review_required']);
        $this->assertNull($result['review_reason']);
        $this->assertLessThan(12, count(array_unique(array_column($result['categories'], 'total'))));
        $totals = array_column($result['categories'], 'total');

        foreach ($result['categories'] as $category) {
            $expectedRank = 1 + count(array_filter($totals, static fn (int $total): bool => $total < $category['total']));
            $this->assertSame($expectedRank, $category['rank']);
        }
    }

    /** @param array<mixed> $responses */
    #[DataProvider('invalidResponses')]
    public function test_invalid_response_payload_is_rejected(array $responses, string $message): void
    {
        $data = $this->canonicalData();
        $calculator = new RmibRawScoreCalculator($data['categories'], $data['rotation']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $calculator->calculate($responses);
    }

    /**
     * These are genuine structural/type/domain errors -- unrelated to
     * ADR-0032 PR3's completeness tolerance (P3) -- and stay hard-rejected.
     * 'missing cell' and 'duplicate rank within group' used to live here too
     * (pre-PR3, when 108 well-formed non-duplicate responses were always
     * required); they are now valid tiered-scoring inputs, covered by
     * dedicated positive-path tests below instead.
     *
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function invalidResponses(): iterable
    {
        $complete = self::completeResponses();

        yield 'duplicate cell' => [[...$complete, $complete[0]], 'RMIB response cell is duplicated.'];
        yield 'out of domain cell' => [[...array_slice($complete, 0, 107), ['group' => 10, 'position' => 12, 'rank' => 12]], 'RMIB response cell is outside the supplied rotation.'];
        yield 'malformed rank' => [[...array_slice($complete, 0, 107), ['group' => 9, 'position' => 12, 'rank' => '12']], 'RMIB response must contain integer group, position, and rank.'];
        yield 'rank outside 1 through 12' => [[...array_slice($complete, 0, 107), ['group' => 9, 'position' => 12, 'rank' => 13]], 'RMIB rank is outside 1 through 12.'];
    }

    /**
     * ADR-0032 PR3 (P3, 2026-09-21): a group missing exactly one cell is
     * reconstructed from the other eleven ranks (the one value in 1-12 not
     * among them) and scores identically to the fully-answered case.
     */
    public function test_a_single_missing_cell_in_one_group_is_reconstructed_to_an_identical_score(): void
    {
        $data = $this->canonicalData();
        $calculator = new RmibRawScoreCalculator($data['categories'], $data['rotation']);
        $complete = $calculator->calculate(self::completeResponses());
        $reconstructed = $calculator->calculate(array_slice(self::completeResponses(), 0, 107));

        $this->assertTrue($reconstructed['scorable']);
        $this->assertSame([], $reconstructed['excluded_groups']);
        $this->assertFalse($reconstructed['review_required']);
        $this->assertNull($reconstructed['review_reason']);
        $this->assertSame($complete['categories'], $reconstructed['categories']);
        $this->assertSame($complete['group_sums'], $reconstructed['group_sums']);
        $this->assertSame($complete['total_rank_sum'], $reconstructed['total_rank_sum']);
        $this->assertSame(107, $reconstructed['response_count']);
    }

    /**
     * ADR-0032 PR3 (P3): a group with 2+ missing cells, or any duplicated
     * rank within it, is excluded from every category -- but the session
     * still scores, read qualitatively (`review_required`). Every other
     * group, and therefore every category's remaining cell_count, stays
     * intact and uniformly reduced by exactly one (rotation-symmetry: each
     * category occurs exactly once per group).
     */
    #[DataProvider('singleDefectiveGroupCases')]
    public function test_a_single_defective_group_is_excluded_but_the_session_still_scores(array $responses, int $expectedExcludedGroup): void
    {
        $data = $this->canonicalData();
        $calculator = new RmibRawScoreCalculator($data['categories'], $data['rotation']);
        $complete = $calculator->calculate(self::completeResponses());

        $result = $calculator->calculate($responses);

        $this->assertTrue($result['scorable']);
        $this->assertSame([$expectedExcludedGroup], $result['excluded_groups']);
        $this->assertTrue($result['review_required']);
        $this->assertSame('RMIB_GROUP_EXCLUDED', $result['review_reason']);
        $this->assertCount(12, $result['categories']);

        foreach ($result['categories'] as $index => $category) {
            $this->assertSame($complete['categories'][$index]['cell_count'] - 1, $category['cell_count']);
            $this->assertLessThan($complete['categories'][$index]['total'], $category['total']);
        }
    }

    /** @return iterable<string, array{array<mixed>, int}> */
    public static function singleDefectiveGroupCases(): iterable
    {
        $missingTwo = array_values(array_filter(
            self::completeResponses(),
            static fn (array $cell): bool => ! ($cell['group'] === 1 && in_array($cell['position'], [11, 12], true)),
        ));
        yield 'two missing cells in group 1' => [$missingTwo, 1];

        $duplicateRank = self::completeResponses();
        $duplicateRank[1]['rank'] = 1;
        yield 'duplicate rank within group 1' => [$duplicateRank, 1];
    }

    /**
     * ADR-0032 PR3 (P3): 2+ defective groups make the whole result
     * `scorable: false` -- re-administration, not partial scoring.
     */
    public function test_two_or_more_defective_groups_make_the_result_unscorable(): void
    {
        $data = $this->canonicalData();
        $calculator = new RmibRawScoreCalculator($data['categories'], $data['rotation']);
        // Two missing cells in group 1 (positions 11-12) AND group 2
        // (positions 11-12): both cross the 2-missing exclusion threshold.
        $responses = array_values(array_filter(
            self::completeResponses(),
            static fn (array $cell): bool => ! (
                in_array($cell['group'], [1, 2], true) && in_array($cell['position'], [11, 12], true)
            ),
        ));

        $result = $calculator->calculate($responses);

        $this->assertFalse($result['scorable']);
        $this->assertSame([1, 2], $result['excluded_groups']);
        $this->assertTrue($result['review_required']);
        $this->assertSame('RMIB_MULTIPLE_GROUPS_INVALID', $result['review_reason']);
    }

    /**
     * @param  array<mixed>  $categories
     * @param  array<mixed>  $rotation
     */
    #[DataProvider('invalidConfigurations')]
    public function test_invalid_configuration_is_rejected(array $categories, array $rotation): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RmibRawScoreCalculator($categories, $rotation);
    }

    /** @return iterable<string, array{array<mixed>, array<mixed>}> */
    public static function invalidConfigurations(): iterable
    {
        $data = self::loadCanonicalData();

        yield 'fewer than twelve categories' => [array_slice($data['categories'], 0, 11), $data['rotation']];

        $duplicateCategory = $data['categories'];
        $duplicateCategory[11]['index'] = 1;
        yield 'duplicate category index' => [$duplicateCategory, $data['rotation']];

        $duplicateCell = $data['rotation'];
        $duplicateCell[107] = $duplicateCell[0];
        yield 'duplicate rotation cell' => [$data['categories'], $duplicateCell];

        $badCategoryReference = $data['rotation'];
        $badCategoryReference[107]['category'] = 13;
        yield 'unknown category reference' => [$data['categories'], $badCategoryReference];

        $unevenOccurrence = $data['rotation'];
        $unevenOccurrence[107]['category'] = 1;
        yield 'category not mapped nine times' => [$data['categories'], $unevenOccurrence];
    }

    /** @return list<array{group: int, position: int, rank: int}> */
    private static function completeResponses(): array
    {
        $responses = [];

        foreach (range(1, 9) as $group) {
            foreach (range(1, 12) as $position) {
                $responses[] = [
                    'group' => $group,
                    'position' => $position,
                    'rank' => (($position + $group - 2) % 12) + 1,
                ];
            }
        }

        return $responses;
    }

    /** @return array{categories: array<mixed>, rotation: array<mixed>} */
    private function canonicalData(): array
    {
        return self::loadCanonicalData();
    }

    /** @return array{categories: array<mixed>, rotation: array<mixed>} */
    private static function loadCanonicalData(): array
    {
        $path = dirname(__DIR__, 3).'/database/seeders/data/rmib.json';
        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new RuntimeException('Canonical RMIB data could not be read.');
        }

        $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data)
            || ! isset($data['categories'], $data['rotation'])
            || ! is_array($data['categories'])
            || ! is_array($data['rotation'])) {
            throw new RuntimeException('Canonical RMIB scoring data is missing.');
        }

        return ['categories' => $data['categories'], 'rotation' => $data['rotation']];
    }
}
