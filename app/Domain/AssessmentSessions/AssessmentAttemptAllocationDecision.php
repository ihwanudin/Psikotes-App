<?php

declare(strict_types=1);

namespace App\Domain\AssessmentSessions;

final readonly class AssessmentAttemptAllocationDecision
{
    public function __construct(
        public bool $accepted,
        public bool $shouldPersist,
        public ?AssessmentAttemptAllocation $allocation,
        public ?AssessmentSessionErrorCode $errorCode,
    ) {}
}
