<?php

declare(strict_types=1);

namespace App\Data\Payments;

use Carbon\CarbonImmutable;

final readonly class AssessmentBillProofAccess
{
    public function __construct(
        public string $url,
        public CarbonImmutable $expiresAt,
        public string $proofFingerprint,
    ) {}
}
