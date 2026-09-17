<?php

declare(strict_types=1);

namespace App\Data\Integrations;

use SensitiveParameter;

final readonly class CheckoutSessionSelector
{
    public function __construct(#[SensitiveParameter] private string $rawSelector) {}

    public function rawSelector(): string
    {
        return $this->rawSelector;
    }
}
