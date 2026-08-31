<?php

declare(strict_types=1);

namespace App\Data\Payments;

use Carbon\CarbonImmutable;

/** In-memory result of the committed single-use permit. Never serialize this into a retry. */
final readonly class AssessmentInvoicePermit
{
    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function __construct(
        public string $messageId,
        public int $organizationId,
        public int $billId,
        public string $merchantReference,
        public int $amount,
        public string $currency,
        public string $description,
        public CarbonImmutable $requestedExpiresAt,
        public string $payloadDigest,
        public array $snapshot,
    ) {}
}
