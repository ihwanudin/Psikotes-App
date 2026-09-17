<?php

declare(strict_types=1);

namespace App\Domain\AssessmentSessions;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

final class AssessmentSessionStateMachine
{
    public function transition(
        AssessmentSessionStatus $current,
        AssessmentSessionStatus $target,
    ): AssessmentSessionStatus {
        $allowed = match ($current) {
            AssessmentSessionStatus::Created => [
                AssessmentSessionStatus::InProgress,
                AssessmentSessionStatus::Voided,
            ],
            AssessmentSessionStatus::InProgress => [
                AssessmentSessionStatus::Submitted,
                AssessmentSessionStatus::Expired,
                AssessmentSessionStatus::Voided,
            ],
            AssessmentSessionStatus::Submitted => [
                AssessmentSessionStatus::Scored,
                AssessmentSessionStatus::Voided,
            ],
            AssessmentSessionStatus::Expired => [AssessmentSessionStatus::Voided],
            AssessmentSessionStatus::Scored, AssessmentSessionStatus::Voided => [],
        };

        if (! in_array($target, $allowed, true)) {
            throw new InvalidAssessmentSessionTransition(
                "Assessment session cannot transition from {$current->value} to {$target->value}.",
            );
        }

        return $target;
    }

    public function start(
        AssessmentSessionStatus $current,
        ?DateTimeImmutable $startedAt,
        ?DateTimeImmutable $endsAt,
        DateTimeImmutable $now,
        int $durationSeconds,
    ): AssessmentSessionStartDecision {
        if ($durationSeconds < 1) {
            throw new InvalidArgumentException('Assessment duration must be positive.');
        }

        if ($current === AssessmentSessionStatus::InProgress) {
            if ($startedAt === null || $endsAt === null || $endsAt <= $startedAt) {
                throw new InvalidAssessmentSessionState(
                    'An in-progress session requires a valid persisted timing window.',
                );
            }

            return new AssessmentSessionStartDecision($current, $startedAt, $endsAt);
        }

        $status = $this->transition($current, AssessmentSessionStatus::InProgress);

        if ($startedAt !== null || $endsAt !== null) {
            throw new InvalidAssessmentSessionState(
                'A created session cannot have a persisted timing window.',
            );
        }

        return new AssessmentSessionStartDecision(
            $status,
            $now,
            $now->add(new DateInterval("PT{$durationSeconds}S")),
        );
    }

    public function submit(AssessmentSessionStatus $current): AssessmentSessionStatus
    {
        if ($current === AssessmentSessionStatus::Submitted || $current === AssessmentSessionStatus::Scored) {
            return $current;
        }

        return $this->transition($current, AssessmentSessionStatus::Submitted);
    }
}
