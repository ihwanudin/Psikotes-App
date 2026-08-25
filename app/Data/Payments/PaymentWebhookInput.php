<?php

declare(strict_types=1);

namespace App\Data\Payments;

final readonly class PaymentWebhookInput
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public array $headers,
        public array $payload,
    ) {}

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }
}
