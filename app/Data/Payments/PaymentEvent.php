<?php

declare(strict_types=1);

namespace App\Data\Payments;

use App\Enums\PaymentStatus;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final readonly class PaymentEvent
{
    /** @param array<string, scalar|null> $metadata */
    public function __construct(
        public string $eventId,
        public string $providerReference,
        public PaymentStatus $status,
        public CarbonInterface $occurredAt,
        public array $metadata = [],
    ) {
        if (! preg_match('/^[A-Za-z0-9._:-]{1,160}$/', $eventId)) {
            throw new InvalidArgumentException('Payment event identifier has an invalid format.');
        }

        if (! preg_match('/^[A-Za-z0-9_-]{1,160}$/', $providerReference)) {
            throw new InvalidArgumentException('Provider reference has an invalid format.');
        }
    }
}
