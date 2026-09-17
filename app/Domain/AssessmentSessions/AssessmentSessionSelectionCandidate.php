<?php

declare(strict_types=1);

namespace App\Domain\AssessmentSessions;

final readonly class AssessmentSessionSelectionCandidate
{
    public function __construct(
        public AssessmentSessionHistoryKey $historyKey,
        public int $participantId,
        public int $organizationId,
        public CaseAuthorizationOrigin $origin,
        public ?string $durableSourceGrantId,
        public bool $eligibleForAllocation,
        public ?string $sessionPublicId,
        public ?AssessmentSessionStatus $sessionStatus,
        public ?int $assessmentParticipantId,
        public bool $retestCandidate,
    ) {
        if ($participantId <= 0 || $organizationId <= 0) {
            throw new InvalidAssessmentSessionState('Candidate participant and organization must be positive.');
        }

        if ($durableSourceGrantId !== null && trim($durableSourceGrantId) === '') {
            throw new InvalidAssessmentSessionState('A durable source grant identity cannot be blank.');
        }

        if (($sessionPublicId === null) !== ($sessionStatus === null)) {
            throw new InvalidAssessmentSessionState('Session identity and status must be supplied together.');
        }

        if ($sessionPublicId !== null && trim($sessionPublicId) === '') {
            throw new InvalidAssessmentSessionState('Session identity cannot be blank.');
        }

        if ($sessionStatus === AssessmentSessionStatus::InProgress && $durableSourceGrantId === null) {
            throw new InvalidAssessmentSessionState('A live replay requires a durable source grant binding.');
        }
    }

    public function isLiveReplay(): bool
    {
        return $this->sessionStatus === AssessmentSessionStatus::InProgress;
    }

    public function hasEligibleDurableSourceGrant(): bool
    {
        return $this->sessionStatus === null
            && $this->eligibleForAllocation
            && $this->durableSourceGrantId !== null;
    }
}
