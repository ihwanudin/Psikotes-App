<?php

declare(strict_types=1);

namespace App\Domain\AssessmentSessions;

final readonly class AssessmentRetestGrant
{
    public function __construct(
        public string $grantId,
        public bool $authorized,
        public string $auditReason,
        public string $authorizedBy,
        public string $authorizationId,
        public int $attemptNumber,
    ) {}
}
