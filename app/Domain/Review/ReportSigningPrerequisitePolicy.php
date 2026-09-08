<?php

declare(strict_types=1);

namespace App\Domain\Review;

use InvalidArgumentException;

final class ReportSigningPrerequisitePolicy
{
    /** @var list<string> */
    private const ASPECTS = ['A1', 'A2', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'D1', 'D2', 'D3', 'D4', 'D5'];

    /** @var list<string> */
    private const TARGET_FIELDS = ['KAIGO', 'KENSETSU', 'NOUGYOU', 'SEIZOU', 'GAISHOKU', 'UMUM'];

    /** @var list<string> */
    private const CLUSTERS = ['A', 'B', 'C', 'D'];

    /**
     * @param  array<mixed>  $input
     * @return array{
     *     can_sign: bool,
     *     blocking_reason_codes: list<string>,
     *     provenance: array{
     *         validity: 'V1'|'V2'|'V3',
     *         label: 'DISARANKAN'|'DIPERTIMBANGKAN'|'TIDAK_DISARANKAN'|null,
     *         procedure_note_present: bool,
     *         accompaniment_conditions_present: bool,
     *         unresolved_g7_aspects: list<string>,
     *         overrides: list<array{type: 'level'|'label', aspect: string|null, reason_character_count: int}>,
     *         target_field: string|null,
     *         narrative_clusters_present: array{A: bool, B: bool, C: bool, D: bool}
     *     }
     * }
     */
    public function evaluate(array $input): array
    {
        $validated = $this->validate($input);
        $procedureNotePresent = $this->hasContent($validated['procedure_note']);
        $conditionsPresent = $this->hasContent($validated['accompaniment_conditions']);
        $targetField = $this->hasContent($validated['target_field']) ? $validated['target_field'] : null;
        $clustersPresent = [];
        foreach (self::CLUSTERS as $cluster) {
            $clustersPresent[$cluster] = $this->hasContent($validated['narrative_clusters'][$cluster]);
        }

        $blockingReasons = [];
        if ($validated['validity'] === 'V3') {
            $blockingReasons[] = 'VALIDITY_V3';
        }
        if ($validated['validity'] === 'V2' && ! $procedureNotePresent) {
            $blockingReasons[] = 'V2_PROCEDURE_NOTE_REQUIRED';
        }
        if ($validated['label'] === 'DIPERTIMBANGKAN' && ! $conditionsPresent) {
            $blockingReasons[] = 'ACCOMPANIMENT_CONDITIONS_REQUIRED';
        }
        if ($validated['unresolved_g7_aspects'] !== []) {
            $blockingReasons[] = 'G7_ASPECTS_UNRESOLVED';
        }

        $overrideProvenance = [];
        $invalidOverrideReason = false;
        foreach ($validated['overrides'] as $override) {
            $reasonLength = $override['reason'] === null ? 0 : mb_strlen(trim($override['reason']));
            $invalidOverrideReason = $invalidOverrideReason || $reasonLength < 20;
            $overrideProvenance[] = [
                'type' => $override['type'],
                'aspect' => $override['aspect'],
                'reason_character_count' => $reasonLength,
            ];
        }
        if ($invalidOverrideReason) {
            $blockingReasons[] = 'OVERRIDE_REASON_MIN_LENGTH';
        }
        if ($targetField === null) {
            $blockingReasons[] = 'TARGET_FIELD_REQUIRED';
        }
        foreach (self::CLUSTERS as $cluster) {
            if (! $clustersPresent[$cluster]) {
                $blockingReasons[] = 'NARRATIVE_CLUSTER_'.$cluster.'_REQUIRED';
            }
        }

        return [
            'can_sign' => $blockingReasons === [],
            'blocking_reason_codes' => $blockingReasons,
            'provenance' => [
                'validity' => $validated['validity'],
                'label' => $validated['label'],
                'procedure_note_present' => $procedureNotePresent,
                'accompaniment_conditions_present' => $conditionsPresent,
                'unresolved_g7_aspects' => $validated['unresolved_g7_aspects'],
                'overrides' => $overrideProvenance,
                'target_field' => $targetField,
                'narrative_clusters_present' => $clustersPresent,
            ],
        ];
    }

