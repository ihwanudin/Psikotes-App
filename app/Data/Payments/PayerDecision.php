<?php

declare(strict_types=1);

namespace App\Data\Payments;

use App\Enums\PayerType;

/** A policy result, never proof of payment, consent, or entitlement. */
final readonly class PayerDecision
{
    /** @param list<PayerType> $allowedPayerTypes */
    public function __construct(
        public array $allowedPayerTypes,
        public ?PayerType $selectedPayerType,
        public ?PayerType $lockedPayerType,
        public ?int $payerOrganizationId,
        public ?string $rejectionReason = null,
    ) {}

    public static function denied(string $reason): self
    {
        return new self([], null, null, null, $reason);
    }

    public function requiresSelection(): bool
    {
        return $this->rejectionReason === null && $this->selectedPayerType === null;
    }
}
