<?php

declare(strict_types=1);

namespace App\Data\Payments;

use Carbon\CarbonImmutable;

/** Committed lookup permit. The rotated UUID and generation fence any later persistence. */
final readonly class AssessmentInvoiceReconciliationPermit
{
    public function __construct(
        public AssessmentInvoicePermit $invoice,
        public string $leaseToken,
        public int $lookupGeneration,
        public CarbonImmutable $leaseExpiresAt,
    ) {}
}
