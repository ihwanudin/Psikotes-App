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

    #[DataProvider('invalidConfigurationCases')]
    public function test_noncanonical_configuration_fails_closed(string $case): void
    {
        $data = $this->canonicalData();
        $version = $data['standard_version'];
        $baseStandards = $data['base_standards'];
        $fields = $data['fields'];

        switch ($case) {
            case 'blank version':
                $version = ' ';
                break;
            case 'untrimmed version':
                $version = ' '.$version;
                break;
            case 'fields not list':
                $fields = [1 => $fields[0]];
                break;
            case 'missing field':
                array_pop($fields);
                break;
            case 'extra field':
                $extra = $fields[5];
                $extra['code'] = 'EXTRA';
                $fields[] = $extra;
                break;
            case 'invented field':
                $fields[5]['code'] = 'EXTRA';
                break;
            case 'duplicate field':
                $fields[5]['code'] = $fields[0]['code'];
                break;
            case 'field item not array':
                $fields[0] = 'invalid';
                break;
            case 'extra field record key':
                $fields[0]['extra'] = true;
                break;
            case 'missing field record key':
                unset($fields[0]['raised_to_4']);
                break;
            case 'untrimmed field code':
                $fields[0]['code'] = ' KAIGO';
                break;
            case 'raised list not list':
                $fields[0]['raised_to_4'] = [1 => 'C2'];
                break;
            case 'duplicate raised list aspect':
                $fields[0]['raised_to_4'][1] = $fields[0]['raised_to_4'][0];
                break;
            case 'raised list and map mismatch':
                array_pop($fields[0]['raised_to_4']);
                break;
            case 'raised list and map order mismatch':
                [$fields[0]['raised_to_4'][0], $fields[0]['raised_to_4'][1]] = [$fields[0]['raised_to_4'][1], $fields[0]['raised_to_4'][0]];
                break;
            case 'raised map value not four':
                $fields[0]['raised_standards']['C2'] = 3;
                break;
            case 'required interest missing standard':
                $fields[0]['required_interest_standard'] = null;
                break;
            case 'required interest unknown':
                $fields[0]['required_interest'] = 'D6';
                break;
            case 'required interest is ability aspect':
                $fields[0]['required_interest'] = 'A1';
                break;
            case 'null interest with standard':
                $fields[5]['required_interest_standard'] = 3;
                break;
            case 'non-UMUM field has no interest':
                $fields[0]['required_interest'] = null;
                $fields[0]['required_interest_standard'] = null;
                break;
            case 'UMUM field has an interest':
                $fields[5]['required_interest'] = 'D1';
                $fields[5]['required_interest_standard'] = 3;
                break;
            case 'UMUM has raised standards':
                $fields[5]['raised_to_4'] = ['A1'];
                $fields[5]['raised_standards'] = ['A1' => 4];
                break;
        }

        $this->expectException(InvalidArgumentException::class);

        new EligibilityZoneCalculator($version, $baseStandards, $fields);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidConfigurationCases(): iterable
    {
        $cases = [
            'blank version',
            'untrimmed version',
            'fields not list',
            'missing field',
            'extra field',
            'invented field',
            'duplicate field',
            'field item not array',
            'extra field record key',
            'missing field record key',
            'untrimmed field code',
            'raised list not list',
            'duplicate raised list aspect',
            'raised list and map mismatch',
            'raised list and map order mismatch',
            'raised map value not four',
            'required interest missing standard',
            'required interest unknown',
            'required interest is ability aspect',
            'null interest with standard',
            'non-UMUM field has no interest',
            'UMUM field has an interest',
            'UMUM has raised standards',
        ];

        foreach ($cases as $case) {
            yield $case => [$case];
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
