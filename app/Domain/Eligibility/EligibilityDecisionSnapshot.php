<?php

declare(strict_types=1);

namespace App\Domain\Eligibility;

use InvalidArgumentException;

final readonly class EligibilityDecisionSnapshot
{
    /** @var list<string> */
    private const ASPECTS = ['A1', 'A2', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'D1', 'D2', 'D3', 'D4', 'D5'];

    /** @var list<string> */
    private const ELIGIBILITY_SOURCE_CODES = ['ist', 'papi', 'kraepelin', 'rmib', 'reporting'];

    /**
     * @param  array<mixed>  $canonicalInput
     * @param  array<mixed>  $snapshot
     */
    private function __construct(
        private array $canonicalInput,
        private array $snapshot,
    ) {}

    /** @param array<mixed> $input */
    public static function create(array $input): self
    {
        if (! self::hasExactKeys($input, [
            'levels',
            'field_code',
            'iq',
            'validity',
            'standard_configuration',
            'eligibility_source_versions',
        ])
            || ! is_array($input['levels'])
            || ! is_string($input['field_code'])
            || ! is_int($input['iq'])
            || ! is_string($input['validity'])
            || ! is_array($input['standard_configuration'])
            || ! is_array($input['eligibility_source_versions'])) {
            throw new InvalidArgumentException('Eligibility decision snapshot input is invalid.');
        }

        $configuration = $input['standard_configuration'];
        if (! self::hasExactKeys($configuration, ['standard_version', 'base_standards', 'fields'])
            || ! is_string($configuration['standard_version'])
            || ! is_array($configuration['base_standards'])
            || ! is_array($configuration['fields'])) {
            throw new InvalidArgumentException('Eligibility decision standard configuration is invalid.');
        }

        $sourceVersions = self::validateEligibilitySourceVersions($input['eligibility_source_versions']);
        if ($sourceVersions['reporting'] !== $configuration['standard_version']) {
            throw new InvalidArgumentException('Eligibility decision standard provenance is inconsistent.');
        }

        if (! self::hasExactKeys($input['levels'], self::ASPECTS)) {
            throw new InvalidArgumentException('Eligibility decision levels are invalid.');
        }
        $canonicalLevels = [];
        foreach (self::ASPECTS as $aspect) {
            $canonicalLevels[$aspect] = $input['levels'][$aspect];
        }

        $calculator = new EligibilityZoneCalculator(
            $configuration['standard_version'],
            $configuration['base_standards'],
            $configuration['fields'],
        );
        $zone = $calculator->calculate($canonicalLevels, $input['field_code']);
        $recommendation = (new RecommendationLabelPolicy)->decide($zone, $input['iq'], $input['validity']);

        if ($zone['standard_version'] !== $sourceVersions['reporting']
            || $recommendation['provenance']['standard_version'] !== $zone['standard_version']
            || $recommendation['provenance']['field_code'] !== $zone['field_code']) {
            throw new InvalidArgumentException('Eligibility decision provenance is inconsistent.');
        }

        return new self(
            [
                'levels' => $canonicalLevels,
                'field_code' => $input['field_code'],
                'iq' => $input['iq'],
                'validity' => $input['validity'],
                'standard_configuration' => $configuration,
                'eligibility_source_versions' => $sourceVersions,
            ],
            [
                'type' => 'eligibility_decision_snapshot',
                'publication_blocked' => $recommendation['publication_blocked'],
                'zone' => $zone,
                'recommendation' => $recommendation,
                'provenance' => [
                    'eligibility_source_versions' => $sourceVersions,
                    'eligibility_standard_version' => $zone['standard_version'],
                ],
            ],
        );
    }

    /** @param array<mixed> $levels */
    public function recalculateWithLevels(array $levels): self
    {
        return self::create([
            ...$this->canonicalInput,
            'levels' => $levels,
        ]);
    }

    /** @return array<mixed> */
    public function toArray(): array
    {
        return $this->snapshot;
    }

    /**
     * @param  array<mixed>  $versions
     * @return array{ist: string, papi: string, kraepelin: string, rmib: string, reporting: string}
     */
    private static function validateEligibilitySourceVersions(array $versions): array
    {
        if (! self::hasExactKeys($versions, self::ELIGIBILITY_SOURCE_CODES)) {
            throw new InvalidArgumentException('Eligibility decision source provenance is invalid.');
        }

        $validated = [];
        foreach (self::ELIGIBILITY_SOURCE_CODES as $code) {
            $version = $versions[$code];
            if (! is_string($version) || trim($version) === '' || trim($version) !== $version) {
                throw new InvalidArgumentException('Eligibility decision source version is invalid.');
            }
            $validated[$code] = $version;
        }

        return $validated;
    }

    /**
     * @param  array<mixed>  $value
     * @param  list<string>  $expectedKeys
     */
    private static function hasExactKeys(array $value, array $expectedKeys): bool
    {
        $actualKeys = array_keys($value);
        sort($actualKeys);
        sort($expectedKeys);

        return $actualKeys === $expectedKeys;
    }
}
