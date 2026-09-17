<?php

declare(strict_types=1);

namespace Tests\Integration\Psychometric;

use App\Domain\Eligibility\EligibilityZoneCalculator;
use App\Domain\Eligibility\RecommendationLabelPolicy;
use App\Services\Scoring\Dass21Scorer;
use App\Services\Scoring\Dass21ScreeningPolicy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DassEligibilityNonInterferenceTest extends TestCase
{
    public function test_normal_and_very_severe_dass_leave_identical_psychometric_eligibility_unchanged(): void
    {
        [$normalDass, $verySevereDass] = $this->canonicalDassCases();
        $psychometric = $this->identicalNonDassPsychometricInput();
        $normalCase = ['psychometric' => $psychometric, 'dass' => $normalDass];
        $verySevereCase = ['psychometric' => $psychometric, 'dass' => $verySevereDass];

        $normalEligibility = $this->eligibilityResult($normalCase['psychometric']);
        $verySevereEligibility = $this->eligibilityResult($verySevereCase['psychometric']);

        self::assertSame($normalCase['psychometric'], $verySevereCase['psychometric']);
        self::assertSame(
            ['category' => 'Normal', 'level' => 1, 'basis_scales' => ['D', 'A', 'S']],
            $normalDass['general'],
        );
        self::assertSame(
            ['category' => 'Sangat Parah', 'level' => 5, 'basis_scales' => ['D', 'A', 'S']],
            $verySevereDass['general'],
        );
        self::assertSame(['type' => 'none', 'support_offer' => false], $normalDass['follow_up']);
        self::assertSame(
            ['type' => 'referral_support_offer', 'support_offer' => true],
            $verySevereDass['follow_up'],
        );
        self::assertNotSame($normalDass, $verySevereDass);
        self::assertSame($normalEligibility['zone'], $verySevereEligibility['zone']);
        self::assertSame($normalEligibility['label'], $verySevereEligibility['label']);
        self::assertSame(
            ['OK' => 13, 'GREY' => 0, 'BELUM' => 0, 'UNASSESSED' => 5],
            $normalEligibility['zone']['zone_counts'],
        );
        self::assertSame('DISARANKAN', $normalEligibility['label']['label']);
    }

    /** @return array{array<mixed>, array<mixed>} */
    private function canonicalDassCases(): array
    {
        $data = $this->canonicalJson('dass21.json');

        if (! isset($data['items'], $data['cutoffs'], $data['multiplier'])
            || ! is_array($data['items'])
            || ! is_array($data['cutoffs'])) {
            throw new RuntimeException('Canonical DASS-21 fixture is incomplete.');
        }

        $scorer = new Dass21Scorer($data['items'], $data['cutoffs'], $data['multiplier']);
        $policy = new Dass21ScreeningPolicy(90);

        return [
            $policy->evaluate($scorer->score($this->responses($data['items'], 0)), 120),
            $policy->evaluate($scorer->score($this->responses($data['items'], 3)), 120),
        ];
    }

    /**
     * @param  array<mixed>  $items
     * @return list<array{item: int, score: int}>
     */
    private function responses(array $items, int $score): array
    {
        $responses = [];

        foreach ($items as $item) {
            if (! is_array($item) || ! isset($item['item']) || ! is_int($item['item'])) {
                throw new RuntimeException('Canonical DASS-21 items cannot form responses.');
            }

            $responses[] = ['item' => $item['item'], 'score' => $score];
        }

        return $responses;
    }

    /**
     * @return array{
     *     levels: array<string, int>,
     *     field_code: string,
     *     iq: int,
     *     validity: string
     * }
     */
    private function identicalNonDassPsychometricInput(): array
    {
        return [
            'levels' => array_fill_keys(
                ['A1', 'A2', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'D1', 'D2', 'D3', 'D4', 'D5'],
                5,
            ),
            'field_code' => 'UMUM',
            'iq' => 100,
            'validity' => 'V1',
        ];
    }

    /**
     * @param  array{levels: array<string, int>, field_code: string, iq: int, validity: string}  $psychometric
     * @return array{zone: array<mixed>, label: array<mixed>}
     */
    private function eligibilityResult(array $psychometric): array
    {
        $data = $this->canonicalJson('reporting.json');

        if (! isset($data['standard_version'], $data['base_standards'], $data['fields'])
            || ! is_string($data['standard_version'])
            || ! is_array($data['base_standards'])
            || ! is_array($data['fields'])) {
            throw new RuntimeException('Canonical eligibility fixture is incomplete.');
        }

        $zone = (new EligibilityZoneCalculator(
            $data['standard_version'],
            $data['base_standards'],
            $data['fields'],
        ))->calculate($psychometric['levels'], $psychometric['field_code']);

        return [
            'zone' => $zone,
            'label' => (new RecommendationLabelPolicy)->decide(
                $zone,
                $psychometric['iq'],
                $psychometric['validity'],
            ),
        ];
    }

    /** @return array<mixed> */
    private function canonicalJson(string $file): array
    {
        $contents = file_get_contents(dirname(__DIR__, 3).'/database/seeders/data/'.$file);

        if (! is_string($contents)) {
            throw new RuntimeException('Canonical psychometric fixture could not be read.');
        }

        $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new RuntimeException('Canonical psychometric fixture must decode to an object.');
        }

        return $data;
    }
}
