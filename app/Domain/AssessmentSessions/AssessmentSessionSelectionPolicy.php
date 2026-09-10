<?php

declare(strict_types=1);

namespace App\Domain\AssessmentSessions;

final class AssessmentSessionSelectionPolicy
{
    /** @param list<AssessmentSessionSelectionCandidate> $candidates */
    public function select(
        AssessmentSessionSelectionScope $scope,
        array $candidates,
    ): AssessmentSessionSelectionOutcome {
        $mismatches = $this->mismatches($scope, $candidates);
        if ($mismatches !== []) {
            return AssessmentSessionSelectionOutcome::scopeRejected($mismatches);
        }

        $live = array_values(array_filter(
            $candidates,
            static fn (AssessmentSessionSelectionCandidate $candidate): bool => $candidate->isLiveReplay(),
        ));

        if (count($live) > 1) {
            return AssessmentSessionSelectionOutcome::historyAmbiguous();
        }

        if (count($live) === 1) {
            return AssessmentSessionSelectionOutcome::replay($live[0]);
        }

        $eligible = array_values(array_filter(
            $candidates,
            static fn (AssessmentSessionSelectionCandidate $candidate): bool => $candidate->hasEligibleDurableSourceGrant(),
        ));

        if (count($eligible) > 1) {
            return AssessmentSessionSelectionOutcome::selectionAmbiguous();
        }

        if (count($eligible) === 1) {
            return AssessmentSessionSelectionOutcome::selected($eligible[0]);
        }

        return AssessmentSessionSelectionOutcome::unavailable();
    }

    /**
     * @param  list<AssessmentSessionSelectionCandidate>  $candidates
     * @return list<AssessmentSessionSelectionMismatch>
     */
    private function mismatches(
        AssessmentSessionSelectionScope $scope,
        array $candidates,
    ): array {
        /** @var array<string, AssessmentSessionSelectionMismatch> $mismatches */
        $mismatches = [];

        foreach ($candidates as $candidate) {
            if ($candidate->participantId !== $scope->participantId) {
                $mismatches[AssessmentSessionSelectionMismatch::Participant->value]
                    = AssessmentSessionSelectionMismatch::Participant;
            }
            if ($candidate->organizationId !== $scope->organizationId) {
                $mismatches[AssessmentSessionSelectionMismatch::Tenant->value]
                    = AssessmentSessionSelectionMismatch::Tenant;
            }
            if ($candidate->origin !== $scope->origin) {
                $mismatches[AssessmentSessionSelectionMismatch::Origin->value]
                    = AssessmentSessionSelectionMismatch::Origin;
            }
            if ($candidate->historyKey->instrument !== $scope->instrument) {
                $mismatches[AssessmentSessionSelectionMismatch::Instrument->value]
                    = AssessmentSessionSelectionMismatch::Instrument;
            }

            if ($scope->origin === CaseAuthorizationOrigin::Integrated) {
                if ($candidate->assessmentParticipantId !== $scope->assessmentParticipantId) {
                    $mismatches[AssessmentSessionSelectionMismatch::AssessmentParticipant->value]
                        = AssessmentSessionSelectionMismatch::AssessmentParticipant;
                }
                if ($candidate->historyKey->casePublicId !== $scope->trustedCasePublicId) {
                    $mismatches[AssessmentSessionSelectionMismatch::CaseIdentity->value]
                        = AssessmentSessionSelectionMismatch::CaseIdentity;
                }
            } elseif ($candidate->assessmentParticipantId !== null) {
                $mismatches[AssessmentSessionSelectionMismatch::AssessmentParticipant->value]
                    = AssessmentSessionSelectionMismatch::AssessmentParticipant;
            }
        }

        ksort($mismatches, SORT_STRING);

        return array_values($mismatches);
    }
}
