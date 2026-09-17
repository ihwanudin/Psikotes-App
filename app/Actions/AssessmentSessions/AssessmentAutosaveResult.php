<?php

declare(strict_types=1);

namespace App\Actions\AssessmentSessions;

use App\Domain\AssessmentSessions\AssessmentAutosaveReceipt;

final readonly class AssessmentAutosaveResult
{
    public function __construct(
        public bool $accepted,
        public bool $replayed,
        public ?string $status,
        public ?string $errorCode,
        public ?AssessmentAutosaveReceipt $receipt = null,
    ) {}
}
