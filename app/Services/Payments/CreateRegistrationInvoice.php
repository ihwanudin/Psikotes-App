<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Contracts\PaymentProvider;
use App\Data\Payments\CreateInvoiceRequest;
use App\Data\Payments\PaymentInvoice;
use App\Data\Payments\PaymentInvoicePreparation;
use App\Models\Order;
use App\Models\Participant;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Payments\Exceptions\PaymentProviderException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

final readonly class CreateRegistrationInvoice
{
    public function __construct(
        private RlsContextRunner $runner,
        private PaymentProvider $provider,
    ) {}

    public function handle(Participant $participant): ?PaymentInvoice
    {
        $preparation = $this->runner->run(
            new RlsContext('service'),
            fn (): ?PaymentInvoicePreparation => $this->prepare($participant->id),
        );

        if ($preparation === null || $preparation->invoice !== null) {
            return $preparation?->invoice;
        }

        if ($preparation->request === null) {
            throw new LogicException('Invoice preparation has no request.');
        }

        try {
            $invoice = $this->provider->createInvoice($preparation->request);
        } catch (PaymentProviderException) {
            $this->markUnknown($preparation->orderId);

            return null;
        }

        return $this->runner->run(
            new RlsContext('service'),
            fn (): PaymentInvoice => $this->storeInvoice($preparation->orderId, $invoice),
        );
    }

    private function prepare(int $participantId): ?PaymentInvoicePreparation
    {
        $order = Order::query()
            ->with('paymentMethod')
            ->where('participant_id', $participantId)
            ->latest('id')
            ->lockForUpdate()
            ->first();

        if ($order === null || $order->paymentMethod->code !== 'xendit') {
            return null;
        }

        if (is_string($order->gateway_ref)
            && is_string($order->invoice_url)
            && $order->expires_at !== null) {
            return new PaymentInvoicePreparation(
                orderId: $order->id,
                invoice: new PaymentInvoice(
                    providerReference: $order->gateway_ref,
                    paymentUrl: $order->invoice_url,
                    amount: $order->amount,
                    currency: $order->currency,
                    expiresAt: $order->expires_at,
                ),
            );
        }

        if ($this->hasRecentUnresolvedClaim($order)) {
            return null;
        }

        $expiresAt = Date::now()->addDay();
        $metadata = $order->metadata ?? [];
        $metadata['xendit_invoice'] = [
            'state' => 'creating',
            'attempted_at' => Date::now()->utc()->toIso8601String(),
        ];
        $order->metadata = $metadata;
        $order->save();

        return new PaymentInvoicePreparation(
            orderId: $order->id,
            request: new CreateInvoiceRequest(
                orderReference: $order->public_id,
                amount: $order->amount,
                currency: $order->currency,
                description: 'Psikotes LSI '.$order->public_id,
                expiresAt: $expiresAt,
            ),
        );
    }

    private function storeInvoice(int $orderId, PaymentInvoice $invoice): PaymentInvoice
    {
        $order = Order::query()->lockForUpdate()->findOrFail($orderId);

        if ($order->gateway_ref !== null && $order->gateway_ref !== $invoice->providerReference) {
            throw new PaymentProviderException('Order already has a different invoice reference.');
        }

        $metadata = $order->metadata ?? [];
        $metadata['xendit_invoice'] = [
            'state' => 'ready',
            'attempted_at' => Date::now()->utc()->toIso8601String(),
        ];
        $order->forceFill([
            'gateway_ref' => $invoice->providerReference,
            'invoice_url' => $invoice->paymentUrl,
            'expires_at' => CarbonImmutable::instance($invoice->expiresAt)->utc(),
            'metadata' => $metadata,
        ])->save();

        Log::info('xendit_invoice_attached', [
            'order_reference' => $order->public_id,
            'provider_reference' => $invoice->providerReference,
        ]);

        return $invoice;
    }

    private function markUnknown(int $orderId): void
    {
        $this->runner->run(new RlsContext('service'), function () use ($orderId): void {
            $order = Order::query()->lockForUpdate()->find($orderId);

            if ($order === null || $order->gateway_ref !== null) {
                return;
            }

            $metadata = $order->metadata ?? [];
            $metadata['xendit_invoice'] = [
                'state' => 'unknown',
                'attempted_at' => Date::now()->utc()->toIso8601String(),
            ];
            $order->metadata = $metadata;
            $order->save();

            Log::warning('xendit_invoice_outcome_unknown', [
                'order_reference' => $order->public_id,
            ]);
        });
    }

    private function hasRecentUnresolvedClaim(Order $order): bool
    {
        $claim = $order->metadata['xendit_invoice'] ?? null;

        if (! is_array($claim)
            || ! in_array($claim['state'] ?? null, ['creating', 'unknown'], true)
            || ! is_string($claim['attempted_at'] ?? null)) {
            return false;
        }

        try {
            return CarbonImmutable::parse($claim['attempted_at'])->isAfter(Date::now()->subMinutes(5));
        } catch (Throwable) {
            return false;
        }
    }
}
