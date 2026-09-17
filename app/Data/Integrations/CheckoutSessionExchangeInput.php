<?php

declare(strict_types=1);

namespace App\Data\Integrations;

use SensitiveParameter;

final readonly class CheckoutSessionExchangeInput
{
    public function __construct(#[SensitiveParameter] private string $rawHandoffToken) {}

    public function rawHandoffToken(): string
    {
        return $this->rawHandoffToken;
    }
}
