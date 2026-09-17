<?php

declare(strict_types=1);

namespace App\Data\Payments;

use Carbon\CarbonImmutable;

/** Outbox discovery hint only. This token is not a provider or reconciliation permit. */
final readonly class ProvisionalAssessmentInvoiceLease
{
    public function __construct(
        public string $messageId,
        public string $leaseToken,
        public CarbonImmutable $leaseExpiresAt,
    ) {}
}
