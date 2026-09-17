<?php

declare(strict_types=1);

namespace App\Domain\AssessmentSessions;

use DateTimeImmutable;

final readonly class AssessmentAutosaveReceipt
{
    /** @param list<int> $acceptedItemNumbers */
    public function __construct(
        public string $mutationId,
        public int $revision,
        public array $acceptedItemNumbers,
        public DateTimeImmutable $receivedAt,
    ) {}
}
