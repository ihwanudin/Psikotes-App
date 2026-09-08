<?php

declare(strict_types=1);

namespace Tests\Unit\Scoring;

use App\Services\Scoring\IstSwLevelCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class IstSwLevelCalculatorTest extends TestCase
{
    /** @param array{int, int, int, string} $expected */
    #[DataProvider('canonicalBoundaries')]
    public function test_canonical_sw_boundaries_map_to_five_levels(int $sw, array $expected): void
    {
        $calculator = new IstSwLevelCalculator($this->canonicalBands());

        $result = $calculator->calculate($sw);

        $this->assertSame($expected, [
            $result['standard_score'],
            $result['source_score'],
            $result['level'],
            $result['category'],
        ]);
    }

    /** @return iterable<string, array{int, array{int, int, int, string}}> */
    public static function canonicalBoundaries(): iterable
    {
        yield 'upper edge kurang' => [80, [80, 2, 1, 'K (Kurang)']];
        yield 'lower edge agak kurang' => [81, [81, 3, 2, 'AK (Agak Kurang)']];
        yield 'upper edge agak kurang' => [94, [94, 4, 2, 'AK (Agak Kurang)']];
        yield 'lower edge sedang' => [95, [95, 5, 3, 'S (Sedang)']];
        yield 'upper edge sedang' => [104, [104, 6, 3, 'S (Sedang)']];
        yield 'lower edge cukup baik' => [105, [105, 7, 4, 'CB (Cukup Baik)']];
        yield 'upper edge cukup baik' => [118, [118, 8, 4, 'CB (Cukup Baik)']];
        yield 'lower edge baik' => [119, [119, 9, 5, 'B (Baik)']];
    }

    public function test_cutoffs_and_provenance_come_from_injected_bands(): void
    {
        $calculator = new IstSwLevelCalculator([
            ['score' => 50, 'lo' => 41, 'hi' => null, 'category' => 'top'],
            ['score' => 49, 'lo' => 31, 'hi' => 40, 'category' => 'upper'],
            ['score' => 48, 'lo' => 21, 'hi' => 30, 'category' => 'middle'],
            ['score' => 47, 'lo' => 11, 'hi' => 20, 'category' => 'lower'],
            ['score' => 46, 'lo' => null, 'hi' => 10, 'category' => 'bottom'],
        ]);

        $this->assertSame([
            'standard_score' => 31,
            'source_score' => 49,
            'level' => 4,
            'category' => 'upper',
            'band' => ['lo' => 31, 'hi' => 40],
        ], $calculator->calculate(31));
    }

    public function test_non_integer_standard_score_is_rejected(): void
    {
        $calculator = new IstSwLevelCalculator($this->canonicalBands());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('IST standard score must be an integer.');

        $calculator->calculate('105');
    }

    /** @param array<mixed> $bands */
    #[DataProvider('invalidBands')]
    public function test_invalid_band_configuration_is_rejected(array $bands): void
    {
        $this->expectException(InvalidArgumentException::class);

        new IstSwLevelCalculator($bands);
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function invalidBands(): iterable
    {
        yield 'empty' => [[]];
        yield 'malformed' => [[['score' => 1, 'lo' => null, 'hi' => null]]];
        yield 'wrong order' => [[
            ['score' => 1, 'lo' => null, 'hi' => 10, 'category' => 'bottom'],
            ['score' => 2, 'lo' => 11, 'hi' => null, 'category' => 'top'],
        ]];
        yield 'gap' => [[
            ['score' => 5, 'lo' => 12, 'hi' => null, 'category' => 'top'],
            ['score' => 4, 'lo' => 8, 'hi' => 10, 'category' => 'upper'],
            ['score' => 3, 'lo' => 5, 'hi' => 7, 'category' => 'middle'],
            ['score' => 2, 'lo' => 2, 'hi' => 4, 'category' => 'lower'],
            ['score' => 1, 'lo' => null, 'hi' => 1, 'category' => 'bottom'],
        ]];
        yield 'overlap' => [[
            ['score' => 5, 'lo' => 10, 'hi' => null, 'category' => 'top'],
            ['score' => 4, 'lo' => 8, 'hi' => 10, 'category' => 'upper'],
            ['score' => 3, 'lo' => 5, 'hi' => 7, 'category' => 'middle'],
            ['score' => 2, 'lo' => 2, 'hi' => 4, 'category' => 'lower'],
            ['score' => 1, 'lo' => null, 'hi' => 1, 'category' => 'bottom'],
        ]];
        yield 'only four category levels' => [[
            ['score' => 5, 'lo' => 11, 'hi' => null, 'category' => 'top'],
            ['score' => 4, 'lo' => 8, 'hi' => 10, 'category' => 'upper'],
            ['score' => 3, 'lo' => 5, 'hi' => 7, 'category' => 'middle'],
            ['score' => 2, 'lo' => 2, 'hi' => 4, 'category' => 'bottom'],
            ['score' => 1, 'lo' => null, 'hi' => 1, 'category' => 'bottom'],
        ]];
    }

    /** @return array<mixed> */
    private function canonicalBands(): array
    {
        $path = dirname(__DIR__, 3).'/database/seeders/data/ist.json';
        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new RuntimeException('Canonical IST data could not be read.');
        }

        $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data) || ! isset($data['sw_score_bands']) || ! is_array($data['sw_score_bands'])) {
            throw new RuntimeException('Canonical IST SW score bands are missing.');
        }

        return $data['sw_score_bands'];
    }
}
