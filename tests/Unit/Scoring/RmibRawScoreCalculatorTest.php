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
    public function test_canonical_position_rank_fixture_produces_category_totals_and_ordinal_ranks(): void
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
        $this->assertSame('category_index', $result['ranking_tie_breaker']);
        $this->assertSame(array_fill(1, 9, 78), $result['group_sums']);
        $this->assertSame(
            [1 => 69, 2 => 66, 3 => 63, 4 => 60, 5 => 57, 6 => 54, 7 => 51, 8 => 48, 9 => 45, 10 => 54, 11 => 63, 12 => 72],
            array_map(static fn (array $category): int => $category['total'], $result['categories']),
        );
        $this->assertSame(
            [1 => 11, 2 => 10, 3 => 8, 4 => 7, 5 => 6, 6 => 4, 7 => 3, 8 => 2, 9 => 1, 10 => 5, 11 => 9, 12 => 12],
            array_map(static fn (array $category): int => $category['rank'], $result['categories']),
        );
        $this->assertSame('Out', $result['categories'][1]['code']);
        $this->assertSame('Outdoor', $result['categories'][1]['name']);
        $this->assertSame(9, $result['categories'][1]['cell_count']);
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

    /** @return iterable<string, array{array<mixed>, string}> */
    public static function invalidResponses(): iterable
    {
        $complete = self::completeResponses();

        yield 'missing cell' => [array_slice($complete, 0, 107), 'RMIB responses must contain all 108 configured cells.'];
        yield 'duplicate cell' => [[...$complete, $complete[0]], 'RMIB response cell is duplicated.'];
        yield 'out of domain cell' => [[...array_slice($complete, 0, 107), ['group' => 10, 'position' => 12, 'rank' => 12]], 'RMIB response cell is outside the supplied rotation.'];
        yield 'malformed rank' => [[...array_slice($complete, 0, 107), ['group' => 9, 'position' => 12, 'rank' => '12']], 'RMIB response must contain integer group, position, and rank.'];
        yield 'rank outside 1 through 12' => [[...array_slice($complete, 0, 107), ['group' => 9, 'position' => 12, 'rank' => 13]], 'RMIB rank is outside 1 through 12.'];

        $duplicateRank = $complete;
        $duplicateRank[1]['rank'] = 1;
        yield 'duplicate rank within group' => [$duplicateRank, 'RMIB group must use each rank exactly once.'];
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
                $responses[] = ['group' => $group, 'position' => $position, 'rank' => $position];
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
