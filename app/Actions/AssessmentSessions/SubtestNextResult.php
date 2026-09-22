<?php

declare(strict_types=1);

namespace App\Actions\AssessmentSessions;

final readonly class SubtestNextResult
{
    public function __construct(
        public bool $accepted,
        public ?string $status,
        public ?string $errorCode,
        public ?int $segmentIndex = null,
        public ?string $becameCurrentAt = null,
        public ?string $startedAt = null,
    ) {}
}
