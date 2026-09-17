<?php

declare(strict_types=1);

namespace Tests\Unit\Scoring;

use App\Services\Scoring\IstIqLevelCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class IstIqLevelCalculatorTest extends TestCase
{
    #[DataProvider('canonicalBoundaries')]
    public function test_canonical_iq_boundaries_follow_the_versioned_lookup(int $iq, int $level): void
    {
        $result = (new IstIqLevelCalculator($this->canonicalBands()))->calculate($iq);

        $this->assertSame($iq, $result['iq']);
        $this->assertSame($level, $result['level']);
    }

    /** @return iterable<string, array{int, int}> */
    public static function canonicalBoundaries(): iterable
    {
        yield 'upper edge level 1' => [90, 1];
        yield 'lower edge level 2' => [91, 2];
        yield 'upper edge level 2' => [102, 2];
        yield 'lower edge level 3' => [103, 3];
        yield 'upper edge level 3' => [114, 3];
        yield 'lower edge level 4' => [115, 4];
        yield 'upper edge level 4' => [126, 4];
        yield 'lower edge level 5' => [127, 5];
    }

    public function test_non_integer_iq_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('IST IQ must be an integer.');

        (new IstIqLevelCalculator($this->canonicalBands()))->calculate('103');
    }

    /** @return array<mixed> */
    private function canonicalBands(): array
    {
        $contents = file_get_contents(dirname(__DIR__, 3).'/database/seeders/data/ist.json');
        if (! is_string($contents)) {
            throw new RuntimeException('Canonical IST data could not be read.');
        }

        $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        return $data['iq_level_bands'];
    }
}
