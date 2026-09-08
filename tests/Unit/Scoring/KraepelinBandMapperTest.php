<?php

declare(strict_types=1);

namespace Tests\Unit\Scoring;

use App\Services\Scoring\KraepelinBandMapper;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class KraepelinBandMapperTest extends TestCase
{
    private const S1_S2_IPS_FALLBACKS = [
        'S1/S2 (IPS)' => [
            'Tianker' => 'S1/S2 (IPA)',
            'Hanker' => 'S1/S2 (IPA)',
        ],
    ];

    public function test_first_f0_golden_maps_four_factors_with_traceable_bands(): void
    {
        $result = $this->mapper()->map([
            'Panker' => 15.86,
            'Tianker' => 7,
            'Hanker' => -0.622,
            'Janker' => 7,
        ], 'S1/S2 (IPA)');

        $this->assertSame('S1/S2 (IPA)', $result['group']);
        $this->assertSame([7, 6, 4, 6], array_column($result['factors'], 'source_score'));
        $this->assertSame([4, 3, 2, 3], array_column($result['factors'], 'level'));
        $this->assertSame(['Baik', 'Sedang', 'Kurang', 'Sedang'], array_column($result['factors'], 'category'));
        $this->assertSame([
            'raw_factor' => 15.86,
            'requested_group' => 'S1/S2 (IPA)',
            'applied_group' => 'S1/S2 (IPA)',
            'direction' => 'higher_is_better',
            'band' => ['lo' => 14.973, 'hi' => 16.09],
            'source_score' => 7,
            'category' => 'Baik',
            'level' => 4,
        ], $result['factors']['Panker']);
        $this->assertSame('lower_is_better', $result['factors']['Tianker']['direction']);
    }

    public function test_second_f0_golden_derives_levels_from_canonical_category_grouping(): void
    {
        $result = $this->mapper()->map([
            'Panker' => 13.12,
            'Tianker' => 5,
            'Hanker' => 5.032,
            'Janker' => 6,
        ], 'SMA/SMK');

        $this->assertSame([7, 7, 9, 7], array_column($result['factors'], 'source_score'));
        $this->assertSame([4, 4, 5, 4], array_column($result['factors'], 'level'));
        $this->assertSame(['Baik', 'Baik', 'Baik Sekali', 'Baik'], array_column($result['factors'], 'category'));
    }

    public function test_s1_s2_ips_uses_injected_ipa_fallback_only_for_tianker_and_hanker(): void
    {
        $result = $this->mapper()->map([
            'Panker' => 15.86,
            'Tianker' => 7,
            'Hanker' => -0.622,
            'Janker' => 7,
        ], 'S1/S2 (IPS)');

        $this->assertSame(8, $result['factors']['Panker']['source_score']);
        $this->assertSame('S1/S2 (IPS)', $result['factors']['Panker']['applied_group']);
        $this->assertSame('S1/S2 (IPA)', $result['factors']['Tianker']['applied_group']);
        $this->assertSame('S1/S2 (IPA)', $result['factors']['Hanker']['applied_group']);
        $this->assertSame('S1/S2 (IPS)', $result['factors']['Janker']['applied_group']);
        $this->assertSame([8, 6, 4, 6], array_column($result['factors'], 'source_score'));
    }

    /** @param array<mixed> $factors */
    #[DataProvider('invalidPayloads')]
    public function test_invalid_factor_payload_or_group_fails_closed(array $factors, string $group, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->mapper()->map($factors, $group);
    }

    /** @return iterable<string, array{array<mixed>, string, string}> */
    public static function invalidPayloads(): iterable
    {
        $valid = ['Panker' => 10.0, 'Tianker' => 5, 'Hanker' => 1.0, 'Janker' => 4];

        yield 'missing factor' => [array_slice($valid, 0, 3, true), 'SMA/SMK', 'Kraepelin result must contain exactly Panker, Tianker, Hanker, and Janker.'];
        yield 'unknown factor' => [[...$valid, 'Other' => 2], 'SMA/SMK', 'Kraepelin result must contain exactly Panker, Tianker, Hanker, and Janker.'];
        yield 'numeric string' => [[...$valid, 'Panker' => '10'], 'SMA/SMK', 'Kraepelin factor values must be finite integers or floats.'];
        yield 'non-finite value' => [[...$valid, 'Panker' => INF], 'SMA/SMK', 'Kraepelin factor values must be finite integers or floats.'];
        yield 'unknown group' => [$valid, 'Unknown', 'Kraepelin norm group is not configured.'];
    }

    /**
     * @param  array<mixed>  $bands
     * @param  array<mixed>  $fallbacks
     */
    #[DataProvider('invalidConfigurations')]
    public function test_invalid_band_or_fallback_configuration_fails_closed(array $bands, array $fallbacks, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new KraepelinBandMapper($bands, $fallbacks);
    }

    /** @return iterable<string, array{array<mixed>, array<mixed>, string}> */
    public static function invalidConfigurations(): iterable
    {
        $bands = self::loadCanonicalBands();

        yield 'group misses factor' => [
            array_values(array_filter($bands, static fn (array $band): bool => $band['group'] !== 'SMA/SMK' || $band['factor'] !== 'Janker')),
            [],
            'Each Kraepelin norm group must define exactly four factors.',
        ];

        $gap = $bands;
        $index = self::bandIndex($gap, 'Panker', 'SMA/SMK', 9);
        $gap[$index]['lo'] = 15.486;
        yield 'gap' => [$gap, [], 'Kraepelin bands must cover their domain without gaps or overlaps.'];

        $overlap = $bands;
        $index = self::bandIndex($overlap, 'Panker', 'SMA/SMK', 9);
        $overlap[$index]['lo'] = 15.484;
        yield 'overlap' => [$overlap, [], 'Kraepelin bands must cover their domain without gaps or overlaps.'];

        $lowerEdgeDrift = $bands;
        $index = self::bandIndex($lowerEdgeDrift, 'Panker', 'SMA/SMK', 1);
        $lowerEdgeDrift[$index]['lo'] = 0;
        yield 'lower edge drifts in one group' => [$lowerEdgeDrift, [], 'Kraepelin lower domain edge must be consistent for each factor across norm groups.'];

        $missingUpperEdge = $bands;
        $index = self::bandIndex($missingUpperEdge, 'Panker', 'SMA/SMK', 10);
        $missingUpperEdge[$index]['hi'] = 100;
        yield 'missing unbounded upper edge' => [$missingUpperEdge, [], 'Kraepelin upper domain edge must be unbounded for every factor and norm group.'];

        $unorderedScores = $bands;
        $index = self::bandIndex($unorderedScores, 'Panker', 'SMA/SMK', 8);
        $unorderedScores[$index]['score'] = 7;
        yield 'non-monotonic score direction' => [$unorderedScores, [], 'Kraepelin band scores must be strictly monotonic.'];

        $conflictingCategory = $bands;
        $index = self::bandIndex($conflictingCategory, 'Panker', 'SMA/SMK', 8);
        $conflictingCategory[$index]['category'] = 'Sedang';
        yield 'category maps to conflicting levels' => [$conflictingCategory, [], 'Each Kraepelin category must map to exactly one derived level.'];

        $duplicateLevelCategory = $bands;
        $index = self::bandIndex($duplicateLevelCategory, 'Panker', 'SMA/SMK', 8);
        $duplicateLevelCategory[$index]['category'] = 'Also Baik';
        yield 'level maps to conflicting categories' => [$duplicateLevelCategory, [], 'Each Kraepelin category must map to exactly one derived level.'];

        yield 'fallback target missing' => [
            $bands,
            ['S1/S2 (IPS)' => ['Hanker' => 'Unknown']],
            'Kraepelin group fallback references an unknown group.',
        ];
    }

    private function mapper(): KraepelinBandMapper
    {
        return new KraepelinBandMapper(self::loadCanonicalBands(), self::S1_S2_IPS_FALLBACKS);
    }

    /** @return array<mixed> */
    private static function loadCanonicalBands(): array
    {
        $contents = file_get_contents(dirname(__DIR__, 3).'/database/seeders/data/kraepelin.json');

        if ($contents === false) {
            throw new RuntimeException('Unable to read canonical Kraepelin data.');
        }

        $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        return $data['score_bands'];
    }

    /** @param array<mixed> $bands */
    private static function bandIndex(array $bands, string $factor, string $group, int $score): int
    {
        foreach ($bands as $index => $band) {
            if ($band['factor'] === $factor && $band['group'] === $group && $band['score'] === $score) {
                return $index;
            }
        }

        throw new RuntimeException('Canonical band not found.');
    }
}
