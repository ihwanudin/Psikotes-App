<?php

declare(strict_types=1);

namespace App\Data\Integrations;

use Carbon\CarbonInterface;

final readonly class IssuedAssessmentInvitation
{
    public function __construct(
        public string $url,
        public string $token,
        public CarbonInterface $expiresAt,
    ) {}
}
