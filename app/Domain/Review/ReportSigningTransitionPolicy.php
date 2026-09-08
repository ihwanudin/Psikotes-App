<?php

declare(strict_types=1);

namespace App\Domain\Review;

use DomainException;

final class ReportSigningTransitionPolicy
{
    public function __construct(
        private readonly ReportSigningPrerequisitePolicy $prerequisites = new ReportSigningPrerequisitePolicy,
        private readonly ReportReviewStateMachine $stateMachine = new ReportReviewStateMachine,
    ) {}

    /**
     * @return array{
     *     can_sign: bool,
     *     current_state: string,
     *     target_state: 'SIGNED',
     *     blocking_reason_codes: list<string>,
     *     prerequisite_provenance: array{
     *         validity: 'V1'|'V2'|'V3',
     *         label: 'DISARANKAN'|'DIPERTIMBANGKAN'|'TIDAK_DISARANKAN'|null,
     *         procedure_note_present: bool,
     *         accompaniment_conditions_present: bool,
     *         unresolved_g7_aspects: list<string>,
     *         overrides: list<array{type: 'level'|'label', aspect: string|null, reason_character_count: int}>,
     *         target_field: string|null,
     *         narrative_clusters_present: array{A: bool, B: bool, C: bool, D: bool}
     *     },
     *     transition: array{
     *         from_state: string,
     *         to_state: string,
     *         transitioned: true,
     *         terminal: bool,
     *         provenance: array{transition_kind: 'STANDARD'|'INVALIDATION', invalidity_declared: bool}
     *     }|null
     * }
     */
    public function attempt(string $currentState, ReportSigningSnapshotComposer $snapshot): array
    {
        if ($currentState !== 'UNDER_REVIEW') {
            $this->stateMachine->transition($currentState, 'SIGNED');

            throw new DomainException('Signing is only permitted from UNDER_REVIEW.');
        }

        $prerequisiteResult = $this->prerequisites->evaluate($snapshot->prerequisiteInput());
        $transition = null;

        if ($prerequisiteResult['can_sign']) {
            $transition = [
                'from_state' => 'UNDER_REVIEW',
                'to_state' => 'SIGNED',
                'transitioned' => true,
                'terminal' => false,
                'provenance' => [
                    'transition_kind' => 'STANDARD',
                    'invalidity_declared' => false,
                ],
            ];
        }

        return [
            'can_sign' => $prerequisiteResult['can_sign'],
            'current_state' => $currentState,
            'target_state' => 'SIGNED',
            'blocking_reason_codes' => $prerequisiteResult['blocking_reason_codes'],
            'prerequisite_provenance' => $prerequisiteResult['provenance'],
            'transition' => $transition,
        ];
    }
}
