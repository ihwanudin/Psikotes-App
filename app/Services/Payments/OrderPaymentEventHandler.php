<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Data\Payments\OrderTransition;
use App\Data\Payments\PaymentEvent;
use App\Enums\OrderStatus;
use App\Models\Entitlement;
use App\Models\Order;
use App\Security\RlsContextRunner;
use App\Services\Payments\Exceptions\PaymentAmountMismatch;
use App\Services\Payments\Exceptions\PaymentReferenceMismatch;
use LogicException;

final readonly class OrderPaymentEventHandler
{
    public function __construct(
        private RlsContextRunner $runner,
        private OrderStateMachine $stateMachine,
    ) {}

    public function applyInCurrentServiceTransaction(PaymentEvent $event): OrderTransition
    {
        if ($this->runner->current()?->role !== 'service') {
            throw new LogicException('Payment events require an active service transaction.');
        }

        $order = Order::query()
            ->where('gateway_ref', $event->providerReference)
            ->lockForUpdate()
            ->first();

        if ($order === null) {
            throw new PaymentReferenceMismatch('Payment event does not match an order.');
        }

        if ($order->amount !== $event->amount || $order->currency !== $event->currency) {
            throw new PaymentAmountMismatch('Payment event money does not match the order.');
        }

        $transition = $this->stateMachine->apply($order->status, $event->status);

        if (! $transition->changed) {
            return $transition;
        }

        $order->status = $transition->status;

        if ($transition->status === OrderStatus::Paid) {
            $order->paid_at = $event->occurredAt;
        }

        $order->save();

        if ($transition->unlocksEntitlements) {
            Entitlement::query()
                ->where('order_id', $order->id)
                ->where('status', 'locked')
                ->update([
                    'status' => 'ready',
                    'ready_at' => $event->occurredAt,
                    'updated_at' => now(),
                ]);
        }

        return $transition;
    }
}
