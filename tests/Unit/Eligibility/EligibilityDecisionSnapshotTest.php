<?php

declare(strict_types=1);

namespace Tests\Unit\Eligibility;

use App\Domain\Eligibility\EligibilityDecisionSnapshot;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class EligibilityDecisionSnapshotTest extends TestCase
{
    public function test_it_derives_zone_and_recommendation_from_canonical_inputs(): void
    {
        $input = $this->validInput();
        $input['levels']['A2'] = 2;

        $result = EligibilityDecisionSnapshot::create($input)->toArray();

        self::assertSame(['type', 'publication_blocked', 'zone', 'recommendation', 'provenance'], array_keys($result));
        self::assertSame('eligibility_decision_snapshot', $result['type']);
        self::assertFalse($result['publication_blocked']);
        self::assertSame('GA-2026.08', $result['zone']['standard_version']);
        self::assertSame('UMUM', $result['zone']['field_code']);
        self::assertSame(['level' => 2, 'standard' => 3, 'zone' => 'GREY'], $result['zone']['aspects']['A2']);
        self::assertSame('DIPERTIMBANGKAN', $result['recommendation']['label']);
        self::assertSame('GA-2026.08', $result['recommendation']['provenance']['standard_version']);
        self::assertSame('UMUM', $result['recommendation']['provenance']['field_code']);
        self::assertSame([
            'eligibility_source_versions' => $input['eligibility_source_versions'],
            'eligibility_standard_version' => 'GA-2026.08',
        ], $result['provenance']);
    }

    public function test_v3_produces_a_blocked_artifact_without_a_publishable_label(): void
    {
        $input = $this->validInput();
        $input['validity'] = 'V3';

        $result = EligibilityDecisionSnapshot::create($input)->toArray();

        self::assertTrue($result['publication_blocked']);
        self::assertSame('publication_blocked', $result['recommendation']['type']);
        self::assertSame('VALIDITY_V3', $result['recommendation']['reason_code']);
        self::assertArrayNotHasKey('label', $result['recommendation']);
        self::assertSame('V3', $result['recommendation']['provenance']['validity']);
    }

    public function test_factory_does_not_accept_caller_supplied_decision_results(): void
    {
        self::assertSame(
            ['input'],
            array_map(
                static fn ($parameter): string => $parameter->getName(),
                (new ReflectionMethod(EligibilityDecisionSnapshot::class, 'create'))->getParameters(),
            ),
        );

        $input = $this->validInput();
        $input['recommendation'] = ['label' => 'DISARANKAN'];

        $this->expectException(InvalidArgumentException::class);
        EligibilityDecisionSnapshot::create($input);
    }

    public function test_reporting_version_provenance_must_match_the_standard_configuration(): void
    {
        $input = $this->validInput();
        $input['eligibility_source_versions']['reporting'] = 'GA-FORGED';

        $this->expectException(InvalidArgumentException::class);
        EligibilityDecisionSnapshot::create($input);
    }

    public function test_eligibility_source_provenance_is_exact_and_trimmed(): void
    {
        foreach (['missing', 'extra', 'blank', 'untrimmed'] as $case) {
            $input = $this->validInput();
            switch ($case) {
                case 'missing':
                    unset($input['eligibility_source_versions']['ist']);
                    break;
                case 'extra':
                    $input['eligibility_source_versions']['unrelated'] = 'F0-2026.08';
                    break;
                case 'blank':
                    $input['eligibility_source_versions']['papi'] = '';
                    break;
                case 'untrimmed':
                    $input['eligibility_source_versions']['rmib'] = ' F0-2026.08';
                    break;
            }

            try {
                EligibilityDecisionSnapshot::create($input);
                self::fail("Invalid eligibility source provenance was accepted for {$case}.");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_missing_extra_or_invalid_levels_fail_closed(): void
    {
        foreach (['missing', 'extra', 'string'] as $case) {
            $input = $this->validInput();
            switch ($case) {
                case 'missing':
                    unset($input['levels']['A1']);
                    break;
                case 'extra':
                    $input['levels']['E1'] = 3;
                    break;
                case 'string':
                    $input['levels']['A1'] = '3';
                    break;
            }

            try {
                EligibilityDecisionSnapshot::create($input);
                self::fail("Invalid levels were accepted for {$case}.");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_all_unassessed_ability_aspects_fail_closed(): void
    {
        $input = $this->validInput();
        foreach (array_slice($this->aspectCodes(), 0, 13) as $aspect) {
            $input['standard_configuration']['base_standards'][$aspect] = null;
        }
        foreach ($input['standard_configuration']['fields'] as &$field) {
            $field['raised_to_4'] = [];
            $field['raised_standards'] = [];
        }
        unset($field);

        $this->expectException(InvalidArgumentException::class);
        EligibilityDecisionSnapshot::create($input);
    }

    public function test_nonexact_field_configuration_fails_closed(): void
    {
        $input = $this->validInput();
        $input['standard_configuration']['fields'][0]['invented'] = true;

        $this->expectException(InvalidArgumentException::class);
        EligibilityDecisionSnapshot::create($input);
    }

    /** @return array<mixed> */
    private function validInput(): array
    {
        $reporting = $this->canonicalReporting();

        return [
            'levels' => array_fill_keys($this->aspectCodes(), 5),
            'field_code' => 'UMUM',
            'iq' => 100,
            'validity' => 'V1',
            'standard_configuration' => $reporting,
            'eligibility_source_versions' => [
                'ist' => 'F0-2026.08',
                'papi' => 'F0-2026.08',
                'kraepelin' => 'F0-2026.08',
                'rmib' => 'F0-2026.08',
                'reporting' => $reporting['standard_version'],
            ],
        ];
    }

    /** @return list<string> */
    private function aspectCodes(): array
    {
        return ['A1', 'A2', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'D1', 'D2', 'D3', 'D4', 'D5'];
    }

    /** @return array{standard_version: string, base_standards: array<mixed>, fields: array<mixed>} */
    private function canonicalReporting(): array
    {
        $contents = file_get_contents(dirname(__DIR__, 3).'/database/seeders/data/reporting.json');
        if (! is_string($contents)) {
            throw new RuntimeException('Canonical reporting data could not be read.');
        }

        $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data)
            || ! is_string($data['standard_version'] ?? null)
            || ! is_array($data['base_standards'] ?? null)
            || ! is_array($data['fields'] ?? null)) {
            throw new RuntimeException('Canonical reporting data is incomplete.');
        }

        return [
            'standard_version' => $data['standard_version'],
            'base_standards' => $data['base_standards'],
            'fields' => $data['fields'],
        ];
    }
}
