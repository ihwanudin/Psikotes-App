<?php

declare(strict_types=1);

namespace App\Domain\AssessmentSessions;

final readonly class AssessmentAutosaveMutation
{
    public function __construct(
        public string $canonicalHash,
        public AssessmentAutosaveReceipt $receipt,
    ) {}
}
