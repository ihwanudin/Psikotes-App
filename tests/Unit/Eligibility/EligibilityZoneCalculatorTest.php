<?php

declare(strict_types=1);

namespace Tests\Unit\Eligibility;

use App\Domain\Eligibility\EligibilityZoneCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EligibilityZoneCalculatorTest extends TestCase
{
    public function test_kaigo_applies_versioned_base_raised_and_interest_standards(): void
    {
        $calculator = $this->calculator();
        $levels = array_fill_keys($this->aspectCodes(), 3);
        $levels['A1'] = 1;

        $result = $calculator->calculate($levels, 'KAIGO');

        $this->assertSame('GA-2026.08', $result['standard_version']);
        $this->assertSame('KAIGO', $result['field_code']);
        $this->assertSame(['level' => 1, 'standard' => 3, 'zone' => 'BELUM'], $result['aspects']['A1']);
        $this->assertSame(['level' => 3, 'standard' => 4, 'zone' => 'GREY'], $result['aspects']['C2']);
        $this->assertSame(['level' => 3, 'standard' => 4, 'zone' => 'GREY'], $result['aspects']['C3']);
        $this->assertSame(['level' => 3, 'standard' => 4, 'zone' => 'GREY'], $result['aspects']['C4']);
        $this->assertSame(['level' => 3, 'standard' => 3, 'zone' => 'OK'], $result['aspects']['D4']);
        $this->assertSame(['level' => 3, 'standard' => null, 'zone' => null], $result['aspects']['D1']);
        $this->assertSame(['OK' => 10, 'GREY' => 3, 'BELUM' => 1, 'UNASSESSED' => 4], $result['zone_counts']);
    }

    public function test_umum_applies_only_base_ability_standards(): void
    {
        $result = $this->calculator()->calculate(array_fill_keys($this->aspectCodes(), 3), 'UMUM');

        foreach (array_slice($this->aspectCodes(), 0, 13) as $code) {
            $this->assertSame(['level' => 3, 'standard' => 3, 'zone' => 'OK'], $result['aspects'][$code]);
        }
        foreach (array_slice($this->aspectCodes(), 13) as $code) {
            $this->assertSame(['level' => 3, 'standard' => null, 'zone' => null], $result['aspects'][$code]);
        }
    }

    /** @param list<string> $raised */
    #[DataProvider('fieldStandards')]
    public function test_every_field_applies_its_canonical_adjustments(string $field, ?string $interest, array $raised): void
    {
        $result = $this->calculator()->calculate(array_fill_keys($this->aspectCodes(), 5), $field);

        foreach ($result['aspects'] as $aspect => $assessment) {
            $expected = in_array($aspect, $raised, true) ? 4 : (str_starts_with($aspect, 'D') ? null : 3);
            if ($aspect === $interest) {
                $expected = 3;
            }
            $this->assertSame($expected, $assessment['standard'], "{$field}:{$aspect}");
        }
    }

    /** @return iterable<string, array{string, string|null, list<string>}> */
    public static function fieldStandards(): iterable
    {
        yield 'kaigo' => ['KAIGO', 'D4', ['C2', 'C3', 'C4']];
        yield 'kensetsu' => ['KENSETSU', 'D3', ['B2', 'C5']];
        yield 'nougyou' => ['NOUGYOU', 'D1', ['C5', 'C6']];
        yield 'seizou' => ['SEIZOU', 'D2', ['B1', 'B2', 'B4']];
        yield 'gaishoku' => ['GAISHOKU', 'D5', ['B2', 'C2', 'C3']];
        yield 'umum' => ['UMUM', null, []];
    }

    public function test_missing_extra_invalid_levels_and_unknown_field_fail_closed(): void
    {
        $calculator = $this->calculator();
        $valid = array_fill_keys($this->aspectCodes(), 3);

        foreach ([
            [array_slice($valid, 1, null, true), 'KAIGO'],
            [[...$valid, 'E1' => 3], 'KAIGO'],
            [[...$valid, 'A1' => 0], 'KAIGO'],
            [[...$valid, 'A1' => 6], 'KAIGO'],
            [[...$valid, 'A1' => '3'], 'KAIGO'],
            [$valid, 'UNKNOWN'],
        ] as [$levels, $field]) {
            try {
                $calculator->calculate($levels, $field);
                $this->fail('Invalid eligibility input was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function calculator(): EligibilityZoneCalculator
    {
        $data = $this->canonicalData();

        return new EligibilityZoneCalculator(
            $data['standard_version'],
            $data['base_standards'],
            $data['fields'],
        );
    }

    /** @return list<string> */
    private function aspectCodes(): array
    {
        return ['A1', 'A2', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'D1', 'D2', 'D3', 'D4', 'D5'];
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
