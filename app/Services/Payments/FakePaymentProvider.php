<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Contracts\PaymentProvider;
use App\Data\Payments\CreateInvoiceRequest;
use App\Data\Payments\PaymentEvent;
use App\Data\Payments\PaymentInvoice;
use App\Data\Payments\PaymentWebhookInput;
use App\Enums\PaymentStatus;
use App\Services\Payments\Exceptions\PaymentProviderException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Throwable;

final class FakePaymentProvider implements PaymentProvider
{
    /** @var array<string, FakeInvoiceRecord> */
    private array $records = [];

    /** @var array<string, string> */
    private array $referencesByOrder = [];

    public function createInvoice(CreateInvoiceRequest $request): PaymentInvoice
    {
        $existingReference = $this->referencesByOrder[$request->orderReference] ?? null;

        if ($existingReference !== null) {
            $record = $this->records[$existingReference];

            if (! $this->sameIntent($record->request, $request)) {
                throw new PaymentProviderException('Order reference was reused for a different invoice intent.');
            }

            return $record->invoice;
        }

        $providerReference = 'fake-'.substr(hash('sha256', $request->orderReference), 0, 24);
        $invoice = new PaymentInvoice(
            providerReference: $providerReference,
            paymentUrl: 'https://payments.example.test/invoices/'.$providerReference,
            amount: $request->amount,
            currency: $request->currency,
            expiresAt: $request->expiresAt,
        );
        $this->records[$providerReference] = new FakeInvoiceRecord(
            request: $request,
            invoice: $invoice,
            status: PaymentStatus::Pending,
            eventId: 'fake-created-'.$providerReference,
            occurredAt: Date::now(),
        );
        $this->referencesByOrder[$request->orderReference] = $providerReference;

        return $invoice;
    }

    public function checkStatus(string $providerReference): PaymentEvent
    {
        return $this->event($this->record($providerReference));
    }

    public function normalizeWebhook(PaymentWebhookInput $input): PaymentEvent
    {
        $signature = $input->header('x-fake-signature');

        if (! is_string($signature) || ! hash_equals('valid', $signature)) {
            throw new PaymentProviderException('Webhook authentication failed.');
        }

        $eventId = $input->payload['event_id'] ?? null;
        $providerReference = $input->payload['invoice_reference'] ?? null;
        $rawStatus = $input->payload['status'] ?? null;
        $rawOccurredAt = $input->payload['occurred_at'] ?? null;

        if (! is_string($eventId)
            || ! is_string($providerReference)
            || ! is_string($rawStatus)
            || ! is_string($rawOccurredAt)) {
            throw new PaymentProviderException('Webhook payload is malformed.');
        }

        $status = PaymentStatus::tryFrom($rawStatus);

        if ($status === null) {
            throw new PaymentProviderException('Webhook status is unknown.');
        }

        try {
            $occurredAt = CarbonImmutable::parse($rawOccurredAt);
        } catch (Throwable) {
            throw new PaymentProviderException('Webhook timestamp is invalid.');
        }

        try {
            return new PaymentEvent($eventId, $providerReference, $status, $occurredAt);
        } catch (\InvalidArgumentException $exception) {
            throw new PaymentProviderException('Webhook identifiers are invalid.', previous: $exception);
        }
    }

    public function expireInvoice(string $providerReference): PaymentEvent
    {
        $record = $this->record($providerReference);

        if ($record->status === PaymentStatus::Expired) {
            return $this->event($record);
        }

        if ($record->status !== PaymentStatus::Pending) {
            throw new PaymentProviderException('Only a pending invoice can expire.');
        }

        $record->status = PaymentStatus::Expired;
        $record->eventId = 'fake-expired-'.$providerReference;
        $record->occurredAt = Date::now();

        return $this->event($record);
    }

    public function markPaid(string $providerReference, string $eventId): PaymentEvent
    {
        $record = $this->record($providerReference);

        if ($record->status === PaymentStatus::Paid && $record->eventId === $eventId) {
            return $this->event($record);
        }

        if ($record->status !== PaymentStatus::Pending) {
            throw new PaymentProviderException('Only a pending invoice can be marked paid.');
        }

        $record->status = PaymentStatus::Paid;
        $record->eventId = $eventId;
        $record->occurredAt = Date::now();

        return $this->event($record);
    }

    private function record(string $providerReference): FakeInvoiceRecord
    {
        return $this->records[$providerReference]
            ?? throw new PaymentProviderException('Invoice was not found.');
    }

    private function event(FakeInvoiceRecord $record): PaymentEvent
    {
        return new PaymentEvent(
            eventId: $record->eventId,
            providerReference: $record->invoice->providerReference,
            status: $record->status,
            occurredAt: $record->occurredAt,
        );
    }

    private function sameIntent(CreateInvoiceRequest $first, CreateInvoiceRequest $second): bool
    {
        return $first->orderReference === $second->orderReference
            && $first->amount === $second->amount
            && $first->currency === $second->currency
            && $first->description === $second->description
            && $first->expiresAt->equalTo($second->expiresAt);
    }
}
