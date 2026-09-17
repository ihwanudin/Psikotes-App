<?php

declare(strict_types=1);

namespace App\Domain\AssessmentSessions;

final readonly class AssessmentAutosaveDecision
{
    public function __construct(
        public bool $accepted,
        public bool $shouldPersist,
        public AssessmentSessionStatus $status,
        public ?AssessmentSessionErrorCode $errorCode,
        public ?AssessmentAutosaveReceipt $receipt = null,
        public ?string $canonicalHash = null,
    ) {}
}
