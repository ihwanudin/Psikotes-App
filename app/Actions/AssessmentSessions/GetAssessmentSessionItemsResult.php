<?php

declare(strict_types=1);

namespace App\Actions\AssessmentSessions;

use App\Domain\AssessmentSessions\AssessmentItemContent;

final readonly class GetAssessmentSessionItemsResult
{
    public function __construct(
        public bool $accepted,
        public ?string $errorCode,
        public ?string $sessionId = null,
        public ?AssessmentItemContent $content = null,
    ) {}
}
