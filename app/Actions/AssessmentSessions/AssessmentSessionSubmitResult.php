<?php

declare(strict_types=1);

namespace App\Actions\AssessmentSessions;

use DateTimeImmutable;

final readonly class AssessmentSessionSubmitResult
{
    public function __construct(
        public bool $accepted,
        public bool $replayed,
        public ?string $errorCode,
        public ?string $sessionId,
        public ?string $status,
        public ?DateTimeImmutable $submittedAt,
        public ?int $answersRevision,
        public DateTimeImmutable $serverTime,
    ) {}
}
