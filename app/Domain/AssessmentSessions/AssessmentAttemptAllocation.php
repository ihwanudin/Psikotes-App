<?php

declare(strict_types=1);

namespace App\Domain\AssessmentSessions;

final readonly class AssessmentAttemptAllocation
{
    public function __construct(
        public string $intentId,
        public AssessmentAttempt $attempt,
    ) {
        if (trim($intentId) === '') {
            throw new InvalidAssessmentSessionState('Assessment allocation intent is required.');
        }
    }
}
