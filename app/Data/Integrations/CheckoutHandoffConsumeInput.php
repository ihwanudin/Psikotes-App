<?php

declare(strict_types=1);

namespace App\Data\Integrations;

use SensitiveParameter;

final readonly class CheckoutHandoffConsumeInput
{
    private string $rawToken;

    public function __construct(#[SensitiveParameter] string $rawToken)
    {
        $this->rawToken = $rawToken;
    }

    public function rawToken(): string
    {
        return $this->rawToken;
    }
}
