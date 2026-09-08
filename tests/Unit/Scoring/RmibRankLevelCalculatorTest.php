<?php

declare(strict_types=1);

namespace Tests\Unit\Scoring;

use App\Services\Scoring\RmibRankLevelCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RmibRankLevelCalculatorTest extends TestCase
{
    #[DataProvider('canonicalRanks')]
    public function test_every_rank_uses_the_versioned_sheet_11_mapping(int $rank, int $level): void
    {
        $result = (new RmibRankLevelCalculator($this->canonicalMapping()))->calculate($rank);

        $this->assertSame($rank, $result['rank']);
        $this->assertSame($level, $result['level']);
    }

    /** @return iterable<string, array{int, int}> */
    public static function canonicalRanks(): iterable
    {
        foreach ([5, 5, 4, 4, 3, 3, 3, 3, 2, 2, 1, 1] as $offset => $level) {
            yield 'rank '.($offset + 1) => [$offset + 1, $level];
        }
    }

    public function test_invalid_rank_and_non_monotonic_mapping_fail_closed(): void
    {
        $calculator = new RmibRankLevelCalculator($this->canonicalMapping());

        foreach ([0, 13, '1'] as $rank) {
            try {
                $calculator->calculate($rank);
                $this->fail('Invalid RMIB rank was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $mapping = $this->canonicalMapping();
        $mapping['12'] = 5;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('RMIB rank-to-level mapping must be non-increasing.');
        new RmibRankLevelCalculator($mapping);
    }

    /** @return array<mixed> */
    private function canonicalMapping(): array
    {
        $contents = file_get_contents(dirname(__DIR__, 3).'/database/seeders/data/rmib.json');
        if (! is_string($contents)) {
            throw new RuntimeException('Canonical RMIB data could not be read.');
        }

        $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        return $data['rank_to_level'];
    }
}
