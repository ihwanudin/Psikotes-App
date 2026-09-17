<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Data\Payments\OrderTransition;
use App\Data\Payments\PaymentEvent;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;

final readonly class PaymentEventApplier
{
    public function __construct(
        private RlsContextRunner $runner,
        private OrderPaymentEventHandler $handler,
    ) {}

    public function apply(PaymentEvent $event): OrderTransition
    {
        return $this->runner->run(
            new RlsContext('service'),
            fn (): OrderTransition => $this->handler->applyInCurrentServiceTransaction($event),
        );
    }
}
