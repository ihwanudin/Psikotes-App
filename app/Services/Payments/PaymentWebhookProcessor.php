<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Data\Payments\PaymentEvent;
use App\Data\Payments\PaymentWebhookResult;
use App\Enums\PaymentWebhookOutcome;
use App\Models\PaymentWebhookEvent;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Payments\Exceptions\InvalidOrderTransition;
use App\Services\Payments\Exceptions\PaymentAmountMismatch;
use App\Services\Payments\Exceptions\PaymentReferenceMismatch;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

final readonly class PaymentWebhookProcessor
{
    public function __construct(
        private RlsContextRunner $runner,
        private OrderPaymentEventHandler $handler,
    ) {}

    public function process(string $provider, PaymentEvent $event): PaymentWebhookResult
    {
        if (! preg_match('/^[a-z0-9_-]{1,32}$/', $provider)) {
            throw new InvalidArgumentException('Payment provider code has an invalid format.');
        }

        $result = $this->runner->run(
            new RlsContext('service'),
            fn (): PaymentWebhookResult => $this->processInServiceTransaction($provider, $event),
        );

        Log::log(
            in_array($result->outcome, [PaymentWebhookOutcome::Rejected, PaymentWebhookOutcome::Conflict], true)
                ? 'warning'
                : 'info',
            'payment_webhook_processed',
            [
                'provider' => $provider,
                'event_id' => $event->eventId,
                'outcome' => $result->outcome->value,
                'reason' => $result->reason,
            ],
        );

        return $result;
    }

    private function processInServiceTransaction(
        string $provider,
        PaymentEvent $event,
    ): PaymentWebhookResult {
        $intentHash = $this->intentHash($event);
        $now = now();
        $claimed = PaymentWebhookEvent::query()->insertOrIgnore([
            'provider' => $provider,
            'event_id' => $event->eventId,
            'provider_reference' => $event->providerReference,
            'status' => $event->status->value,
            'amount' => $event->amount,
            'currency' => $event->currency,
            'occurred_at' => $event->occurredAt,
            'intent_hash' => $intentHash,
            'outcome' => 'processing',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $record = PaymentWebhookEvent::query()
            ->where('provider', $provider)
            ->where('event_id', $event->eventId)
            ->lockForUpdate()
            ->firstOrFail();

        if ($claimed === 0) {
            if (! hash_equals($record->intent_hash, $intentHash)) {
                return new PaymentWebhookResult(PaymentWebhookOutcome::Conflict, 'intent_mismatch');
            }

            if ($record->outcome === 'rejected') {
                return new PaymentWebhookResult(PaymentWebhookOutcome::Rejected, $record->error_code);
            }

            return new PaymentWebhookResult(PaymentWebhookOutcome::Duplicate);
        }

        try {
            $transition = $this->handler->applyInCurrentServiceTransaction($event);
        } catch (InvalidOrderTransition) {
            return $this->reject($record, 'invalid_transition');
        } catch (PaymentReferenceMismatch) {
            return $this->reject($record, 'reference_mismatch');
        } catch (PaymentAmountMismatch) {
            return $this->reject($record, 'money_mismatch');
        }

        $record->forceFill([
            'outcome' => $transition->changed ? 'applied' : 'ignored',
            'processed_at' => now(),
        ])->save();

        return new PaymentWebhookResult(
            $transition->changed ? PaymentWebhookOutcome::Applied : PaymentWebhookOutcome::Ignored,
        );
    }

    private function reject(PaymentWebhookEvent $record, string $reason): PaymentWebhookResult
    {
        $record->forceFill([
            'outcome' => 'rejected',
            'error_code' => $reason,
            'processed_at' => now(),
        ])->save();

        return new PaymentWebhookResult(PaymentWebhookOutcome::Rejected, $reason);
    }

    private function intentHash(PaymentEvent $event): string
    {
        return hash('sha256', json_encode([
            'provider_reference' => $event->providerReference,
            'status' => $event->status->value,
            'amount' => $event->amount,
            'currency' => $event->currency,
        ], JSON_THROW_ON_ERROR));
    }
}
