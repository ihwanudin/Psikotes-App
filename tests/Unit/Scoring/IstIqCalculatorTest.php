<?php

declare(strict_types=1);

namespace Tests\Unit\Scoring;

use App\Services\Scoring\IstIqCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class IstIqCalculatorTest extends TestCase
{
    public function test_canonical_iq_range_boundaries_are_exact(): void
    {
        $calculator = new IstIqCalculator($this->canonicalIqRanges(), $this->canonicalNorms());

        $this->assertSame(77, $calculator->calculate($this->rawScoresWithTotal(28)));
        $this->assertSame(132, $calculator->calculate($this->rawScoresWithTotal(151)));
    }

    public function test_iq_comes_from_the_injected_range_data(): void
    {
        $calculator = new IstIqCalculator([
            ['lo' => 0, 'hi' => 1, 'iq' => 901],
            ['lo' => 2, 'hi' => 3, 'iq' => 902],
        ], $this->syntheticNorms());

        $this->assertSame(902, $calculator->calculate($this->rawScoresWithTotal(2)));
    }

    public function test_canonical_ge_maximum_raw_score_is_accepted(): void
    {
        $calculator = new IstIqCalculator($this->canonicalIqRanges(), $this->canonicalNorms());
        $rawScores = array_fill_keys($this->subtests(), 0);
        $rawScores['GE'] = 32;

        $this->assertSame(78, $calculator->calculate($rawScores));
    }

    /** @param array<string, int> $rawScores */
    #[DataProvider('impossiblePerSubtestDistributions')]
    public function test_impossible_per_subtest_distribution_is_rejected(array $rawScores): void
    {
        $calculator = new IstIqCalculator($this->canonicalIqRanges(), $this->canonicalNorms());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('IST raw score is outside the supplied subtest norm domain.');

        $calculator->calculate($rawScores);
    }

    /** @return iterable<string, array{array<string, int>}> */
    public static function impossiblePerSubtestDistributions(): iterable
    {
        yield 'non-GE exceeds 0..20' => [[
            'SE' => 21, 'WA' => 7, 'AN' => 0, 'GE' => 0,
            'RA' => 0, 'ZR' => 0, 'FA' => 0, 'WU' => 0, 'ME' => 0,
        ]];

        yield 'GE exceeds 0..32' => [[
            'SE' => 0, 'WA' => 0, 'AN' => 0, 'GE' => 33,
            'RA' => 0, 'ZR' => 0, 'FA' => 0, 'WU' => 0, 'ME' => 0,
        ]];
    }

    /** @param array<mixed> $rawScores */
    #[DataProvider('invalidPayloads')]
    public function test_invalid_raw_score_payload_is_rejected(array $rawScores, string $message): void
    {
        $calculator = new IstIqCalculator($this->canonicalIqRanges(), $this->canonicalNorms());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $calculator->calculate($rawScores);
    }

    /** @return iterable<string, array{array<mixed>, string}> */
    public static function invalidPayloads(): iterable
    {
        yield 'missing subtest' => [[
            'SE' => 4, 'WA' => 3, 'AN' => 3, 'GE' => 3,
            'RA' => 3, 'ZR' => 3, 'FA' => 3, 'WU' => 3,
        ], 'IST raw scores must contain exactly the nine supplied subtests.'];

        yield 'unknown subtest' => [[
            'SE' => 4, 'WA' => 3, 'AN' => 3, 'GE' => 3,
            'RA' => 3, 'ZR' => 3, 'FA' => 3, 'WU' => 3, 'XX' => 3,
        ], 'IST raw score subtest is outside the supplied subtests.'];

        yield 'non-integer score' => [[
            'SE' => '4', 'WA' => 3, 'AN' => 3, 'GE' => 3,
            'RA' => 3, 'ZR' => 3, 'FA' => 3, 'WU' => 3, 'ME' => 3,
        ], 'IST raw scores used for IQ must be non-negative integers.'];

        yield 'negative score' => [[
            'SE' => -1, 'WA' => 4, 'AN' => 4, 'GE' => 4,
            'RA' => 4, 'ZR' => 4, 'FA' => 4, 'WU' => 4, 'ME' => 4,
        ], 'IST raw scores used for IQ must be non-negative integers.'];

        yield 'sum below lookup domain' => [[
            'SE' => 3, 'WA' => 3, 'AN' => 3, 'GE' => 3,
            'RA' => 3, 'ZR' => 3, 'FA' => 3, 'WU' => 3, 'ME' => 3,
        ], 'IST raw-score sum is outside the supplied IQ lookup.'];

        yield 'sum above lookup domain' => [[
            'SE' => 20, 'WA' => 20, 'AN' => 20, 'GE' => 20,
            'RA' => 20, 'ZR' => 20, 'FA' => 20, 'WU' => 20, 'ME' => 20,
        ], 'IST raw-score sum is outside the supplied IQ lookup.'];
    }

    /**
     * @param  array<mixed>  $ranges
     * @param  array<mixed>  $norms
     */
    #[DataProvider('invalidConfigurations')]
    public function test_invalid_configuration_is_rejected(array $ranges, array $norms): void
    {
        $this->expectException(InvalidArgumentException::class);

        new IstIqCalculator($ranges, $norms);
    }

    /** @return iterable<string, array{array<mixed>, array<mixed>}> */
    public static function invalidConfigurations(): iterable
    {
        $norms = array_fill_keys(['SE', 'WA', 'AN', 'GE', 'RA', 'ZR', 'FA', 'WU', 'ME'], [0 => 100]);

        yield 'fewer than nine subtests' => [[['lo' => 0, 'hi' => 0, 'iq' => 80]], array_slice($norms, 0, 8, true)];
        yield 'more than nine subtests' => [[['lo' => 0, 'hi' => 0, 'iq' => 80]], [...$norms, 'XX' => [0 => 100]]];
        yield 'malformed subtest norm' => [[['lo' => 0, 'hi' => 0, 'iq' => 80]], [...$norms, 'ME' => 'invalid']];
        yield 'gapped subtest domain' => [[['lo' => 0, 'hi' => 0, 'iq' => 80]], [...$norms, 'ME' => [0 => 90, 2 => 100]]];
        yield 'non-integer standard score' => [[['lo' => 0, 'hi' => 0, 'iq' => 80]], [...$norms, 'ME' => [0 => '100']]];
        yield 'empty ranges' => [[], $norms];
        yield 'malformed range' => [[['lo' => 0, 'hi' => 1]], $norms];
        yield 'reversed range' => [[['lo' => 2, 'hi' => 1, 'iq' => 80]], $norms];
        yield 'overlap' => [[
            ['lo' => 0, 'hi' => 1, 'iq' => 80],
            ['lo' => 1, 'hi' => 2, 'iq' => 81],
        ], $norms];
        yield 'gap' => [[
            ['lo' => 0, 'hi' => 1, 'iq' => 80],
            ['lo' => 3, 'hi' => 4, 'iq' => 81],
        ], $norms];
        yield 'non-integer IQ' => [[['lo' => 0, 'hi' => 1, 'iq' => '80']], $norms];
    }

    /** @return list<string> */
    private function subtests(): array
    {
        return ['SE', 'WA', 'AN', 'GE', 'RA', 'ZR', 'FA', 'WU', 'ME'];
    }

    /** @return array<string, int> */
    private function rawScoresWithTotal(int $total): array
    {
        $scores = array_fill_keys($this->subtests(), 0);

        foreach ($this->subtests() as $subtest) {
            $score = min(20, $total);
            $scores[$subtest] = $score;
            $total -= $score;
        }

        return $scores;
    }

    /** @return array<string, array<int, int>> */
    private function syntheticNorms(): array
    {
        return array_fill_keys($this->subtests(), array_fill(0, 21, 100));
    }

    /** @return array<mixed> */
    private function canonicalIqRanges(): array
    {
        $path = dirname(__DIR__, 3).'/database/seeders/data/ist.json';
        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new RuntimeException('Canonical IST data could not be read.');
        }

        $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data) || ! isset($data['iq_ranges']) || ! is_array($data['iq_ranges'])) {
            throw new RuntimeException('Canonical IST IQ ranges are missing.');
        }

        return $data['iq_ranges'];
    }

    /** @return array<string, array<int, int>> */
    private function canonicalNorms(): array
    {
        $path = dirname(__DIR__, 3).'/database/seeders/data/ist.json';
        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new RuntimeException('Canonical IST data could not be read.');
        }

        $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data) || ! isset($data['norms']) || ! is_array($data['norms'])) {
            throw new RuntimeException('Canonical IST norms are missing.');
        }

        return $data['norms'];
    }
}
