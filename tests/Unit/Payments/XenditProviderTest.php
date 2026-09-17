<?php

declare(strict_types=1);

namespace Tests\Unit\Payments;

use App\Contracts\PaymentProvider;
use App\Data\Payments\CreateInvoiceRequest;
use App\Data\Payments\PaymentWebhookInput;
use App\Enums\PaymentStatus;
use App\Services\Payments\Exceptions\PaymentProviderException;
use App\Services\Payments\XenditProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class XenditProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-08-25 13:00:00+07:00');
        config()->set('services.xendit', [
            'secret_key' => 'xnd_test_secret',
            'callback_token' => 'callback-secret',
            'base_url' => 'https://api.xendit.co',
            'connect_timeout_seconds' => 3,
            'timeout_seconds' => 10,
        ]);
        Http::preventStrayRequests();
    }

    public function test_create_invoice_uses_basic_auth_and_validates_response_contract(): void
    {
        Http::fake([
            'https://api.xendit.co/v2/invoices' => Http::response($this->invoicePayload(), 200),
        ]);

        $invoice = app(XenditProvider::class)->createInvoice($this->invoiceRequest());

        $this->assertSame('invoice-123', $invoice->providerReference);
        $this->assertSame('https://invoice.xendit.co/invoice-123', $invoice->paymentUrl);
        $this->assertSame(350_000, $invoice->amount);
        $this->assertSame('IDR', $invoice->currency);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.xendit.co/v2/invoices'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('xnd_test_secret:'))
            && $request['external_id'] === '01K3H9M5YXB62D9QK7E5V2G8Z1'
            && $request['amount'] === 350_000
            && $request['currency'] === 'IDR'
            && $request['invoice_duration'] === 3600);
    }

    public function test_application_binds_payment_contract_to_xendit_adapter(): void
    {
        $this->assertInstanceOf(XenditProvider::class, app(PaymentProvider::class));
    }

    public function test_unknown_create_outcome_falls_back_to_external_id_lookup(): void
    {
        Http::fakeSequence('https://api.xendit.co/*')
            ->pushFailedConnection('create timeout')
            ->push([$this->invoicePayload()]);

        $invoice = app(XenditProvider::class)->createInvoice($this->invoiceRequest());

        $this->assertSame('invoice-123', $invoice->providerReference);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://api.xendit.co/v2/invoices?')
            && str_contains($request->url(), 'external_id=01K3H9M5YXB62D9QK7E5V2G8Z1'));
    }

    public function test_create_rejects_an_invoice_url_outside_xendit(): void
    {
        Http::fake([
            'https://api.xendit.co/v2/invoices' => Http::response($this->invoicePayload([
                'invoice_url' => 'https://attacker.example/invoice-123',
            ]), 200),
        ]);

        $this->expectException(PaymentProviderException::class);

        app(XenditProvider::class)->createInvoice($this->invoiceRequest());
    }

    public function test_status_check_maps_settled_to_paid(): void
    {
        Http::fake([
            'https://api.xendit.co/v2/invoices/invoice-123' => Http::response($this->invoicePayload([
                'status' => 'SETTLED',
                'paid_at' => '2026-08-25T13:05:00+07:00',
            ]), 200),
        ]);

        $event = app(XenditProvider::class)->checkStatus('invoice-123');

        $this->assertSame($this->eventId('invoice-123', 'paid'), $event->eventId);
        $this->assertSame('invoice-123', $event->providerReference);
        $this->assertSame('01K3H9M5YXB62D9QK7E5V2G8Z1', $event->merchantReference);
        $this->assertSame(PaymentStatus::Paid, $event->status);
        $this->assertSame(350_000, $event->amount);
    }

    public function test_webhook_token_is_verified_before_payload_is_normalized(): void
    {
        $this->expectException(PaymentProviderException::class);
        $this->expectExceptionMessage('Xendit webhook was rejected.');

        app(XenditProvider::class)->normalizeWebhook(new PaymentWebhookInput(
            headers: ['x-callback-token' => 'wrong'],
            payload: ['status' => 'PAID'],
        ));
    }

    public function test_paid_and_settled_webhooks_normalize_to_the_same_logical_event(): void
    {
        $provider = app(XenditProvider::class);
        $paid = $provider->normalizeWebhook($this->webhookInput('PAID'));
        $settled = $provider->normalizeWebhook($this->webhookInput('SETTLED'));

        $this->assertSame($this->eventId('invoice-123', 'paid'), $paid->eventId);
        $this->assertSame($paid->eventId, $settled->eventId);
        $this->assertSame(PaymentStatus::Paid, $paid->status);
        $this->assertSame($paid->status, $settled->status);
    }

    public function test_unknown_webhook_status_is_rejected_without_echoing_it(): void
    {
        $this->expectException(PaymentProviderException::class);
        $this->expectExceptionMessage('Xendit webhook was rejected.');

        app(XenditProvider::class)->normalizeWebhook($this->webhookInput('UNEXPECTED_INTERNAL_STATUS'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function invoicePayload(array $overrides = []): array
    {
        return array_merge([
            'id' => 'invoice-123',
            'external_id' => '01K3H9M5YXB62D9QK7E5V2G8Z1',
            'status' => 'PENDING',
            'amount' => 350_000,
            'currency' => 'IDR',
            'invoice_url' => 'https://invoice.xendit.co/invoice-123',
            'expiry_date' => '2026-08-25T14:00:00+07:00',
            'created' => '2026-08-25T13:00:00+07:00',
            'updated' => '2026-08-25T13:00:00+07:00',
        ], $overrides);
    }

    private function webhookInput(string $status): PaymentWebhookInput
    {
        return new PaymentWebhookInput(
            headers: ['x-callback-token' => 'callback-secret'],
            payload: $this->invoicePayload([
                'status' => $status,
                'paid_at' => '2026-08-25T13:05:00+07:00',
            ]),
        );
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

    private function eventId(string $providerReference, string $status): string
    {
        return 'xendit-invoice:'.hash('sha256', $providerReference).':'.$status;
    }
}
