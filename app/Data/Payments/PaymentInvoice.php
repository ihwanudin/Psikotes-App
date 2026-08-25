<?php

declare(strict_types=1);

namespace App\Data\Payments;

use Carbon\CarbonInterface;
use InvalidArgumentException;

final readonly class PaymentInvoice
{
    public function __construct(
        public string $providerReference,
        public string $paymentUrl,
        public int $amount,
        public string $currency,
        public CarbonInterface $expiresAt,
    ) {
        if (! preg_match('/^[A-Za-z0-9_-]{1,160}$/', $providerReference)) {
            throw new InvalidArgumentException('Provider reference has an invalid format.');
        }

        $url = parse_url($paymentUrl);

        if (! is_array($url)
            || ($url['scheme'] ?? null) !== 'https'
            || ! is_string($url['host'] ?? null)
            || $url['host'] === '') {
            throw new InvalidArgumentException('Payment URL must be an absolute HTTPS URL.');
        }

        if ($amount < 1 || ! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('Invoice money values are invalid.');
        }
    }
}
