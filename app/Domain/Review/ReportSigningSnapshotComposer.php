<?php

declare(strict_types=1);

namespace App\Domain\Review;

use InvalidArgumentException;

final readonly class ReportSigningSnapshotComposer
{
    /** @var list<string> */
    private const ASPECTS = ['A1', 'A2', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'D1', 'D2', 'D3', 'D4', 'D5'];

    /** @var list<string> */
    private const LABELS = ['DISARANKAN', 'DIPERTIMBANGKAN', 'TIDAK_DISARANKAN'];

    /**
     * @param  array<mixed>  $prerequisiteInput
     * @param  array<mixed>  $snapshotProvenance
     */
    private function __construct(
        private array $prerequisiteInput,
        private array $snapshotProvenance,
    ) {}

    /** @param array<mixed> $input */
    public static function compose(array $input): self
    {
        if (! self::hasExactKeys($input, [
            'recommendation',
            'discrepancies',
            'overrides',
            'procedure_note',
            'accompaniment_conditions',
            'target_field',
            'narrative_clusters',
        ])
            || ! is_array($input['recommendation'])
            || ! is_array($input['discrepancies'])
            || ! array_is_list($input['discrepancies'])
            || ! is_array($input['overrides'])
            || ! array_is_list($input['overrides'])
            || ($input['procedure_note'] !== null && ! is_string($input['procedure_note']))
            || ($input['accompaniment_conditions'] !== null && ! is_string($input['accompaniment_conditions']))
            || ($input['target_field'] !== null && ! is_string($input['target_field']))
            || ! is_array($input['narrative_clusters'])
            || ! self::hasExactKeys($input['narrative_clusters'], ['A', 'B', 'C', 'D'])) {
            throw new InvalidArgumentException('Report signing snapshot input is invalid.');
        }

        foreach ($input['narrative_clusters'] as $narrative) {
            if ($narrative !== null && ! is_string($narrative)) {
                throw new InvalidArgumentException('Report signing snapshot narrative is invalid.');
            }
        }

        [$validity, $label, $recommendationType] = self::validateRecommendation($input['recommendation']);
        [$unresolvedAspects, $discrepancyAspects] = self::validateDiscrepancies($input['discrepancies']);
        [$overrides, $finalLabel] = self::validateOverrides($input['overrides'], $validity, $label);

        return new self(
            [
                'validity' => $validity,
                'procedure_note' => $input['procedure_note'],
                'label' => $finalLabel,
                'accompaniment_conditions' => $input['accompaniment_conditions'],
                'unresolved_g7_aspects' => $unresolvedAspects,
                'overrides' => $overrides,
                'target_field' => $input['target_field'],
                'narrative_clusters' => $input['narrative_clusters'],
            ],
            [
                'type' => 'report_signing_snapshot',
                'recommendation_type' => $recommendationType,
                'discrepancy_aspects' => $discrepancyAspects,
                'changed_override_count' => count($overrides),
                'audit_evidence_complete' => true,
                'recalculation_evidence_complete' => true,
            ],
        );
    }

    /** @return array<mixed> */
    public function prerequisiteInput(): array
    {
        return $this->prerequisiteInput;
    }

    /** @return array<mixed> */
    public function provenance(): array
    {
        return $this->snapshotProvenance;
    }

    /**
     * @param  array<mixed>  $recommendation
     * @return array{0: 'V1'|'V2'|'V3', 1: 'DISARANKAN'|'DIPERTIMBANGKAN'|'TIDAK_DISARANKAN'|null, 2: 'recommendation_label'|'publication_blocked'}
     */
    private static function validateRecommendation(array $recommendation): array
    {
        if (($recommendation['type'] ?? null) === 'publication_blocked') {
            if (! self::hasExactKeys($recommendation, ['type', 'publication_blocked', 'reason_code', 'provenance'])
                || $recommendation['publication_blocked'] !== true
                || $recommendation['reason_code'] !== 'VALIDITY_V3'
                || ! is_array($recommendation['provenance'])
                || ! self::hasExactKeys($recommendation['provenance'], ['standard_version', 'field_code', 'iq', 'validity'])
                || ! self::validRecommendationProvenance($recommendation['provenance'], 'V3')) {
                throw new InvalidArgumentException('Blocked recommendation output is invalid.');
            }

            return ['V3', null, 'publication_blocked'];
        }

        if (! self::hasExactKeys($recommendation, [
            'type',
            'publication_blocked',
            'initial_label',
            'label',
            'review_required',
            'review_reason_codes',
            'provenance',
        ])
            || $recommendation['type'] !== 'recommendation_label'
            || $recommendation['publication_blocked'] !== false
            || ! is_string($recommendation['initial_label'])
            || ! in_array($recommendation['initial_label'], self::LABELS, true)
            || ! is_string($recommendation['label'])
            || ! in_array($recommendation['label'], self::LABELS, true)
            || ! is_bool($recommendation['review_required'])
            || ! is_array($recommendation['review_reason_codes'])
            || ! array_is_list($recommendation['review_reason_codes'])
            || ! is_array($recommendation['provenance'])
            || ! self::hasExactKeys($recommendation['provenance'], [
                'standard_version',
                'field_code',
                'iq',
                'validity',
                'critical_belum_aspects',
                'belum_aspects',
                'grey_aspects',
                'zone_counts',
            ])) {
            throw new InvalidArgumentException('Recommendation label output is invalid.');
        }

        $validity = $recommendation['provenance']['validity'];
        if (! in_array($validity, ['V1', 'V2'], true)
            || ! self::validRecommendationProvenance($recommendation['provenance'], $validity)) {
            throw new InvalidArgumentException('Recommendation label output is inconsistent.');
        }

        $belumAspects = $recommendation['provenance']['belum_aspects'];
        $greyAspects = $recommendation['provenance']['grey_aspects'];
        $expectedCritical = array_values(array_intersect(['A1', 'B2', 'C4', 'C5'], $belumAspects));
        $expectedInitialLabel = match (true) {
            $expectedCritical !== [], count($belumAspects) >= 3 => 'TIDAK_DISARANKAN',
            $belumAspects !== [], $greyAspects !== [] => 'DIPERTIMBANGKAN',
            default => 'DISARANKAN',
        };
        if ($recommendation['provenance']['critical_belum_aspects'] !== $expectedCritical
            || $recommendation['provenance']['zone_counts']['BELUM'] !== count($belumAspects)
            || $recommendation['provenance']['zone_counts']['GREY'] !== count($greyAspects)
            || $recommendation['initial_label'] !== $expectedInitialLabel
            || ($recommendation['review_required']
                ? ($recommendation['review_reason_codes'] !== ['IQ_BELOW_70']
                    || $recommendation['initial_label'] !== 'DISARANKAN'
                    || $recommendation['label'] !== 'DIPERTIMBANGKAN'
                    || $recommendation['provenance']['iq'] >= 70)
                : ($recommendation['review_reason_codes'] !== []
                    || $recommendation['label'] !== $recommendation['initial_label']))) {
            throw new InvalidArgumentException('Recommendation label output is inconsistent.');
        }

        return [$validity, $recommendation['label'], 'recommendation_label'];
    }

    /** @param array<mixed> $provenance */
    private static function validRecommendationProvenance(array $provenance, string $validity): bool
    {
        if (! is_string($provenance['standard_version'] ?? null)
            || trim($provenance['standard_version']) === ''
            || ! is_string($provenance['field_code'] ?? null)
            || trim($provenance['field_code']) === ''
            || ! is_int($provenance['iq'] ?? null)
            || $provenance['iq'] < 1
            || ($provenance['validity'] ?? null) !== $validity) {
            return false;
        }

        if ($validity === 'V3') {
            return true;
        }

        foreach (['critical_belum_aspects', 'belum_aspects', 'grey_aspects'] as $key) {
            if (! is_array($provenance[$key] ?? null)
                || ! array_is_list($provenance[$key])
                || array_filter($provenance[$key], static fn (mixed $aspect): bool => ! is_string($aspect) || ! in_array($aspect, self::ASPECTS, true)) !== []
                || $provenance[$key] !== array_values(array_intersect(self::ASPECTS, $provenance[$key]))) {
                return false;
            }
        }

        return is_array($provenance['zone_counts'] ?? null)
            && self::hasExactKeys($provenance['zone_counts'], ['OK', 'GREY', 'BELUM', 'UNASSESSED'])
            && array_sum($provenance['zone_counts']) === count(self::ASPECTS)
            && array_filter($provenance['zone_counts'], static fn (mixed $count): bool => ! is_int($count) || $count < 0) === [];
    }

    /**
     * @param  list<mixed>  $discrepancies
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function validateDiscrepancies(array $discrepancies): array
    {
        if (count($discrepancies) !== count(self::ASPECTS)) {
            throw new InvalidArgumentException('Every aspect needs discrepancy evidence.');
        }

        $seen = [];
        $unresolved = [];
        foreach ($discrepancies as $evidence) {
            if (! is_array($evidence)
                || ! self::hasExactKeys($evidence, ['result', 'review_resolved'])
                || ! is_bool($evidence['review_resolved'])
                || ! is_array($evidence['result'])) {
                throw new InvalidArgumentException('Discrepancy evidence is invalid.');
            }

            $result = $evidence['result'];
            if (! self::hasExactKeys($result, ['type', 'review_required', 'automatic_narrative_allowed', 'reason_code', 'provenance'])
                || $result['type'] !== 'aspect_source_discrepancy'
                || ! is_bool($result['review_required'])
                || ! is_bool($result['automatic_narrative_allowed'])
                || ! is_array($result['provenance'])
                || ! self::hasExactKeys($result['provenance'], ['aspect', 'sources', 'minimum_level', 'maximum_level', 'spread'])) {
                throw new InvalidArgumentException('Discrepancy output is invalid.');
            }

            $provenance = $result['provenance'];
            $aspect = $provenance['aspect'];
            $sources = $provenance['sources'];
            if (! is_string($aspect)
                || ! in_array($aspect, self::ASPECTS, true)
                || isset($seen[$aspect])
                || ! is_array($sources)
                || ! array_is_list($sources)
                || $sources === []) {
                throw new InvalidArgumentException('Discrepancy provenance is invalid.');
            }

            $levels = [];
            $sourceNames = [];
            foreach ($sources as $source) {
                if (! is_array($source)
                    || ! self::hasExactKeys($source, ['source', 'level'])
                    || ! is_string($source['source'])
                    || trim($source['source']) === ''
                    || $source['source'] !== trim($source['source'])
                    || isset($sourceNames[$source['source']])
                    || ! is_int($source['level'])
                    || $source['level'] < 1
                    || $source['level'] > 5) {
                    throw new InvalidArgumentException('Discrepancy sources are invalid.');
                }
                $sourceNames[$source['source']] = true;
                $levels[] = $source['level'];
            }
            $sortedSources = $sources;
            usort($sortedSources, static fn (array $left, array $right): int => strcmp($left['source'], $right['source']));
            $minimum = min($levels);
            $maximum = max($levels);
            $spread = $maximum - $minimum;
            $reviewRequired = $spread >= 2;
            if ($sources !== $sortedSources
                || $provenance['minimum_level'] !== $minimum
                || $provenance['maximum_level'] !== $maximum
                || $provenance['spread'] !== $spread
                || $result['review_required'] !== $reviewRequired
                || $result['automatic_narrative_allowed'] !== ! $reviewRequired
                || $result['reason_code'] !== ($reviewRequired ? 'SOURCE_LEVEL_SPREAD' : null)
                || (! $reviewRequired && $evidence['review_resolved'])) {
                throw new InvalidArgumentException('Discrepancy evidence is inconsistent.');
            }

            $seen[$aspect] = true;
            if ($reviewRequired && ! $evidence['review_resolved']) {
                $unresolved[] = $aspect;
            }
        }

        $aspects = array_keys($seen);
        sort($aspects);
        $expected = self::ASPECTS;
        sort($expected);
        if ($aspects !== $expected) {
            throw new InvalidArgumentException('Discrepancy aspect coverage is invalid.');
        }
        sort($unresolved);

        return [$unresolved, self::ASPECTS];
    }

    /**
     * @param  list<mixed>  $overrideEvidence
     * @param  'V1'|'V2'|'V3'  $validity
     * @param  'DISARANKAN'|'DIPERTIMBANGKAN'|'TIDAK_DISARANKAN'|null  $label
     * @return array{0: list<array{type: 'level'|'label', aspect: string|null, reason: string}>, 1: 'DISARANKAN'|'DIPERTIMBANGKAN'|'TIDAK_DISARANKAN'|null}
     */
    private static function validateOverrides(array $overrideEvidence, string $validity, ?string $label): array
    {
        $projected = [];
        $seen = [];
        foreach ($overrideEvidence as $evidence) {
            if (! is_array($evidence)
                || ! self::hasExactKeys($evidence, ['result', 'audit_recorded', 'recalculation_completed'])
                || ! is_array($evidence['result'])
                || ! is_bool($evidence['audit_recorded'])
                || ! is_bool($evidence['recalculation_completed'])) {
                throw new InvalidArgumentException('Override evidence is invalid.');
            }

            $result = $evidence['result'];
            if (($result['provenance']['override_type'] ?? null) === 'level') {
                [$changed, $identity, $projection] = self::validateLevelOverride($result);
            } elseif (($result['provenance']['override_type'] ?? null) === 'label') {
                [$changed, $identity, $projection] = self::validateLabelOverride($result);
                if ($changed) {
                    if ($validity === 'V3' || $result['system_label'] !== $label) {
                        throw new InvalidArgumentException('Professional label override does not match the recommendation.');
                    }
                    $label = $result['final_label'];
                }
            } else {
                throw new InvalidArgumentException('Professional override output is invalid.');
            }

            if (isset($seen[$identity])
                || $result['audit_required'] !== $changed
                || ($changed && ! $evidence['audit_recorded'])
                || (! $changed && $evidence['audit_recorded'])
                || ($result['recalculation_required'] && ! $evidence['recalculation_completed'])
                || (! $result['recalculation_required'] && $evidence['recalculation_completed'])) {
                throw new InvalidArgumentException('Professional override evidence is inconsistent.');
            }
            $seen[$identity] = true;
            if ($changed) {
                $projected[] = $projection;
            }
        }

        usort($projected, static fn (array $left, array $right): int => [$left['type'], $left['aspect']] <=> [$right['type'], $right['aspect']]);

        return [$projected, $label];
    }

    /**
     * @param  array<mixed>  $result
     * @return array{0: bool, 1: string, 2: array{type: 'level', aspect: string, reason: string|null}}
     */
    private static function validateLevelOverride(array $result): array
    {
        if (! self::hasExactKeys($result, ['type', 'changed', 'audit_required', 'recalculation_required', 'system_level', 'final_level', 'reason', 'provenance'])
            || ! self::validOverrideCommon($result, 'level')
            || ! is_int($result['system_level'])
            || $result['system_level'] < 1
            || $result['system_level'] > 5
            || ! is_int($result['final_level'])
            || $result['final_level'] < 1
            || $result['final_level'] > 5
            || ! is_string($result['provenance']['aspect'])
            || ! in_array($result['provenance']['aspect'], self::ASPECTS, true)) {
            throw new InvalidArgumentException('Professional level override output is invalid.');
        }
        $changed = $result['system_level'] !== $result['final_level'];
        if ($result['changed'] !== $changed || $result['recalculation_required'] !== $changed) {
            throw new InvalidArgumentException('Professional level override output is inconsistent.');
        }

        return [$changed, 'level:'.$result['provenance']['aspect'], [
            'type' => 'level',
            'aspect' => $result['provenance']['aspect'],
            'reason' => $result['reason'],
        ]];
    }

    /**
     * @param  array<mixed>  $result
     * @return array{0: bool, 1: 'label:', 2: array{type: 'label', aspect: null, reason: string|null}}
     */
    private static function validateLabelOverride(array $result): array
    {
        if (! self::hasExactKeys($result, ['type', 'changed', 'audit_required', 'recalculation_required', 'system_label', 'final_label', 'reason', 'provenance'])
            || ! self::validOverrideCommon($result, 'label')
            || ! is_string($result['system_label'])
            || ! in_array($result['system_label'], self::LABELS, true)
            || ! is_string($result['final_label'])
            || ! in_array($result['final_label'], self::LABELS, true)
            || $result['provenance']['aspect'] !== null) {
            throw new InvalidArgumentException('Professional label override output is invalid.');
        }
        $changed = $result['system_label'] !== $result['final_label'];
        if ($result['changed'] !== $changed || $result['recalculation_required'] !== false) {
            throw new InvalidArgumentException('Professional label override output is inconsistent.');
        }

        return [$changed, 'label:', [
            'type' => 'label',
            'aspect' => null,
            'reason' => $result['reason'],
        ]];
    }

    /** @param array<mixed> $result */
    private static function validOverrideCommon(array $result, string $type): bool
    {
        if ($result['type'] !== 'professional_override'
            || ! is_bool($result['changed'])
            || ! is_bool($result['audit_required'])
            || ! is_bool($result['recalculation_required'])
            || ($result['reason'] !== null && ! is_string($result['reason']))
            || ! is_array($result['provenance'])
            || ! self::hasExactKeys($result['provenance'], ['policy', 'override_type', 'aspect', 'reason_character_count'])
            || $result['provenance']['policy'] !== 'G6'
            || $result['provenance']['override_type'] !== $type
            || ! is_int($result['provenance']['reason_character_count'])) {
            return false;
        }

        $reasonLength = is_string($result['reason']) ? mb_strlen($result['reason']) : 0;

        return $result['provenance']['reason_character_count'] === $reasonLength
            && ($result['changed'] ? $reasonLength >= 20 : $result['reason'] === null);
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
