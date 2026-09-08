<?php

declare(strict_types=1);

namespace Tests\Unit\Eligibility;

use App\Domain\Eligibility\EligibilityZoneCalculator;
use App\Domain\Eligibility\RecommendationLabelPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class RecommendationLabelPolicyTest extends TestCase
{
    #[DataProvider('criticalAspects')]
    public function test_any_critical_belum_zone_is_not_recommended(string $criticalAspect): void
    {
        $levels = $this->allLevels(5);
        $levels[$criticalAspect] = 1;

        $result = (new RecommendationLabelPolicy)->decide($this->zoneResult($levels), 100, 'V1');

        $this->assertSame('recommendation_label', $result['type']);
        $this->assertSame('TIDAK_DISARANKAN', $result['label']);
        $this->assertSame([$criticalAspect], $result['provenance']['critical_belum_aspects']);
    }

    /** @return iterable<string, array{string}> */
    public static function criticalAspects(): iterable
    {
        yield 'A1' => ['A1'];
        yield 'B2' => ['B2'];
        yield 'C4' => ['C4'];
        yield 'C5' => ['C5'];
    }

    public function test_three_non_critical_belum_zones_are_not_recommended(): void
    {
        $levels = $this->allLevels(5);
        $levels['A2'] = 1;
        $levels['B1'] = 1;
        $levels['C1'] = 1;

        $result = (new RecommendationLabelPolicy)->decide($this->zoneResult($levels), 100, 'V1');

        $this->assertSame('recommendation_label', $result['type']);
        $this->assertSame('TIDAK_DISARANKAN', $result['label']);
        $this->assertSame(['A2', 'B1', 'C1'], $result['provenance']['belum_aspects']);
    }

    public function test_one_or_two_belum_and_any_grey_are_considered(): void
    {
        $oneBelum = $this->allLevels(5);
        $oneBelum['A2'] = 1;
        $twoBelum = $oneBelum;
        $twoBelum['B1'] = 1;
        $grey = $this->allLevels(5);
        $grey['A2'] = 2;

        $policy = new RecommendationLabelPolicy;

        $oneBelumResult = $policy->decide($this->zoneResult($oneBelum), 100, 'V1');
        $twoBelumResult = $policy->decide($this->zoneResult($twoBelum), 100, 'V1');
        $greyResult = $policy->decide($this->zoneResult($grey), 100, 'V1');

        $this->assertSame('recommendation_label', $oneBelumResult['type']);
        $this->assertSame('recommendation_label', $twoBelumResult['type']);
        $this->assertSame('recommendation_label', $greyResult['type']);
        $this->assertSame('DIPERTIMBANGKAN', $oneBelumResult['label']);
        $this->assertSame('DIPERTIMBANGKAN', $twoBelumResult['label']);
        $this->assertSame('DIPERTIMBANGKAN', $greyResult['label']);
    }

    public function test_all_assessed_aspects_ok_are_recommended(): void
    {
        $zoneResult = $this->zoneResult($this->allLevels(5), 'UMUM');

        $result = (new RecommendationLabelPolicy)->decide($zoneResult, 100, 'V2');

        $this->assertSame('recommendation_label', $result['type']);
        $this->assertSame('DISARANKAN', $result['initial_label']);
        $this->assertSame('DISARANKAN', $result['label']);
        $this->assertFalse($result['review_required']);
        $this->assertSame([], $result['review_reason_codes']);
        $this->assertSame([
            'standard_version' => 'GA-2026.08',
            'field_code' => 'UMUM',
            'iq' => 100,
            'validity' => 'V2',
            'critical_belum_aspects' => [],
            'belum_aspects' => [],
            'grey_aspects' => [],
            'zone_counts' => ['OK' => 13, 'GREY' => 0, 'BELUM' => 0, 'UNASSESSED' => 5],
        ], $result['provenance']);
    }

    public function test_iq_below_70_downgrades_only_an_initial_recommended_label_and_flags_review(): void
    {
        $policy = new RecommendationLabelPolicy;
        $allOk = $this->zoneResult($this->allLevels(5));

        $belowBoundary = $policy->decide($allOk, 69, 'V1');
        $atBoundary = $policy->decide($allOk, 70, 'V1');

        $this->assertSame('recommendation_label', $belowBoundary['type']);
        $this->assertSame('recommendation_label', $atBoundary['type']);
        $this->assertSame('DISARANKAN', $belowBoundary['initial_label']);
        $this->assertSame('DIPERTIMBANGKAN', $belowBoundary['label']);
        $this->assertTrue($belowBoundary['review_required']);
        $this->assertSame(['IQ_BELOW_70'], $belowBoundary['review_reason_codes']);
        $this->assertSame('DISARANKAN', $atBoundary['label']);
        $this->assertFalse($atBoundary['review_required']);
    }

    public function test_low_iq_does_not_replace_a_more_conservative_initial_label(): void
    {
        $levels = $this->allLevels(5);
        $levels['A2'] = 1;

        $result = (new RecommendationLabelPolicy)->decide($this->zoneResult($levels), 69, 'V1');

        $this->assertSame('recommendation_label', $result['type']);
        $this->assertSame('DIPERTIMBANGKAN', $result['initial_label']);
        $this->assertSame('DIPERTIMBANGKAN', $result['label']);
        $this->assertFalse($result['review_required']);
        $this->assertSame([], $result['review_reason_codes']);
    }

    public function test_v3_returns_a_typed_publication_block_without_a_label(): void
    {
        $result = (new RecommendationLabelPolicy)->decide(
            $this->zoneResult($this->allLevels(5)),
            100,
            'V3',
        );

        $this->assertSame([
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
    }

    public function test_public_api_has_no_dass_parameter_and_rejects_embedded_dass_data(): void
    {
        $parameters = (new ReflectionMethod(RecommendationLabelPolicy::class, 'decide'))->getParameters();
        $this->assertSame(['zoneResult', 'iq', 'validity'], array_map(static fn ($parameter) => $parameter->getName(), $parameters));

        $zoneResult = $this->zoneResult($this->allLevels(5));
        $zoneResult['dass'] = ['general_level' => 5];

        $this->expectException(InvalidArgumentException::class);
        (new RecommendationLabelPolicy)->decide($zoneResult, 100, 'V1');
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_payloads_fail_closed(string $mutation, mixed $value): void
    {
        $zoneResult = $this->zoneResult($this->allLevels(5));

        switch ($mutation) {
            case 'top_level':
                $zoneResult[$value] = 'unexpected';
                break;
            case 'standard_version':
                $zoneResult['standard_version'] = $value;
                break;
            case 'missing_aspect':
                unset($zoneResult['aspects']['A1']);
                break;
            case 'extra_aspect':
                $zoneResult['aspects']['E1'] = $zoneResult['aspects']['A1'];
                break;
            case 'level':
                $zoneResult['aspects']['A1']['level'] = $value;
                break;
            case 'standard':
                $zoneResult['aspects']['A1']['standard'] = $value;
                break;
            case 'zone':
                $zoneResult['aspects']['A1']['zone'] = $value;
                break;
            case 'counts':
                $zoneResult['zone_counts']['OK'] = $value;
                break;
        }

        $this->expectException(InvalidArgumentException::class);
        (new RecommendationLabelPolicy)->decide($zoneResult, 100, 'V1');
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidPayloads(): iterable
    {
        yield 'unexpected top-level field' => ['top_level', 'eligibility'];
        yield 'empty standard version' => ['standard_version', ''];
        yield 'missing aspect' => ['missing_aspect', null];
        yield 'extra aspect' => ['extra_aspect', null];
        yield 'non-integer level' => ['level', '5'];
        yield 'level outside domain' => ['level', 6];
        yield 'invalid standard' => ['standard', 0];
        yield 'invalid zone' => ['zone', 'RED'];
        yield 'inconsistent zone' => ['zone', 'GREY'];
        yield 'mismatched counts' => ['counts', 0];
    }

    #[DataProvider('invalidScalarInputs')]
    public function test_invalid_iq_and_validity_fail_closed(int $iq, string $validity): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new RecommendationLabelPolicy)->decide($this->zoneResult($this->allLevels(5)), $iq, $validity);
    }

    /** @return iterable<string, array{int, string}> */
    public static function invalidScalarInputs(): iterable
    {
        yield 'zero IQ' => [0, 'V1'];
        yield 'negative IQ' => [-1, 'V1'];
        yield 'unknown validity' => [100, 'V4'];
        yield 'lowercase validity' => [100, 'v1'];
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
    private function zoneResult(array $levels, string $field = 'UMUM'): array
    {
        $data = $this->canonicalData();

        return (new EligibilityZoneCalculator(
            $data['standard_version'],
            $data['base_standards'],
            $data['fields'],
        ))->calculate($levels, $field);
    }

    /** @return array<mixed> */
    private function canonicalData(): array
    {
        $contents = file_get_contents(dirname(__DIR__, 3).'/database/seeders/data/reporting.json');
        if (! is_string($contents)) {
            throw new RuntimeException('Canonical reporting data could not be read.');
        }

        return json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    }
}
