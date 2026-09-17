<?php

declare(strict_types=1);

namespace App\Domain\AssessmentSessions;

use DateTimeImmutable;

final class AssessmentSessionDeadlinePolicy
{
    public function evaluateAnswerWrite(
        AssessmentSessionStatus $status,
        ?DateTimeImmutable $endsAt,
        DateTimeImmutable $receivedAt,
    ): AssessmentSessionDeadlineDecision {
        if ($status === AssessmentSessionStatus::Created) {
            return new AssessmentSessionDeadlineDecision(
                false,
                $status,
                AssessmentSessionErrorCode::SessionNotStarted,
            );
        }

        if ($status !== AssessmentSessionStatus::InProgress) {
            return new AssessmentSessionDeadlineDecision(
                false,
                $status,
                AssessmentSessionErrorCode::SessionClosed,
            );
        }

        if ($endsAt === null) {
            throw new InvalidAssessmentSessionState('An in-progress session requires ends_at.');
        }

        if ($receivedAt > $endsAt) {
            return new AssessmentSessionDeadlineDecision(
                false,
                AssessmentSessionStatus::Expired,
                AssessmentSessionErrorCode::DeadlineExceeded,
            );
        }

        return new AssessmentSessionDeadlineDecision(true, $status, null);
    }
}
