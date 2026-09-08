<?php

declare(strict_types=1);

namespace App\Domain\AssessmentSessions;

use DateTimeImmutable;

final readonly class AssessmentSessionStartDecision
{
    public function __construct(
        public AssessmentSessionStatus $status,
        public DateTimeImmutable $startedAt,
        public DateTimeImmutable $endsAt,
    ) {}
}
