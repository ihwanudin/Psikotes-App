<?php

declare(strict_types=1);

namespace Tests\Unit\Scoring;

use App\Services\Scoring\RmibScoreCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RmibScoreCalculatorTest extends TestCase
{
    #[DataProvider('canonicalRanks')]
    public function test_every_rank_uses_the_versioned_sheet_11_rank_to_score_table(int $rank): void
    {
        $table = $this->canonicalTable();
        $result = (new RmibScoreCalculator($table))->calculate($rank);

        $this->assertSame($rank, $result['rank']);
        // Compared against the approved table itself, not a constant rewritten
        // in this test — a change to rmib.json's rank_to_score is exactly what
        // this test must catch.
        $this->assertSame($table[(string) $rank], $result['score']);
    }

    /** @return iterable<string, array{int}> */
    public static function canonicalRanks(): iterable
    {
        foreach (range(1, 12) as $rank) {
            yield 'rank '.$rank => [$rank];
        }
    }

    public function test_invalid_rank_and_non_monotonic_mapping_fail_closed(): void
    {
        $calculator = new RmibScoreCalculator($this->canonicalTable());

        foreach ([0, 13, '1'] as $rank) {
            try {
                $calculator->calculate($rank);
                $this->fail('Invalid RMIB rank was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $table = $this->canonicalTable();
        $table['12'] = 10;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('RMIB rank-to-score mapping must be non-increasing as rank increases.');
        new RmibScoreCalculator($table);
    }

    public function test_incomplete_or_out_of_bounds_table_fails_closed(): void
    {
        $table = $this->canonicalTable();
        unset($table['12']);

        try {
            new RmibScoreCalculator($table);
            $this->fail('Incomplete RMIB rank-to-score table was accepted.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $table = $this->canonicalTable();
        $table['1'] = 11;

        try {
            new RmibScoreCalculator($table);
            $this->fail('Out-of-bounds RMIB rank-to-score value was accepted.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    /** @return array<string, int> */
    private function canonicalTable(): array
    {
        $contents = file_get_contents(dirname(__DIR__, 3).'/database/seeders/data/rmib.json');
        if (! is_string($contents)) {
            throw new RuntimeException('Canonical RMIB data could not be read.');
        }

        $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        return $data['rank_to_score'];
    }
}
