<?php

declare(strict_types=1);

namespace Tests\Integration\Eligibility;

use App\Domain\Eligibility\EligibilityZoneCalculator;
use App\Domain\Eligibility\RecommendationLabelPolicy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EligibilityStandardVersionIsolationAcceptanceTest extends TestCase
{
    public function test_g8_standard_versions_keep_their_own_results_and_provenance(): void
    {
        $canonical = $this->canonicalReporting();
        $levels = $this->allLevels(5);
        $levels['A2'] = 3;

        $oldResult = (new EligibilityZoneCalculator(
            $canonical['standard_version'],
            $canonical['base_standards'],
            $canonical['fields'],
        ))->calculate($levels, 'UMUM');
        $policy = new RecommendationLabelPolicy;
        $oldRecommendation = $this->recommendation($policy, $oldResult);
        $oldReportSnapshot = [
            'zone' => $oldResult,
            'recommendation' => $oldRecommendation,
        ];

        $newStandards = $canonical['base_standards'];
        $newStandards['A2'] = 4;
        $newResult = (new EligibilityZoneCalculator(
            'GA-2026.09',
            $newStandards,
            $canonical['fields'],
        ))->calculate($levels, 'UMUM');
        $newRecommendation = $this->recommendation($policy, $newResult);

        self::assertSame('GA-2026.08', $oldResult['standard_version']);
        self::assertSame(['level' => 3, 'standard' => 3, 'zone' => 'OK'], $oldResult['aspects']['A2']);
        self::assertSame(['OK' => 13, 'GREY' => 0, 'BELUM' => 0, 'UNASSESSED' => 5], $oldResult['zone_counts']);
        self::assertSame('DISARANKAN', $oldRecommendation['label']);
        self::assertSame('GA-2026.08', $oldRecommendation['provenance']['standard_version']);

        self::assertSame('GA-2026.09', $newResult['standard_version']);
        self::assertSame(['level' => 3, 'standard' => 4, 'zone' => 'GREY'], $newResult['aspects']['A2']);
        self::assertSame(['OK' => 12, 'GREY' => 1, 'BELUM' => 0, 'UNASSESSED' => 5], $newResult['zone_counts']);
        self::assertSame('DIPERTIMBANGKAN', $newRecommendation['label']);
        self::assertSame('GA-2026.09', $newRecommendation['provenance']['standard_version']);

        self::assertSame($oldResult['standard_version'], $oldRecommendation['provenance']['standard_version']);
        self::assertSame($newResult['standard_version'], $newRecommendation['provenance']['standard_version']);
        self::assertNotSame($oldRecommendation, $newRecommendation);
        self::assertSame($oldReportSnapshot, [
            'zone' => $oldResult,
            'recommendation' => $oldRecommendation,
        ]);
        self::assertSame($oldRecommendation, $this->recommendation($policy, $oldResult));
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
     * @param  array<mixed>  $zoneResult
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
    private function recommendation(RecommendationLabelPolicy $policy, array $zoneResult): array
    {
        $result = $policy->decide($zoneResult, 100, 'V1');

        if ($result['type'] !== 'recommendation_label') {
            self::fail('A V1 eligibility result must produce a recommendation label.');
        }

        return $result;
    }

    /**
     * @return array{
     *     standard_version: string,
     *     base_standards: array<string, int|null>,
     *     fields: list<array<mixed>>
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
            || ! isset($data['standard_version'], $data['base_standards'], $data['fields'])
            || ! is_string($data['standard_version'])
            || ! is_array($data['base_standards'])
            || ! is_array($data['fields'])
            || ! array_is_list($data['fields'])) {
            throw new RuntimeException('Canonical reporting data is incomplete.');
        }

        /** @var array<string, int|null> $baseStandards */
        $baseStandards = $data['base_standards'];
        /** @var list<array<mixed>> $fields */
        $fields = $data['fields'];

        return [
            'standard_version' => $data['standard_version'],
            'base_standards' => $baseStandards,
            'fields' => $fields,
        ];
    }
}
