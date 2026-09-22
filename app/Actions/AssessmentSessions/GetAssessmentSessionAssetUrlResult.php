<?php

declare(strict_types=1);

namespace App\Actions\AssessmentSessions;

use DateTimeImmutable;

final readonly class GetAssessmentSessionAssetUrlResult
{
    public function __construct(
        public bool $accepted,
        public ?string $errorCode,
        public ?string $url = null,
        public ?DateTimeImmutable $expiresAt = null,
    ) {}
}
