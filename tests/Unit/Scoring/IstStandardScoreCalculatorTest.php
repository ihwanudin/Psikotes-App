<?php

declare(strict_types=1);

namespace Tests\Unit\Scoring;

use App\Services\Scoring\IstStandardScoreCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class IstStandardScoreCalculatorTest extends TestCase
{
    public function test_all_nine_subtests_map_domain_boundaries_from_canonical_norms(): void
    {
        $calculator = new IstStandardScoreCalculator($this->canonicalNorms());

        $this->assertSame([
            'SE' => 71,
            'WA' => 137,
            'AN' => 79,
            'RA' => 130,
            'ZR' => 85,
            'FA' => 130,
            'WU' => 75,
            'ME' => 116,
            'GE' => 142,
        ], $calculator->calculate([
            'SE' => 0,
            'WA' => 20,
            'AN' => 0,
            'RA' => 20,
            'ZR' => 0,
            'FA' => 20,
            'WU' => 0,
            'ME' => 20,
            'GE' => 32,
        ]));
    }

    public function test_corrected_se_raw_score_sixteen_maps_to_131(): void
    {
        $calculator = new IstStandardScoreCalculator(['SE' => $this->canonicalNorms()['SE']]);

        $this->assertSame(['SE' => 131], $calculator->calculate(['SE' => 16]));
    }

    public function test_scores_come_from_the_injected_norms(): void
    {
        $calculator = new IstStandardScoreCalculator([
            'SE' => [0 => 901, 1 => 902],
            'GE' => [0 => 801, 1 => 802, 2 => 803],
        ]);

        $this->assertSame(
            ['SE' => 902, 'GE' => 803],
            $calculator->calculate(['SE' => 1, 'GE' => 2]),
        );
    }

    /** @param array<mixed> $rawScores */
    #[DataProvider('invalidRawScores')]
    public function test_invalid_raw_score_payload_is_rejected(array $rawScores, string $message): void
    {
        $calculator = new IstStandardScoreCalculator([
            'SE' => [0 => 90, 1 => 100],
            'GE' => [0 => 80, 1 => 90, 2 => 100],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $calculator->calculate($rawScores);
    }

    /** @return iterable<string, array{array<mixed>, string}> */
    public static function invalidRawScores(): iterable
    {
        yield 'missing subtest' => [
            ['SE' => 1],
            'IST raw scores must contain every configured subtest exactly once.',
        ];

        yield 'unknown subtest' => [
            ['SE' => 1, 'XX' => 0],
            'IST raw score subtest is outside the supplied norms.',
        ];

        yield 'non-integer raw score' => [
            ['SE' => '1', 'GE' => 2],
            'IST raw score must be an integer.',
        ];

        yield 'below domain' => [
            ['SE' => -1, 'GE' => 2],
            'IST raw score is not present in the supplied subtest norm.',
        ];

        yield 'above domain' => [
            ['SE' => 1, 'GE' => 3],
            'IST raw score is not present in the supplied subtest norm.',
        ];
    }

    /** @param array<mixed> $norms */
    #[DataProvider('invalidNorms')]
    public function test_invalid_norm_configuration_is_rejected(array $norms): void
    {
        $this->expectException(InvalidArgumentException::class);

        new IstStandardScoreCalculator($norms);
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function invalidNorms(): iterable
    {
        yield 'empty configuration' => [[]];
        yield 'non-array subtest norm' => [['SE' => 'invalid']];
        yield 'empty subtest norm' => [['SE' => []]];
        yield 'non-contiguous raw score domain' => [['SE' => [0 => 90, 2 => 100]]];
        yield 'non-integer standard score' => [['SE' => [0 => '90']]];
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
