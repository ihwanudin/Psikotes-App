<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\Payments\CreateInvoiceRequest;
use App\Data\Payments\PaymentEvent;
use App\Data\Payments\PaymentInvoice;
use App\Data\Payments\PaymentWebhookInput;

interface PaymentProvider
{
    public function createInvoice(CreateInvoiceRequest $request): PaymentInvoice;

    public function checkStatus(string $providerReference): PaymentEvent;

    public function normalizeWebhook(PaymentWebhookInput $input): PaymentEvent;

    public function expireInvoice(string $providerReference): PaymentEvent;
}