    /**
     * @param  array<mixed>  $input
     * @return array{
     *     validity: 'V1'|'V2'|'V3',
     *     procedure_note: string|null,
     *     label: 'DISARANKAN'|'DIPERTIMBANGKAN'|'TIDAK_DISARANKAN'|null,
     *     accompaniment_conditions: string|null,
     *     unresolved_g7_aspects: list<string>,
     *     overrides: list<array{type: 'level'|'label', aspect: string|null, reason: string|null}>,
     *     target_field: string|null,
     *     narrative_clusters: array{A: string|null, B: string|null, C: string|null, D: string|null}
     * }
     */
    private function validate(array $input): array
    {
        if (! $this->hasExactKeys($input, [
            'validity',
            'procedure_note',
            'label',
            'accompaniment_conditions',
            'unresolved_g7_aspects',
            'overrides',
            'target_field',
            'narrative_clusters',
        ])
            || ! is_string($input['validity'])
            || ! in_array($input['validity'], ['V1', 'V2', 'V3'], true)
            || ($input['procedure_note'] !== null && ! is_string($input['procedure_note']))
            || ($input['accompaniment_conditions'] !== null && ! is_string($input['accompaniment_conditions']))
            || ! is_array($input['unresolved_g7_aspects'])
            || ! array_is_list($input['unresolved_g7_aspects'])
            || ! is_array($input['overrides'])
            || ! array_is_list($input['overrides'])
            || ($input['target_field'] !== null && ! is_string($input['target_field']))
            || ! is_array($input['narrative_clusters'])
            || ! $this->hasExactKeys($input['narrative_clusters'], self::CLUSTERS)) {
            throw new InvalidArgumentException('Report signing prerequisite input is invalid.');
        }

        $label = $input['label'];
        if (($input['validity'] === 'V3' && $label !== null)
            || ($input['validity'] !== 'V3'
                && (! is_string($label)
                    || ! in_array($label, ['DISARANKAN', 'DIPERTIMBANGKAN', 'TIDAK_DISARANKAN'], true)))) {
            throw new InvalidArgumentException('Report signing validity and label are inconsistent.');
        }

        $targetField = $input['target_field'];
        if (is_string($targetField)
            && trim($targetField) !== ''
            && ! in_array($targetField, self::TARGET_FIELDS, true)) {
            throw new InvalidArgumentException('Report signing target field is invalid.');
        }

        $unresolvedAspects = [];
        $seenAspects = [];
        foreach ($input['unresolved_g7_aspects'] as $aspect) {
            if (! is_string($aspect)
                || ! in_array($aspect, self::ASPECTS, true)
                || array_key_exists($aspect, $seenAspects)) {
                throw new InvalidArgumentException('Report signing unresolved aspects are invalid.');
            }
            $seenAspects[$aspect] = true;
            $unresolvedAspects[] = $aspect;
        }
        sort($unresolvedAspects);

        $overrides = [];
        $seenOverrides = [];
        foreach ($input['overrides'] as $override) {
            if (! is_array($override)
                || ! $this->hasExactKeys($override, ['type', 'aspect', 'reason'])
                || ! is_string($override['type'])
                || ! in_array($override['type'], ['level', 'label'], true)
                || ($override['reason'] !== null && ! is_string($override['reason']))) {
                throw new InvalidArgumentException('Report signing override is invalid.');
            }

            $aspect = $override['aspect'];
            if (($override['type'] === 'level'
                    && (! is_string($aspect) || ! in_array($aspect, self::ASPECTS, true)))
                || ($override['type'] === 'label' && $aspect !== null)) {
                throw new InvalidArgumentException('Report signing override target is invalid.');
            }

            $identity = $override['type'].':'.($aspect ?? '');
            if (array_key_exists($identity, $seenOverrides)) {
                throw new InvalidArgumentException('Report signing override is duplicated.');
            }
            $seenOverrides[$identity] = true;
            $overrides[] = [
                'type' => $override['type'],
                'aspect' => $aspect,
                'reason' => $override['reason'],
            ];
        }
        usort(
            $overrides,
            static fn (array $left, array $right): int => [$left['type'], $left['aspect']] <=> [$right['type'], $right['aspect']],
        );

        $narrativeClusters = [];
        foreach (self::CLUSTERS as $cluster) {
            $narrative = $input['narrative_clusters'][$cluster];
            if ($narrative !== null && ! is_string($narrative)) {
                throw new InvalidArgumentException('Report signing narrative cluster is invalid.');
            }
            $narrativeClusters[$cluster] = $narrative;
        }

        return [
            'validity' => $input['validity'],
            'procedure_note' => $input['procedure_note'],
            'label' => $label,
            'accompaniment_conditions' => $input['accompaniment_conditions'],
            'unresolved_g7_aspects' => $unresolvedAspects,
            'overrides' => $overrides,
            'target_field' => $targetField,
            'narrative_clusters' => $narrativeClusters,
        ];
    }

    private function hasContent(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
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
