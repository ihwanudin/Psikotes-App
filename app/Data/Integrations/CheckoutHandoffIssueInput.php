<?php

declare(strict_types=1);

namespace App\Data\Integrations;

use App\Enums\CheckoutHandoffIntent;
use App\Models\IntegrationClient;
use SensitiveParameter;

final readonly class CheckoutHandoffIssueInput
{
    private string $idempotencyKey;

    public function __construct(
        public IntegrationClient $authenticatedClient,
        public string $assessmentAttemptId,
        public string $sourceSystem,
        #[SensitiveParameter]
        string $idempotencyKey,
        public CheckoutHandoffIntent $intent,
    ) {
        $this->idempotencyKey = $idempotencyKey;
    }

    public function idempotencyKey(): string
    {
        return $this->idempotencyKey;
    }
}
