<?php

declare(strict_types=1);

namespace App\Domain\AssessmentSessions;

final readonly class AssessmentSessionDeadlineDecision
{
    public function __construct(
        public bool $accepted,
        public AssessmentSessionStatus $status,
        public ?AssessmentSessionErrorCode $errorCode,
    ) {}
}
