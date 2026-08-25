<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Data\Payments\CreateInvoiceRequest;
use App\Data\Payments\PaymentInvoice;
use App\Enums\PaymentStatus;
use Carbon\CarbonInterface;

final class FakeInvoiceRecord
{
    public function __construct(
        public readonly CreateInvoiceRequest $request,
        public readonly PaymentInvoice $invoice,
        public PaymentStatus $status,
        public string $eventId,
        public CarbonInterface $occurredAt,
    ) {}
}
