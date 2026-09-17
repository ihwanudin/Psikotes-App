<?php

declare(strict_types=1);

namespace Tests\Unit\Scoring;

use App\Services\Scoring\PapiLevelCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PapiLevelCalculatorTest extends TestCase
{
    public function test_every_raw_value_uses_distance_from_each_dimension_white_zone(): void
    {
        $data = $this->canonicalData();
        $calculator = new PapiLevelCalculator($data['white_zones'], $data['normalization']);

        foreach ($data['white_zones'] as $dimension => [$lo, $hi]) {
            foreach (range(0, 9) as $rawScore) {
                $distance = $rawScore < $lo ? $lo - $rawScore : ($rawScore > $hi ? $rawScore - $hi : 0);
                $expectedLevel = $data['normalization']['distance_to_level'][(string) min($distance, 4)];
                $result = $calculator->calculate($dimension, $rawScore);

                $this->assertSame($expectedLevel, $result['level'], "{$dimension}:{$rawScore}");
                $this->assertSame(in_array($dimension, $data['normalization']['excluded_from_hpp'], true), $result['hpp_excluded']);
            }
        }
    }

    public function test_w_acceptance_fixture_is_derived_from_white_zone_distance(): void
    {
        $data = $this->canonicalData();
        $calculator = new PapiLevelCalculator($data['white_zones'], $data['normalization']);

        $this->assertSame([1, 5, 3], array_map(
            static fn (int $raw): int => $calculator->calculate('W', $raw)['level'],
            [0, 5, 9],
        ));
    }

    public function test_unknown_dimension_and_out_of_domain_raw_score_fail_closed(): void
    {
        $data = $this->canonicalData();
        $calculator = new PapiLevelCalculator($data['white_zones'], $data['normalization']);

        foreach ([['Q', 5], ['W', -1], ['W', 10], ['W', '5']] as [$dimension, $raw]) {
            try {
                $calculator->calculate($dimension, $raw);
                $this->fail('Invalid PAPI input was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @return array{white_zones: array<mixed>, normalization: array<mixed>} */
    private function canonicalData(): array
    {
        $contents = file_get_contents(dirname(__DIR__, 3).'/database/seeders/data/papi.json');
        if (! is_string($contents)) {
            throw new RuntimeException('Canonical PAPI data could not be read.');
        }

        $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        return ['white_zones' => $data['white_zones'], 'normalization' => $data['normalization']];
    }
}
