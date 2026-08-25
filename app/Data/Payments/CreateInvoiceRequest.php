<?php

declare(strict_types=1);

namespace App\Data\Payments;

use Carbon\CarbonInterface;
use InvalidArgumentException;

final readonly class CreateInvoiceRequest
{
    public function __construct(
        public string $orderReference,
        public int $amount,
        public string $currency,
        public string $description,
        public CarbonInterface $expiresAt,
    ) {
        if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $orderReference)) {
            throw new InvalidArgumentException('Order reference has an invalid format.');
        }

        if ($amount < 1) {
            throw new InvalidArgumentException('Invoice amount must be positive.');
        }

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('Invoice currency must be an ISO 4217 code.');
        }

        $length = mb_strlen($description);

        if ($length < 1 || $length > 255) {
            throw new InvalidArgumentException('Invoice description must contain 1 to 255 characters.');
        }
    }
}
