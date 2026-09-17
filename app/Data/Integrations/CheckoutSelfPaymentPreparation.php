<?php

declare(strict_types=1);

namespace App\Data\Integrations;

/** Internal committed reservation/replay identity. It is not a provider permit or browser response. */
final readonly class CheckoutSelfPaymentPreparation
{
    public function __construct(
        public int $organizationId,
        public int $billId,
        public string $status,
        public bool $created,
    ) {}
}
