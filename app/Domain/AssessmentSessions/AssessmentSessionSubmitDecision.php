<?php

declare(strict_types=1);

namespace App\Domain\AssessmentSessions;

final readonly class AssessmentSessionSubmitDecision
{
    public function __construct(
        public bool $accepted,
        public bool $replayed,
        public AssessmentSessionStatus $status,
        public ?AssessmentSessionErrorCode $errorCode,
    ) {}
}
