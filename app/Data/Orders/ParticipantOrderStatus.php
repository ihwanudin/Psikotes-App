<?php

declare(strict_types=1);

namespace App\Data\Orders;

use App\Models\Order;

final readonly class ParticipantOrderStatus
{
    public function __construct(
        public string $publicId,
        public string $status,
        public int $amount,
        public string $currency,
        public string $paymentMethodCode,
        public string $paymentMethodDisplayName,
        public ?string $paidAt,
        public ?string $expiresAt,
        public ?string $rejectionReason,
    ) {}

    public static function fromOrder(Order $order): self
    {
        return new self(
            publicId: $order->public_id,
            status: $order->status->value,
            amount: $order->amount,
            currency: $order->currency,
            paymentMethodCode: $order->paymentMethod->code,
            paymentMethodDisplayName: $order->paymentMethod->display_name,
            paidAt: $order->paid_at?->toAtomString(),
            expiresAt: $order->expires_at?->toAtomString(),
            rejectionReason: $order->status->value === 'rejected'
                ? $order->rejection_reason
                : null,
        );
    }

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        return [
            'public_id' => $this->publicId,
            'status' => $this->status,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'payment_method' => [
                'code' => $this->paymentMethodCode,
                'display_name' => $this->paymentMethodDisplayName,
            ],
            'paid_at' => $this->paidAt,
            'expires_at' => $this->expiresAt,
            'rejection_reason' => $this->rejectionReason,
        ];
    }

    /** @return array<string, mixed> */
    public function toInertiaArray(): array
    {
        return [
            'publicId' => $this->publicId,
            'status' => $this->status,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'paymentMethod' => [
                'code' => $this->paymentMethodCode,
                'displayName' => $this->paymentMethodDisplayName,
            ],
            'paidAt' => $this->paidAt,
            'expiresAt' => $this->expiresAt,
            'rejectionReason' => $this->rejectionReason,
        ];
    }
}
