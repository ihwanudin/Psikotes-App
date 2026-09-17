<?php

declare(strict_types=1);

namespace Tests\Integration\Psychometric;

use App\Domain\Eligibility\EligibilityZoneCalculator;
use App\Domain\Eligibility\RecommendationLabelPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RecommendationGuardrailsAcceptanceTest extends TestCase
{
    #[DataProvider('criticalAspects')]
    public function test_t12_g1_each_critical_belum_zone_is_not_recommended(string $criticalAspect): void
    {
        $levels = $this->allLevels(5);
        $levels[$criticalAspect] = 1;

        $zone = $this->canonicalZoneResult($levels);
        $result = $this->recommendationResult($zone, 100, 'V1');

        self::assertSame(['A1', 'B2', 'C4', 'C5'], $this->canonicalReporting()['critical']);
        self::assertSame(3, $zone['aspects'][$criticalAspect]['standard']);
        self::assertSame('BELUM', $zone['aspects'][$criticalAspect]['zone']);
        self::assertSame(['OK' => 12, 'GREY' => 0, 'BELUM' => 1, 'UNASSESSED' => 5], $zone['zone_counts']);
        self::assertSame('TIDAK_DISARANKAN', $result['label']);
        self::assertSame([$criticalAspect], $result['provenance']['critical_belum_aspects']);
        self::assertSame([$criticalAspect], $result['provenance']['belum_aspects']);
    }

    /** @return iterable<string, array{string}> */
    public static function criticalAspects(): iterable
    {
        yield 'A1' => ['A1'];
        yield 'B2' => ['B2'];
        yield 'C4' => ['C4'];
        yield 'C5' => ['C5'];
    }

    public function test_iq_69_downgrades_an_otherwise_recommended_result_and_iq_70_does_not(): void
    {
        $zone = $this->canonicalZoneResult($this->allLevels(5));
        $belowBoundary = $this->recommendationResult($zone, 69, 'V1');
        $atBoundary = $this->recommendationResult($zone, 70, 'V1');

        self::assertSame(['OK' => 13, 'GREY' => 0, 'BELUM' => 0, 'UNASSESSED' => 5], $zone['zone_counts']);
        self::assertSame('DISARANKAN', $belowBoundary['initial_label']);
        self::assertSame('DIPERTIMBANGKAN', $belowBoundary['label']);
        self::assertTrue($belowBoundary['review_required']);
        self::assertSame(['IQ_BELOW_70'], $belowBoundary['review_reason_codes']);
        self::assertSame('DISARANKAN', $atBoundary['initial_label']);
        self::assertSame('DISARANKAN', $atBoundary['label']);
        self::assertFalse($atBoundary['review_required']);
        self::assertSame([], $atBoundary['review_reason_codes']);
    }

    public function test_t14_g3_v3_blocks_publication_without_a_recommendation_label(): void
    {
        $zone = $this->canonicalZoneResult($this->allLevels(5));

        $result = (new RecommendationLabelPolicy)->decide($zone, 100, 'V3');

        self::assertSame(['OK' => 13, 'GREY' => 0, 'BELUM' => 0, 'UNASSESSED' => 5], $zone['zone_counts']);
        self::assertSame([
            'type' => 'publication_blocked',
            'publication_blocked' => true,
            'reason_code' => 'VALIDITY_V3',
            'provenance' => [
                'standard_version' => 'GA-2026.08',
                'field_code' => 'UMUM',
                'iq' => 100,
                'validity' => 'V3',
            ],
        ], $result);
        self::assertArrayNotHasKey('label', $result);
        self::assertArrayNotHasKey('initial_label', $result);
    }

    public function test_t19_two_noncritical_belum_zones_are_considered_and_three_are_not_recommended(): void
    {
        $twoBelumLevels = $this->allLevels(5);
        $twoBelumLevels['A2'] = 1;
        $twoBelumLevels['B1'] = 1;
        $threeBelumLevels = $twoBelumLevels;
        $threeBelumLevels['C1'] = 1;

        $twoBelumZone = $this->canonicalZoneResult($twoBelumLevels);
        $threeBelumZone = $this->canonicalZoneResult($threeBelumLevels);
        $twoBelum = $this->recommendationResult($twoBelumZone, 100, 'V1');
        $threeBelum = $this->recommendationResult($threeBelumZone, 100, 'V1');

        self::assertSame(['OK' => 11, 'GREY' => 0, 'BELUM' => 2, 'UNASSESSED' => 5], $twoBelumZone['zone_counts']);
        self::assertSame(['A2', 'B1'], $twoBelum['provenance']['belum_aspects']);
        self::assertSame([], $twoBelum['provenance']['critical_belum_aspects']);
        self::assertSame('DIPERTIMBANGKAN', $twoBelum['label']);
        self::assertSame(['OK' => 10, 'GREY' => 0, 'BELUM' => 3, 'UNASSESSED' => 5], $threeBelumZone['zone_counts']);
        self::assertSame(['A2', 'B1', 'C1'], $threeBelum['provenance']['belum_aspects']);
        self::assertSame([], $threeBelum['provenance']['critical_belum_aspects']);
        self::assertSame('TIDAK_DISARANKAN', $threeBelum['label']);
    }

    /** @return array<string, int> */
    private function allLevels(int $level): array
    {
        return array_fill_keys(
            ['A1', 'A2', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'D1', 'D2', 'D3', 'D4', 'D5'],
            $level,
        );
    }

    /**
     * @param  array<string, int>  $levels
     * @return array{
     *     standard_version: string,
     *     field_code: string,
     *     aspects: array<string, array{level: int, standard: int|null, zone: 'OK'|'GREY'|'BELUM'|null}>,
     *     zone_counts: array{OK: int, GREY: int, BELUM: int, UNASSESSED: int}
     * }
     */
    private function canonicalZoneResult(array $levels): array
    {
        $data = $this->canonicalReporting();

        return (new EligibilityZoneCalculator(
            $data['standard_version'],
            $data['base_standards'],
            $data['fields'],
        ))->calculate($levels, 'UMUM');
    }

    /**
     * @param  array<mixed>  $zone
     * @param  'V1'|'V2'  $validity
     * @return array{
     *     type: 'recommendation_label',
     *     publication_blocked: false,
     *     initial_label: 'DISARANKAN'|'DIPERTIMBANGKAN'|'TIDAK_DISARANKAN',
     *     label: 'DISARANKAN'|'DIPERTIMBANGKAN'|'TIDAK_DISARANKAN',
     *     review_required: bool,
     *     review_reason_codes: list<string>,
     *     provenance: array{
     *         standard_version: string,
     *         field_code: string,
     *         iq: int,
     *         validity: 'V1'|'V2',
     *         critical_belum_aspects: list<string>,
     *         belum_aspects: list<string>,
     *         grey_aspects: list<string>,
     *         zone_counts: array{OK: int, GREY: int, BELUM: int, UNASSESSED: int}
     *     }
     * }
     */
    private function recommendationResult(array $zone, int $iq, string $validity): array
    {
        $result = (new RecommendationLabelPolicy)->decide($zone, $iq, $validity);

        if ($result['type'] !== 'recommendation_label') {
            self::fail('A V1/V2 acceptance fixture must produce a recommendation label.');
        }

        return $result;
    }

    /**
     * @return array{
     *     standard_version: string,
     *     base_standards: array<string, int|null>,
     *     fields: list<array<mixed>>,
     *     critical: list<string>
     * }
     */
    private function canonicalReporting(): array
    {
        $contents = file_get_contents(dirname(__DIR__, 3).'/database/seeders/data/reporting.json');

        if (! is_string($contents)) {
            throw new RuntimeException('Canonical reporting data could not be read.');
        }

        $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data)
            || ! isset($data['standard_version'], $data['base_standards'], $data['fields'], $data['critical'])
            || ! is_string($data['standard_version'])
            || ! is_array($data['base_standards'])
            || ! is_array($data['fields'])
            || ! array_is_list($data['fields'])
            || ! is_array($data['critical'])
            || ! array_is_list($data['critical'])
            || array_filter($data['critical'], static fn (mixed $aspect): bool => ! is_string($aspect)) !== []) {
            throw new RuntimeException('Canonical reporting data is incomplete.');
        }

        /** @var array<string, int|null> $baseStandards */
        $baseStandards = $data['base_standards'];
        /** @var list<array<mixed>> $fields */
        $fields = $data['fields'];
        /** @var list<string> $critical */
        $critical = $data['critical'];

        return [
            'standard_version' => $data['standard_version'],
            'base_standards' => $baseStandards,
            'fields' => $fields,
            'critical' => $critical,
        ];
    }
}
