<?php

declare(strict_types=1);

namespace Tests\Unit\Scoring;

use App\Services\Scoring\Dass21Scorer;
use App\Services\Scoring\Dass21ScreeningPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class Dass21ScreeningPolicyTest extends TestCase
{
    #[DataProvider('followUpCases')]
    public function test_follow_up_uses_general_level_without_score_cutoff_logic(int $stressRaw, string $category, int $level, string $type, bool $supportOffer): void
    {
        $scored = $this->score(['D' => 0, 'A' => 0, 'S' => $stressRaw]);

        $result = (new Dass21ScreeningPolicy(90))->evaluate($scored, 90);

        $this->assertSame($category, $result['general']['category']);
        $this->assertSame($level, $result['general']['level']);
        $this->assertSame($type, $result['follow_up']['type']);
        $this->assertSame($supportOffer, $result['follow_up']['support_offer']);
    }

    /** @return iterable<string, array{int, string, int, string, bool}> */
    public static function followUpCases(): iterable
    {
        yield 'normal' => [0, 'Normal', 1, 'none', false];
        yield 'mild' => [8, 'Ringan', 2, 'none', false];
        yield 'moderate' => [10, 'Sedang', 3, 'monitoring', false];
        yield 'severe' => [13, 'Parah', 4, 'referral_support_offer', true];
        yield 'extremely severe' => [17, 'Sangat Parah', 5, 'referral_support_offer', true];
    }

    public function test_duration_and_uniformity_boundaries_are_machine_readable(): void
    {
        $uniform = $this->score(['D' => 0, 'A' => 0, 'S' => 0]);
        $nonUniform = $this->score(['D' => 1, 'A' => 0, 'S' => 0]);
        $policy = new Dass21ScreeningPolicy(90);

        $at89 = $policy->evaluate($uniform, 89);
        $at90 = $policy->evaluate($nonUniform, 90);

        $this->assertSame([
            'uniform_responses' => true,
            'completion_under_minimum' => true,
        ], $at89['validity_flags']);
        $this->assertSame(['uniform_responses', 'completion_under_minimum'], $at89['active_flag_codes']);
        $this->assertSame([
            'uniform_responses' => false,
            'completion_under_minimum' => false,
        ], $at90['validity_flags']);
        $this->assertSame([], $at90['active_flag_codes']);
        $this->assertSame([
            'completion_duration_seconds' => 90,
            'minimum_completion_seconds' => 90,
            'response_count' => 21,
            'distinct_response_values' => 2,
        ], $at90['provenance']);
    }

    public function test_tied_worst_subscales_are_preserved_without_eligibility_output(): void
    {
        $scored = $this->score(['D' => 11, 'A' => 8, 'S' => 0]);

        $result = (new Dass21ScreeningPolicy(90))->evaluate($scored, 120);

        $this->assertSame(['D', 'A'], $result['general']['basis_scales']);
        $this->assertSame('referral_support_offer', $result['follow_up']['type']);
        $this->assertSame(
            ['general', 'follow_up', 'validity_flags', 'active_flag_codes', 'provenance'],
            array_keys($result),
        );
        $this->assertSame(['category', 'level', 'basis_scales'], array_keys($result['general']));
        $this->assertSame(['type', 'support_offer'], array_keys($result['follow_up']));
        $this->assertArrayNotHasKey('eligibility', $result);
        $this->assertArrayNotHasKey('zone', $result);
        $this->assertArrayNotHasKey('label', $result);
        $this->assertArrayNotHasKey('recommendation', $result);
    }

    /** @param array<mixed> $scored */
    #[DataProvider('invalidInputs')]
    public function test_invalid_scored_result_or_duration_fails_closed(array $scored, int $duration, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        (new Dass21ScreeningPolicy(90))->evaluate($scored, $duration);
    }

    /** @return iterable<string, array{array<mixed>, int, string}> */
    public static function invalidInputs(): iterable
    {
        $scored = self::scoreStatic(['D' => 0, 'A' => 0, 'S' => 0]);

        $missingGeneral = $scored;
        unset($missingGeneral['general']);
        yield 'missing general result' => [$missingGeneral, 90, 'DASS-21 scored result metadata is invalid.'];

        $badLevel = $scored;
        $badLevel['general']['level'] = 6;
        yield 'general level outside domain' => [$badLevel, 90, 'DASS-21 general level must be between one and five.'];

        $badBasis = $scored;
        $badBasis['general']['basis_scales'] = ['X'];
        yield 'unknown basis scale' => [$badBasis, 90, 'DASS-21 general basis scales are inconsistent with subscale levels.'];

        $badItemScore = $scored;
        $badItemScore['subscales']['D']['item_scores'][3] = 4;
        yield 'response outside domain' => [$badItemScore, 90, 'DASS-21 scored item provenance is invalid.'];

        yield 'negative duration' => [$scored, -1, 'DASS-21 completion duration must be non-negative.'];
    }

    public function test_invalid_minimum_duration_configuration_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('DASS-21 minimum completion duration must be positive.');

        new Dass21ScreeningPolicy(0);
    }

    /**
     * @param  array<string, int>  $rawScores
     * @return array<mixed>
     */
    private function score(array $rawScores): array
    {
        return self::scoreStatic($rawScores);
    }

    /**
     * @param  array<string, int>  $rawScores
     * @return array<mixed>
     */
    private static function scoreStatic(array $rawScores): array
    {
        $data = self::canonicalData();
        $remaining = $rawScores;
        $responses = [];

        foreach ($data['items'] as $item) {
            $value = min(3, $remaining[$item['scale']]);
            $remaining[$item['scale']] -= $value;
            $responses[] = ['item' => $item['item'], 'score' => $value];
        }

        if (array_sum($remaining) !== 0) {
            throw new RuntimeException('Requested raw score is outside the DASS-21 response domain.');
        }

        return (new Dass21Scorer($data['items'], $data['cutoffs'], $data['multiplier']))->score($responses);
    }

    /** @return array{items: array<mixed>, cutoffs: array<mixed>, multiplier: mixed} */
    private static function canonicalData(): array
    {
        $contents = file_get_contents(dirname(__DIR__, 3).'/database/seeders/data/dass21.json');

        if ($contents === false) {
            throw new RuntimeException('Unable to read canonical DASS-21 data.');
        }

        $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        return [
            'items' => $data['items'],
            'cutoffs' => $data['cutoffs'],
            'multiplier' => $data['multiplier'],
        ];
    }
}
