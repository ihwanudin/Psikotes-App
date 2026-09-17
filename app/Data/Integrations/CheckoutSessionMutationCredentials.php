<?php

declare(strict_types=1);

namespace App\Data\Integrations;

use SensitiveParameter;

final readonly class CheckoutSessionMutationCredentials
{
    public function __construct(
        #[SensitiveParameter] private string $rawSelector,
        #[SensitiveParameter] private string $rawCsrfToken,
    ) {}

    public function rawSelector(): string
    {
        return $this->rawSelector;
    }

    public function rawCsrfToken(): string
    {
        return $this->rawCsrfToken;
    }
}
