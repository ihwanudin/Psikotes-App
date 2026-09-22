<?php

declare(strict_types=1);

namespace App\Domain\AssessmentSessions;

final class AssessmentAttemptAllocationPolicy
{
    /**
     * The persistence layer must still claim the accepted allocation atomically.
     *
     * @param  list<AssessmentAttempt>  $attempts
     */
    public function decide(
        array $attempts,
        string $intentId,
        string $authorizationId,
        string $newSessionPublicId,
        ?AssessmentAttemptAllocation $existingAllocation,
        ?AssessmentRetestGrant $retestGrant,
        int $freeAttemptLimit = 3,
    ): AssessmentAttemptAllocationDecision {
        if (trim($intentId) === '' || trim($authorizationId) === '') {
            throw new InvalidAssessmentSessionState('Allocation intent and authorization are required.');
        }

        $this->assertConsistentHistory($attempts);

        if ($existingAllocation !== null) {
            $this->assertReplayMatchesHistory($attempts, $existingAllocation, $intentId, $authorizationId);

            return new AssessmentAttemptAllocationDecision(true, false, $existingAllocation, null);
        }

        foreach ($attempts as $attempt) {
            if ($attempt->isActive()) {
                return $this->reject(AssessmentSessionErrorCode::AttemptAlreadyExists);
            }
        }

        $nextAttemptNumber = count($attempts) + 1;
        if ($attempts !== [] && ! $this->allowsRetest(
            $attempts,
            $retestGrant,
            $authorizationId,
            $nextAttemptNumber,
            $freeAttemptLimit,
        )) {
            return $this->reject(AssessmentSessionErrorCode::RetestNotAuthorized);
        }

        $allocation = new AssessmentAttemptAllocation(
            $intentId,
            new AssessmentAttempt(
                $newSessionPublicId,
                $nextAttemptNumber,
                $authorizationId,
                AssessmentSessionStatus::Created,
            ),
        );

        return new AssessmentAttemptAllocationDecision(true, true, $allocation, null);
    }

    /** @param list<AssessmentAttempt> $attempts */
    private function assertConsistentHistory(array $attempts): void
    {
        $attemptNumbers = [];
        $sessionIds = [];
        $activeCount = 0;

        foreach ($attempts as $attempt) {
            if (isset($attemptNumbers[$attempt->attemptNumber]) || isset($sessionIds[$attempt->sessionPublicId])) {
                throw new InvalidAssessmentSessionState('Assessment attempt history contains duplicate identity.');
            }

            $attemptNumbers[$attempt->attemptNumber] = true;
            $sessionIds[$attempt->sessionPublicId] = true;
            $activeCount += $attempt->isActive() ? 1 : 0;
        }

        if ($activeCount > 1) {
            throw new InvalidAssessmentSessionState('Assessment attempt history has multiple active attempts.');
        }

        if ($attempts !== []) {
            ksort($attemptNumbers, SORT_NUMERIC);
            if (array_keys($attemptNumbers) !== range(1, count($attemptNumbers))) {
                throw new InvalidAssessmentSessionState('Assessment attempt numbers must be contiguous.');
            }
        }
    }

    /** @param list<AssessmentAttempt> $attempts */
    private function assertReplayMatchesHistory(
        array $attempts,
        AssessmentAttemptAllocation $existingAllocation,
        string $intentId,
        string $authorizationId,
    ): void {
        if ($existingAllocation->intentId !== $intentId
            || $existingAllocation->attempt->authorizationId !== $authorizationId) {
            throw new InvalidAssessmentSessionState('Allocation replay identity does not match the request.');
        }

        foreach ($attempts as $attempt) {
            if ($attempt == $existingAllocation->attempt) {
                return;
            }
        }

        throw new InvalidAssessmentSessionState('Allocation replay is missing from attempt history.');
    }

    /**
     * item 19 (owner's decision, 2026-09-22): the first $freeAttemptLimit
     * attempts need no admin authorization at all. Every attempt past that
     * needs its own individual, valid AssessmentRetestGrant -- with no
     * further upper bound (repeated human approval is the deterrent, not a
     * fixed ceiling).
     *
     * @param  list<AssessmentAttempt>  $attempts
     */
    private function allowsRetest(
        array $attempts,
        ?AssessmentRetestGrant $grant,
        string $authorizationId,
        int $nextAttemptNumber,
        int $freeAttemptLimit,
    ): bool {
        // Authorization-identity reuse is barred unconditionally: every
        // attempt, free or gated, must run under a genuinely new
        // authorization. Hoisted above the free-limit branch below so this
        // defense does not weaken just because an attempt happens to fall
        // under the free limit.
        foreach ($attempts as $attempt) {
            if ($attempt->authorizationId === $authorizationId) {
                return false;
            }
        }

        if ($nextAttemptNumber <= $freeAttemptLimit) {
            return true;
        }

        if ($grant === null
            || ! $grant->authorized
            || trim($grant->grantId) === ''
            || trim($grant->auditReason) === ''
            || trim($grant->authorizedBy) === ''
            || $grant->authorizationId !== $authorizationId
            || $grant->attemptNumber !== $nextAttemptNumber) {
            return false;
        }

        return true;
    }

    private function reject(AssessmentSessionErrorCode $errorCode): AssessmentAttemptAllocationDecision
    {
        return new AssessmentAttemptAllocationDecision(false, false, null, $errorCode);
    }
}
