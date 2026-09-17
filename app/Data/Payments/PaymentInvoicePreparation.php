<?php

declare(strict_types=1);

namespace App\Data\Payments;

final readonly class PaymentInvoicePreparation
{
    public function __construct(
        public int $orderId,
        public ?CreateInvoiceRequest $request = null,
        public ?PaymentInvoice $invoice = null,
    ) {}
}
