<?php

declare(strict_types=1);

namespace App\Data\Payments;

use App\Enums\PaymentWebhookOutcome;

final readonly class PaymentWebhookResult
{
    public function __construct(
        public PaymentWebhookOutcome $outcome,
        public ?string $reason = null,
    ) {}
}
