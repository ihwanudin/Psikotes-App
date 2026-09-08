<?php

declare(strict_types=1);

namespace App\Domain\Eligibility;

use InvalidArgumentException;

final class RecommendationLabelPolicy
{
    /** @var list<string> */
    private const ASPECTS = ['A1', 'A2', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'D1', 'D2', 'D3', 'D4', 'D5'];

    /** @var list<string> */
    private const REQUIRED_ASSESSED_ASPECTS = ['A1', 'A2', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7'];

    /** @var list<string> */
    private const CRITICAL_ASPECTS = ['A1', 'B2', 'C4', 'C5'];

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
     * }|array{
     *     type: 'publication_blocked',
     *     publication_blocked: true,
     *     reason_code: 'VALIDITY_V3',
     *     provenance: array{standard_version: string, field_code: string, iq: int, validity: 'V3'}
     * }
     */
    public function decide(array $zoneResult, int $iq, string $validity): array
    {
        if (! in_array($validity, ['V1', 'V2', 'V3'], true)) {
            throw new InvalidArgumentException('Recommendation validity is invalid.');
        }

        $validated = $this->validate($zoneResult, $iq);

        if ($validity === 'V3') {
            return [
                'type' => 'publication_blocked',
                'publication_blocked' => true,
                'reason_code' => 'VALIDITY_V3',
                'provenance' => [
                    'standard_version' => $validated['standard_version'],
                    'field_code' => $validated['field_code'],
                    'iq' => $iq,
                    'validity' => $validity,
                ],
            ];
        }

        $belumAspects = [];
        $greyAspects = [];
        foreach (self::ASPECTS as $aspect) {
            $zone = $validated['aspects'][$aspect]['zone'];
            if ($zone === 'BELUM') {
                $belumAspects[] = $aspect;
            } elseif ($zone === 'GREY') {
                $greyAspects[] = $aspect;
            }
        }
        $criticalBelumAspects = array_values(array_intersect(self::CRITICAL_ASPECTS, $belumAspects));

        $initialLabel = match (true) {
            $criticalBelumAspects !== [], count($belumAspects) >= 3 => 'TIDAK_DISARANKAN',
            $belumAspects !== [], $greyAspects !== [] => 'DIPERTIMBANGKAN',
            default => 'DISARANKAN',
        };
        $requiresIqReview = $initialLabel === 'DISARANKAN' && $iq < 70;

        return [
            'type' => 'recommendation_label',
            'publication_blocked' => false,
            'initial_label' => $initialLabel,
            'label' => $requiresIqReview ? 'DIPERTIMBANGKAN' : $initialLabel,
            'review_required' => $requiresIqReview,
            'review_reason_codes' => $requiresIqReview ? ['IQ_BELOW_70'] : [],
            'provenance' => [
                'standard_version' => $validated['standard_version'],
                'field_code' => $validated['field_code'],
                'iq' => $iq,
                'validity' => $validity,
                'critical_belum_aspects' => $criticalBelumAspects,
                'belum_aspects' => $belumAspects,
                'grey_aspects' => $greyAspects,
                'zone_counts' => $validated['zone_counts'],
            ],
        ];
    }

    /**
     * @param  array<mixed>  $zoneResult
     * @return array{
     *     standard_version: string,
     *     field_code: string,
     *     aspects: array<string, array{level: int, standard: int|null, zone: 'OK'|'GREY'|'BELUM'|null}>,
     *     zone_counts: array{OK: int, GREY: int, BELUM: int, UNASSESSED: int}
     * }
     */
    private function validate(array $zoneResult, int $iq): array
    {
        if (! $this->hasExactKeys($zoneResult, ['standard_version', 'field_code', 'aspects', 'zone_counts'])
            || ! is_string($zoneResult['standard_version'])
            || trim($zoneResult['standard_version']) === ''
            || ! is_string($zoneResult['field_code'])
            || trim($zoneResult['field_code']) === ''
            || ! is_array($zoneResult['aspects'])
            || ! is_array($zoneResult['zone_counts'])
            || $iq < 1) {
            throw new InvalidArgumentException('Recommendation label input is invalid.');
        }

        if (! $this->hasExactKeys($zoneResult['aspects'], self::ASPECTS)) {
            throw new InvalidArgumentException('Recommendation label aspects are invalid.');
        }

        $aspects = [];
        $actualCounts = ['OK' => 0, 'GREY' => 0, 'BELUM' => 0, 'UNASSESSED' => 0];
        foreach (self::ASPECTS as $aspect) {
            $assessment = $zoneResult['aspects'][$aspect];
            if (! is_array($assessment)
                || ! $this->hasExactKeys($assessment, ['level', 'standard', 'zone'])
                || ! is_int($assessment['level'])
                || $assessment['level'] < 1
                || $assessment['level'] > 5
                || ($assessment['standard'] !== null
                    && (! is_int($assessment['standard'])
                        || $assessment['standard'] < 1
                        || $assessment['standard'] > 5))
                || ! in_array($assessment['zone'], ['OK', 'GREY', 'BELUM', null], true)) {
                throw new InvalidArgumentException('Recommendation label aspect assessment is invalid.');
            }

            $expectedZone = match (true) {
                $assessment['standard'] === null => null,
                $assessment['level'] >= $assessment['standard'] => 'OK',
                $assessment['level'] === $assessment['standard'] - 1 => 'GREY',
                default => 'BELUM',
            };
            if ($assessment['zone'] !== $expectedZone
                || (in_array($aspect, self::REQUIRED_ASSESSED_ASPECTS, true) && $assessment['zone'] === null)) {
                throw new InvalidArgumentException('Recommendation label aspect assessment is inconsistent.');
            }

            $actualCounts[$assessment['zone'] ?? 'UNASSESSED']++;
            $aspects[$aspect] = $assessment;
        }

        if (! $this->hasExactKeys($zoneResult['zone_counts'], ['OK', 'GREY', 'BELUM', 'UNASSESSED'])) {
            throw new InvalidArgumentException('Recommendation label zone counts are invalid.');
        }
        foreach ($actualCounts as $zone => $count) {
            if (! is_int($zoneResult['zone_counts'][$zone]) || $zoneResult['zone_counts'][$zone] !== $count) {
                throw new InvalidArgumentException('Recommendation label zone counts are inconsistent.');
            }
        }

        return [
            'standard_version' => $zoneResult['standard_version'],
            'field_code' => $zoneResult['field_code'],
            'aspects' => $aspects,
            'zone_counts' => $actualCounts,
        ];
    }

    /**
     * @param  array<mixed>  $value
     * @param  list<string>  $expectedKeys
     */
    private function hasExactKeys(array $value, array $expectedKeys): bool
    {
        $actualKeys = array_keys($value);
        sort($actualKeys);
        sort($expectedKeys);

        return $actualKeys === $expectedKeys;
    }
}
