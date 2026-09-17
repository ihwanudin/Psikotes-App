<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Data\Payments\OrderTransition;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Services\Payments\Exceptions\InvalidOrderTransition;

final class OrderStateMachine
{
    public function apply(OrderStatus $current, PaymentStatus $incoming): OrderTransition
    {
        return $this->transition($current, OrderStatus::from($incoming->value));
    }

    public function applyManualReview(OrderStatus $current, bool $approved): OrderTransition
    {
        return $this->transition(
            $current,
            $approved ? OrderStatus::Paid : OrderStatus::Rejected,
        );
    }

    private function transition(OrderStatus $current, OrderStatus $target): OrderTransition
    {

        if ($current === $target) {
            return new OrderTransition($current, changed: false, unlocksEntitlements: false);
        }

        if ($current !== OrderStatus::Pending) {
            throw new InvalidOrderTransition(
                "Order cannot transition from {$current->value} to {$target->value}.",
            );
        }

        return new OrderTransition(
            status: $target,
            changed: true,
            unlocksEntitlements: $target === OrderStatus::Paid,
        );
    }
}
