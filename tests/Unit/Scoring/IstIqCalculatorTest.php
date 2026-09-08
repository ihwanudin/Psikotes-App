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
        $calculator = new IstIqCalculator($this->canonicalIqRanges(), $this->subtests());

        $this->assertSame(77, $calculator->calculate($this->rawScoresWithTotal(28)));
        $this->assertSame(132, $calculator->calculate($this->rawScoresWithTotal(151)));
    }

    public function test_iq_comes_from_the_injected_range_data(): void
    {
        $calculator = new IstIqCalculator([
            ['lo' => 0, 'hi' => 1, 'iq' => 901],
            ['lo' => 2, 'hi' => 3, 'iq' => 902],
        ], $this->subtests());

        $this->assertSame(902, $calculator->calculate($this->rawScoresWithTotal(2)));
    }

    /** @param array<mixed> $rawScores */
    #[DataProvider('invalidPayloads')]
    public function test_invalid_raw_score_payload_is_rejected(array $rawScores, string $message): void
    {
        $calculator = new IstIqCalculator($this->canonicalIqRanges(), $this->subtests());

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
     * @param  array<mixed>  $subtests
     */
    #[DataProvider('invalidConfigurations')]
    public function test_invalid_configuration_is_rejected(array $ranges, array $subtests): void
    {
        $this->expectException(InvalidArgumentException::class);

        new IstIqCalculator($ranges, $subtests);
    }

    /** @return iterable<string, array{array<mixed>, array<mixed>}> */
    public static function invalidConfigurations(): iterable
    {
        $nine = ['SE', 'WA', 'AN', 'GE', 'RA', 'ZR', 'FA', 'WU', 'ME'];

        yield 'fewer than nine subtests' => [[['lo' => 0, 'hi' => 0, 'iq' => 80]], array_slice($nine, 0, 8)];
        yield 'duplicate subtest' => [[['lo' => 0, 'hi' => 0, 'iq' => 80]], [...array_slice($nine, 0, 8), 'SE']];
        yield 'empty ranges' => [[], $nine];
        yield 'malformed range' => [[['lo' => 0, 'hi' => 1]], $nine];
        yield 'reversed range' => [[['lo' => 2, 'hi' => 1, 'iq' => 80]], $nine];
        yield 'overlap' => [[
            ['lo' => 0, 'hi' => 1, 'iq' => 80],
            ['lo' => 1, 'hi' => 2, 'iq' => 81],
        ], $nine];
        yield 'gap' => [[
            ['lo' => 0, 'hi' => 1, 'iq' => 80],
            ['lo' => 3, 'hi' => 4, 'iq' => 81],
        ], $nine];
        yield 'non-integer IQ' => [[['lo' => 0, 'hi' => 1, 'iq' => '80']], $nine];
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
}
