<?php

declare(strict_types=1);

namespace App\Data\Integrations;

use InvalidArgumentException;

/** Internal browser-safe state classification. It intentionally contains no invoice capability or identity. */
final readonly class CheckoutSelfPaymentIssuanceResult
{
    public function __construct(
        public string $state,
        public ?string $paymentUrl,
    ) {
        $url = $paymentUrl === null ? null : parse_url($paymentUrl);
        $validPendingUrl = is_array($url) && ($url['scheme'] ?? null) === 'https'
            && is_string($url['host'] ?? null) && $url['host'] !== ''
            && ! isset($url['user']) && ! isset($url['pass']);
        if (($state === 'pending' && ! $validPendingUrl)
            || ($state === 'paid' && $paymentUrl !== null)
            || ! in_array($state, ['pending', 'paid'], true)) {
            throw new InvalidArgumentException('Invalid checkout payment issuance result.');
        }
    }
}
