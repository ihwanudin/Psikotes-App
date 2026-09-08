<?php

declare(strict_types=1);

namespace Tests\Integration\Psychometric;

use App\Domain\Eligibility\EligibilityZoneCalculator;
use App\Domain\Eligibility\RecommendationLabelPolicy;
use App\Domain\Review\ProfessionalOverridePolicy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProfessionalLevelOverrideRecalculationTest extends TestCase
{
    public function test_changed_critical_level_override_recalculates_zone_and_label_from_final_levels(): void
    {
        $systemLevels = $this->allLevels(5);
        $systemLevels['A1'] = 1;
        $systemZone = $this->canonicalZoneResult($systemLevels);
        $systemRecommendation = $this->recommendation($systemZone);

        $override = (new ProfessionalOverridePolicy)->levelOverride([
            'aspect' => 'A1',
            'system_level' => 1,
            'final_level' => 3,
            'reason' => 'Observasi profesional mendukung level akhir tiga.',
        ]);

        self::assertSame(['A1', 'B2', 'C4', 'C5'], $this->canonicalReporting()['critical']);
        self::assertSame(3, $systemZone['aspects']['A1']['standard']);
        self::assertSame('BELUM', $systemZone['aspects']['A1']['zone']);
        self::assertSame(['OK' => 12, 'GREY' => 0, 'BELUM' => 1, 'UNASSESSED' => 5], $systemZone['zone_counts']);
        self::assertSame('TIDAK_DISARANKAN', $systemRecommendation['label']);
        self::assertSame(['A1'], $systemRecommendation['provenance']['critical_belum_aspects']);
        self::assertSame(1, $override['system_level']);
        self::assertSame(3, $override['final_level']);
        self::assertTrue($override['changed']);
        self::assertTrue($override['audit_required']);
        self::assertTrue($override['recalculation_required']);

        $finalLevels = $systemLevels;
        $finalLevels[$override['provenance']['aspect']] = $override['final_level'];
        $finalZone = $this->canonicalZoneResult($finalLevels);
        $finalRecommendation = $this->recommendation($finalZone);

        self::assertSame(1, $systemLevels['A1']);
        self::assertSame(3, $finalLevels['A1']);
        self::assertSame('OK', $finalZone['aspects']['A1']['zone']);
        self::assertSame(['OK' => 13, 'GREY' => 0, 'BELUM' => 0, 'UNASSESSED' => 5], $finalZone['zone_counts']);
        self::assertSame('DISARANKAN', $finalRecommendation['label']);
        self::assertSame([], $finalRecommendation['provenance']['critical_belum_aspects']);
    }

    public function test_label_only_override_does_not_signal_or_mutate_level_zones(): void
    {
        $systemLevels = $this->allLevels(5);
        $systemLevels['A1'] = 1;
        $systemZone = $this->canonicalZoneResult($systemLevels);
        $zoneSnapshot = $systemZone;
        $systemRecommendation = $this->recommendation($systemZone);

        $override = (new ProfessionalOverridePolicy)->labelOverride([
            'system_label' => $systemRecommendation['label'],
            'final_label' => 'DIPERTIMBANGKAN',
            'reason' => 'Pertimbangan profesional memerlukan pendampingan.',
        ]);

        self::assertSame('TIDAK_DISARANKAN', $override['system_label']);
        self::assertSame('DIPERTIMBANGKAN', $override['final_label']);
        self::assertTrue($override['changed']);
        self::assertTrue($override['audit_required']);
        self::assertFalse($override['recalculation_required']);
        self::assertSame([
            'policy' => 'G6',
            'override_type' => 'label',
            'aspect' => null,
            'reason_character_count' => 49,
        ], $override['provenance']);
        self::assertArrayNotHasKey('levels', $override);
        self::assertSame($zoneSnapshot, $systemZone);
        self::assertSame('BELUM', $systemZone['aspects']['A1']['zone']);
        self::assertSame(['OK' => 12, 'GREY' => 0, 'BELUM' => 1, 'UNASSESSED' => 5], $systemZone['zone_counts']);
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
        $reporting = $this->canonicalReporting();

        return (new EligibilityZoneCalculator(
            $reporting['standard_version'],
            $reporting['base_standards'],
            $reporting['fields'],
        ))->calculate($levels, 'UMUM');
    }

    /**
     * @param  array<mixed>  $zone
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
    private function recommendation(array $zone): array
    {
        $result = (new RecommendationLabelPolicy)->decide($zone, 100, 'V1');

        if ($result['type'] !== 'recommendation_label') {
            self::fail('A V1 fixture must produce a recommendation label.');
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
