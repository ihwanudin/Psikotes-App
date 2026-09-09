<?php

declare(strict_types=1);

namespace App\Domain\Review;

use InvalidArgumentException;

final readonly class ReportSigningSnapshotComposer
{
    /** @var list<string> */
    private const ASPECTS = ['A1', 'A2', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'D1', 'D2', 'D3', 'D4', 'D5'];

    /**
     * @param  array<mixed>  $prerequisiteInput
     * @param  array<mixed>  $snapshotProvenance
     */
    private function __construct(
        private array $prerequisiteInput,
        private array $snapshotProvenance,
    ) {}

    /** @param array<mixed> $structuralInput */
    public static function compose(
        ReviewedEligibilityDecision $reviewedEligibility,
        G7ReviewSet $g7ReviewSet,
        array $structuralInput,
    ): self {
        $structural = self::validateStructuralInput($structuralInput);
        $reviewed = $reviewedEligibility->toArray();
        $systemLevels = $reviewed['system_levels'];
        $finalLevels = $reviewed['final_levels'];
        $systemRecommendation = $reviewed['system_decision']['recommendation'];
        $finalDecision = $reviewed['final_decision'];

        $validity = $systemRecommendation['provenance']['validity'];
        $targetField = $systemRecommendation['provenance']['field_code'];
        $label = $reviewed['publication_blocked'] ? null : $finalDecision['label'];
        $levelOverrides = self::levelOverridesByAspect($reviewed['level_overrides']);
        $discrepancyAspects = self::validateG7Bindings(
            $g7ReviewSet,
            $systemLevels,
            $finalLevels,
            $levelOverrides,
        );
        $overrides = self::projectChangedOverrides(
            $reviewed['level_overrides'],
            $reviewed['label_override'],
        );

        return new self(
            [
                'validity' => $validity,
                'procedure_note' => $structural['procedure_note'],
                'label' => $label,
                'accompaniment_conditions' => $structural['accompaniment_conditions'],
                'unresolved_g7_aspects' => [],
                'overrides' => $overrides,
                'target_field' => $targetField,
                'narrative_clusters' => $structural['narrative_clusters'],
            ],
            [
                'type' => 'report_signing_snapshot',
                'reviewed_eligibility_type' => $reviewed['type'],
                'reviewed_eligibility' => $reviewed,
                'discrepancy_aspects' => $discrepancyAspects,
                'g7_review' => self::projectG7Evidence($g7ReviewSet),
                'changed_override_count' => count($overrides),
                'persistence_authority_bound' => false,
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
     * @param  array<mixed>  $input
     * @return array{
     *     procedure_note: string|null,
     *     accompaniment_conditions: string|null,
     *     narrative_clusters: array{A: string|null, B: string|null, C: string|null, D: string|null}
     * }
     */
    private static function validateStructuralInput(array $input): array
    {
        if (! self::hasExactKeys($input, ['procedure_note', 'accompaniment_conditions', 'narrative_clusters'])
            || ($input['procedure_note'] !== null && ! is_string($input['procedure_note']))
            || ($input['accompaniment_conditions'] !== null && ! is_string($input['accompaniment_conditions']))
            || ! is_array($input['narrative_clusters'])
            || ! self::hasExactKeys($input['narrative_clusters'], ['A', 'B', 'C', 'D'])) {
            throw new InvalidArgumentException('Report signing structural input is invalid.');
        }

        foreach ($input['narrative_clusters'] as $narrative) {
            if ($narrative !== null && ! is_string($narrative)) {
                throw new InvalidArgumentException('Report signing narrative is invalid.');
            }
        }

        return [
            'procedure_note' => $input['procedure_note'],
            'accompaniment_conditions' => $input['accompaniment_conditions'],
            'narrative_clusters' => [
                'A' => $input['narrative_clusters']['A'],
                'B' => $input['narrative_clusters']['B'],
                'C' => $input['narrative_clusters']['C'],
                'D' => $input['narrative_clusters']['D'],
            ],
        ];
    }

    /**
     * @param  list<array<mixed>>  $overrides
     * @return array<string, array<mixed>>
     */
    private static function levelOverridesByAspect(array $overrides): array
    {
        $byAspect = [];
        foreach ($overrides as $override) {
            if (($override['provenance']['override_type'] ?? null) !== 'level') {
                throw new InvalidArgumentException('Reviewed eligibility contains an invalid level override.');
            }
            $aspect = $override['provenance']['aspect'];
            if (! is_string($aspect) || isset($byAspect[$aspect])) {
                throw new InvalidArgumentException('Reviewed eligibility level override identity is invalid.');
            }
            $byAspect[$aspect] = $override;
        }

        return $byAspect;
    }

    /**
     * @param  array<string, int>  $systemLevels
     * @param  array<string, int>  $finalLevels
     * @param  array<string, array<mixed>>  $levelOverrides
     * @return list<string>
     */
    private static function validateG7Bindings(
        G7ReviewSet $reviewSet,
        array $systemLevels,
        array $finalLevels,
        array $levelOverrides,
    ): array {
        $aspects = [];
        foreach ($reviewSet->resolutions() as $resolution) {
            $aspect = $resolution->aspect();
            if (! in_array($aspect, self::ASPECTS, true)
                || $resolution->systemLevel() !== $systemLevels[$aspect]) {
                throw new InvalidArgumentException('G7 system level does not match reviewed eligibility.');
            }

            if ($resolution->state() === G7AspectResolution::STATE_RESOLVED) {
                $finalLevel = $resolution->finalLevel();
                if ($finalLevel !== $finalLevels[$aspect]) {
                    throw new InvalidArgumentException('G7 final level does not match reviewed eligibility.');
                }

                if ($finalLevel !== $resolution->systemLevel()) {
                    $override = $levelOverrides[$aspect] ?? null;
                    if (! is_array($override)
                        || $override['changed'] !== true
                        || $override['system_level'] !== $resolution->systemLevel()
                        || $override['final_level'] !== $finalLevel
                        || $override['reason'] !== $resolution->reason()) {
                        throw new InvalidArgumentException('Changed G7 resolution does not match its professional override.');
                    }
                }
            }

            $aspects[] = $aspect;
        }

        if ($aspects !== self::ASPECTS) {
            throw new InvalidArgumentException('G7 review set is not in canonical aspect order.');
        }

        return $aspects;
    }

    /**
     * @return list<array{
     *     aspect: string,
     *     state: string,
     *     discrepancy: array<mixed>,
     *     system_level: int,
     *     final_level: int|null,
     *     reason: string|null
     * }>
     */
    private static function projectG7Evidence(G7ReviewSet $reviewSet): array
    {
        return array_map(
            static fn (G7AspectResolution $resolution): array => [
                'aspect' => $resolution->aspect(),
                'state' => $resolution->state(),
                'discrepancy' => $resolution->discrepancy(),
                'system_level' => $resolution->systemLevel(),
                'final_level' => $resolution->finalLevel(),
                'reason' => $resolution->reason(),
            ],
            $reviewSet->resolutions(),
        );
    }

    /**
     * @param  list<array<mixed>>  $levelOverrides
     * @param  array<mixed>|null  $labelOverride
     * @return list<array{type: 'level'|'label', aspect: string|null, reason: string|null}>
     */
    private static function projectChangedOverrides(array $levelOverrides, ?array $labelOverride): array
    {
        $projected = [];
        foreach ($levelOverrides as $override) {
            if ($override['changed'] === true) {
                $projected[] = [
                    'type' => 'level',
                    'aspect' => $override['provenance']['aspect'],
                    'reason' => $override['reason'],
                ];
            }
        }
        if ($labelOverride !== null && $labelOverride['changed'] === true) {
            $projected[] = [
                'type' => 'label',
                'aspect' => null,
                'reason' => $labelOverride['reason'],
            ];
        }

        usort($projected, static fn (array $left, array $right): int => [$left['type'], $left['aspect']] <=> [$right['type'], $right['aspect']]);

        return $projected;
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
