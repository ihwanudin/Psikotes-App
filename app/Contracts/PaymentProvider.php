<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\Payments\CreateInvoiceRequest;
use App\Data\Payments\PaymentEvent;
use App\Data\Payments\PaymentInvoice;
use App\Data\Payments\PaymentWebhookInput;
use App\Services\Payments\Exceptions\PaymentProviderException;

interface PaymentProvider
{
    public function createInvoice(CreateInvoiceRequest $request): PaymentInvoice;

    /**
     * Read-only recovery by exact merchant reference and expected money (positive integer IDR).
     * References use the existing create format: 1-64 ASCII letters, digits, underscores or hyphens.
     * Returns one validated invoice, including past expiry; this is not proof of payment.
     * Never creates/expires an invoice, retries a POST, or changes local payment state.
     * Empty, ambiguous, malformed or mismatched results and transport failures are unknown.
     *
     * @throws \InvalidArgumentException Invalid input, rejected before any provider request.
     * @throws PaymentProviderException Unknown outcome; never permission to create again.
     */
    public function lookupInvoice(string $merchantReference, int $amount, string $currency): PaymentInvoice;

    public function checkStatus(string $providerReference): PaymentEvent;

    public function normalizeWebhook(PaymentWebhookInput $input): PaymentEvent;

    public function expireInvoice(string $providerReference): PaymentEvent;
}
