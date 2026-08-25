<?php

declare(strict_types=1);

namespace Tests\Unit\Payments;

use App\Contracts\PaymentProvider;
use App\Data\Payments\CreateInvoiceRequest;
use App\Data\Payments\PaymentWebhookInput;
use App\Enums\PaymentStatus;
use App\Services\Payments\Exceptions\PaymentProviderException;
use App\Services\Payments\FakePaymentProvider;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

final class PaymentProviderContractTest extends TestCase
{
    private FakePaymentProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-08-25 13:00:00+07:00');
        $this->provider = new FakePaymentProvider;
    }

    public function test_fake_implements_the_payment_neutral_contract(): void
    {
        $this->assertInstanceOf(PaymentProvider::class, $this->provider);
    }

    public function test_create_invoice_is_deterministic_and_idempotent_for_one_order_intent(): void
    {
        $request = $this->invoiceRequest();

        $first = $this->provider->createInvoice($request);
        $replay = $this->provider->createInvoice($request);

        $this->assertSame($first->providerReference, $replay->providerReference);
        $this->assertSame($first->paymentUrl, $replay->paymentUrl);
        $this->assertSame('IDR', $first->currency);
        $this->assertSame(350_000, $first->amount);
        $this->assertTrue($first->expiresAt->equalTo($request->expiresAt));
    }

    public function test_reusing_an_order_reference_for_a_different_amount_is_rejected(): void
    {
        $this->provider->createInvoice($this->invoiceRequest());

        $this->expectException(PaymentProviderException::class);

        $this->provider->createInvoice(new CreateInvoiceRequest(
            orderReference: '01K3H9M5YXB62D9QK7E5V2G8Z1',
            amount: 400_000,
            currency: 'IDR',
            description: 'Paket IST',
            expiresAt: Date::now()->addHour(),
        ));
    }

    public function test_status_check_returns_a_normalized_event(): void
    {
        $invoice = $this->provider->createInvoice($this->invoiceRequest());
        $this->provider->markPaid($invoice->providerReference, 'fake-paid-event-1');

        $event = $this->provider->checkStatus($invoice->providerReference);

        $this->assertSame('fake-paid-event-1', $event->eventId);
        $this->assertSame($invoice->providerReference, $event->providerReference);
        $this->assertSame(PaymentStatus::Paid, $event->status);
        $this->assertTrue($event->occurredAt->equalTo(Date::now()));
    }

    public function test_webhook_is_normalized_without_gateway_fields_leaking_into_the_event(): void
    {
        $invoice = $this->provider->createInvoice($this->invoiceRequest());
        $event = $this->provider->normalizeWebhook(new PaymentWebhookInput(
            headers: ['x-fake-signature' => 'valid'],
            payload: [
                'event_id' => 'fake-webhook-event-1',
                'invoice_reference' => $invoice->providerReference,
                'status' => 'paid',
                'occurred_at' => '2026-08-25T13:05:00+07:00',
            ],
        ));

        $this->assertSame('fake-webhook-event-1', $event->eventId);
        $this->assertSame(PaymentStatus::Paid, $event->status);
        $this->assertSame([], $event->metadata);
    }

    public function test_malformed_or_unauthenticated_webhook_is_rejected(): void
    {
        $this->expectException(PaymentProviderException::class);

        $this->provider->normalizeWebhook(new PaymentWebhookInput(
            headers: ['x-fake-signature' => 'invalid'],
            payload: ['status' => 'paid'],
        ));
    }

    public function test_pending_invoice_can_be_expired_and_paid_invoice_cannot(): void
    {
        $pending = $this->provider->createInvoice($this->invoiceRequest());

        $expired = $this->provider->expireInvoice($pending->providerReference);

        $this->assertSame(PaymentStatus::Expired, $expired->status);
        $this->assertSame(PaymentStatus::Expired, $this->provider->checkStatus($pending->providerReference)->status);

        $paidRequest = new CreateInvoiceRequest(
            orderReference: '01K3H9M5YXB62D9QK7E5V2G8Z2',
            amount: 350_000,
            currency: 'IDR',
            description: 'Paket PAPI',
            expiresAt: Date::now()->addHour(),
        );
        $paid = $this->provider->createInvoice($paidRequest);
        $this->provider->markPaid($paid->providerReference, 'fake-paid-event-2');

        $this->expectException(PaymentProviderException::class);

        $this->provider->expireInvoice($paid->providerReference);
    }

    private function invoiceRequest(): CreateInvoiceRequest
    {
        return new CreateInvoiceRequest(
            orderReference: '01K3H9M5YXB62D9QK7E5V2G8Z1',
            amount: 350_000,
            currency: 'IDR',
            description: 'Paket IST',
            expiresAt: Date::now()->addHour(),
        );
    }
}
