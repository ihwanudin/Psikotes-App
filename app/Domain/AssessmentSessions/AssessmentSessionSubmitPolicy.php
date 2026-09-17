<?php

declare(strict_types=1);

namespace App\Domain\AssessmentSessions;

use DateTimeImmutable;

final class AssessmentSessionSubmitPolicy
{
    public function decide(
        AssessmentSessionStatus $status,
        ?DateTimeImmutable $endsAt,
        DateTimeImmutable $receivedAt,
    ): AssessmentSessionSubmitDecision {
        if ($status === AssessmentSessionStatus::Submitted || $status === AssessmentSessionStatus::Scored) {
            return new AssessmentSessionSubmitDecision(true, true, $status, null);
        }

        $deadline = (new AssessmentSessionDeadlinePolicy)->evaluateAnswerWrite($status, $endsAt, $receivedAt);
        if (! $deadline->accepted) {
            return new AssessmentSessionSubmitDecision(false, false, $deadline->status, $deadline->errorCode);
        }

        return new AssessmentSessionSubmitDecision(
            true,
            false,
            (new AssessmentSessionStateMachine)->submit($status),
            null,
        );
    }
}
