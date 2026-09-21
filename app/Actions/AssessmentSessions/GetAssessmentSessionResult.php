<?php

declare(strict_types=1);

namespace App\Actions\AssessmentSessions;

final readonly class GetAssessmentSessionResult
{
    public function __construct(
        public bool $found,
        public ?AssessmentSessionSnapshot $snapshot = null,
    ) {}
}
