<?php

declare(strict_types=1);

namespace App\Actions\AssessmentSessions;

final readonly class GetAssessmentSessionAnswersResult
{
    /** @param  ?list<array{item_no: int, value: mixed}>  $answers */
    public function __construct(
        public bool $accepted,
        public ?string $errorCode,
        public ?string $sessionId = null,
        public ?int $answersRevision = null,
        public ?array $answers = null,
    ) {}
}
