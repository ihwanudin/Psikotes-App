<?php

declare(strict_types=1);

namespace App\Data\Integrations;

use InvalidArgumentException;

/** Internal browser-safe state classification. It intentionally contains no invoice capability or identity. */
final readonly class CheckoutSelfPaymentIssuanceResult
{
    public function __construct(public string $state)
    {
        if (! in_array($state, ['pending', 'paid'], true)) {
            throw new InvalidArgumentException('Invalid checkout payment issuance result.');
        }
    }
}
