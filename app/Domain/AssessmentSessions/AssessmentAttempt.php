<?php

declare(strict_types=1);

namespace App\Domain\AssessmentSessions;

final readonly class AssessmentAttempt
{
    public function __construct(
        public string $sessionPublicId,
        public int $attemptNumber,
        public string $authorizationId,
        public AssessmentSessionStatus $status,
    ) {
        if (preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $sessionPublicId) !== 1
            || $attemptNumber < 1
            || trim($authorizationId) === '') {
            throw new InvalidAssessmentSessionState('Assessment attempt identity is invalid.');
        }
    }

    public function isActive(): bool
    {
        return $this->status === AssessmentSessionStatus::Created
            || $this->status === AssessmentSessionStatus::InProgress;
    }
}
