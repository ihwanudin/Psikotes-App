<?php

declare(strict_types=1);

namespace App\Actions\AssessmentSessions;

use DateTimeImmutable;

final readonly class AssessmentSessionStartResult
{
    public function __construct(
        public bool $accepted,
        public bool $replayed,
        public ?string $errorCode,
        public ?string $sessionId,
        public ?string $testType,
        public ?string $status,
        public ?int $attemptNo,
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $endsAt,
        public ?DateTimeImmutable $writeDeadline,
        public ?DateTimeImmutable $submittedAt,
        public DateTimeImmutable $serverTime,
        public int $remainingSeconds,
        public ?int $answersRevision,
    ) {}
}
