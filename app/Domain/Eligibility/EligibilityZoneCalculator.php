<?php

declare(strict_types=1);

namespace App\Domain\Eligibility;

use InvalidArgumentException;

final readonly class EligibilityZoneCalculator
{
    /** @var list<string> */
    private const ASPECTS = ['A1', 'A2', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'D1', 'D2', 'D3', 'D4', 'D5'];

    /** @var array<string, int|null> */
    private array $baseStandards;

    /** @var array<string, array{required_interest: string|null, required_interest_standard: int|null, raised_standards: array<string, int>}> */
    private array $fields;

    /**
     * @param  array<mixed>  $baseStandards
     * @param  array<mixed>  $fields
     */
    public function __construct(
        private string $standardVersion,
        array $baseStandards,
        array $fields,
    ) {
        $configuredAspects = array_keys($baseStandards);
        sort($configuredAspects);
        $expectedAspects = self::ASPECTS;
        sort($expectedAspects);
        if (trim($this->standardVersion) === '' || $configuredAspects !== $expectedAspects) {
            throw new InvalidArgumentException('Eligibility standard configuration is invalid.');
        }

        $validatedStandards = [];
        foreach ($baseStandards as $aspect => $standard) {
            if (! is_string($aspect)
                || ! in_array($aspect, self::ASPECTS, true)
                || ($standard !== null && (! is_int($standard) || $standard < 1 || $standard > 5))) {
                throw new InvalidArgumentException('Eligibility base standard is invalid.');
            }
            $validatedStandards[$aspect] = $standard;
        }

        if (! array_is_list($fields) || $fields === []) {
            throw new InvalidArgumentException('Eligibility field configuration is invalid.');
        }

        $validatedFields = [];
        foreach ($fields as $field) {
            if (! is_array($field)
                || ! isset($field['code'], $field['raised_standards'])
                || ! array_key_exists('required_interest', $field)
                || ! array_key_exists('required_interest_standard', $field)
                || ! is_string($field['code'])
                || trim($field['code']) === ''
                || ! is_array($field['raised_standards'])
                || array_key_exists($field['code'], $validatedFields)) {
                throw new InvalidArgumentException('Eligibility field configuration is invalid.');
            }

            $requiredInterest = $field['required_interest'];
            $requiredStandard = $field['required_interest_standard'];
            if (($requiredInterest === null) !== ($requiredStandard === null)
                || ($requiredInterest !== null
                    && (! is_string($requiredInterest)
                        || ! array_key_exists($requiredInterest, $validatedStandards)
                        || ! str_starts_with($requiredInterest, 'D')
                        || ! is_int($requiredStandard)
                        || $requiredStandard < 1
                        || $requiredStandard > 5))) {
                throw new InvalidArgumentException('Eligibility required-interest standard is invalid.');
            }

            $raisedStandards = [];
            foreach ($field['raised_standards'] as $aspect => $standard) {
                if (! is_string($aspect)
                    || ! array_key_exists($aspect, $validatedStandards)
                    || $validatedStandards[$aspect] === null
                    || ! is_int($standard)
                    || $standard < 1
                    || $standard > 5) {
                    throw new InvalidArgumentException('Eligibility raised standard is invalid.');
                }
                $raisedStandards[$aspect] = $standard;
            }

            $validatedFields[$field['code']] = [
                'required_interest' => $requiredInterest,
                'required_interest_standard' => $requiredStandard,
                'raised_standards' => $raisedStandards,
            ];
        }

        $this->baseStandards = $validatedStandards;
        $this->fields = $validatedFields;
    }

    /**
     * @param  array<mixed>  $levels
     * @return array{
     *     standard_version: string,
     *     field_code: string,
     *     aspects: array<string, array{level: int, standard: int|null, zone: 'OK'|'GREY'|'BELUM'|null}>,
     *     zone_counts: array{OK: int, GREY: int, BELUM: int, UNASSESSED: int}
     * }
     */
    public function calculate(array $levels, string $fieldCode): array
    {
        if (! array_key_exists($fieldCode, $this->fields)) {
            throw new InvalidArgumentException('Eligibility field is not configured.');
        }

        $expectedAspects = self::ASPECTS;
        $suppliedAspects = array_keys($levels);
        sort($expectedAspects);
        sort($suppliedAspects);
        if ($suppliedAspects !== $expectedAspects) {
            throw new InvalidArgumentException('Eligibility levels must cover every configured aspect exactly once.');
        }
        $validatedLevels = [];
        foreach ($levels as $aspect => $level) {
            if (! is_string($aspect) || ! is_int($level) || $level < 1 || $level > 5) {
                throw new InvalidArgumentException('Eligibility aspect levels must be integers from one through five.');
            }
            $validatedLevels[$aspect] = $level;
        }

        $field = $this->fields[$fieldCode];
        $standards = $this->baseStandards;
        foreach ($field['raised_standards'] as $aspect => $standard) {
            $standards[$aspect] = $standard;
        }
        if ($field['required_interest'] !== null) {
            $standards[$field['required_interest']] = $field['required_interest_standard'];
        }

        $aspects = [];
        $counts = ['OK' => 0, 'GREY' => 0, 'BELUM' => 0, 'UNASSESSED' => 0];
        foreach ($validatedLevels as $aspect => $level) {
            if (! array_key_exists($aspect, $standards)) {
                throw new InvalidArgumentException('Eligibility standard configuration is incomplete.');
            }
            $standard = $standards[$aspect];
            $zone = match (true) {
                $standard === null => null,
                $level >= $standard => 'OK',
                $level === $standard - 1 => 'GREY',
                default => 'BELUM',
            };
            $counts[$zone ?? 'UNASSESSED']++;
            $aspects[$aspect] = [
                'level' => $level,
                'standard' => $standard,
                'zone' => $zone,
            ];
        }

        return [
            'standard_version' => $this->standardVersion,
            'field_code' => $fieldCode,
            'aspects' => $aspects,
            'zone_counts' => $counts,
        ];
    }
}
