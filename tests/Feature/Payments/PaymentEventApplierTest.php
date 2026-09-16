<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Data\Payments\PaymentEvent;
use App\Enums\PaymentStatus;
use App\Models\Entitlement;
use App\Models\Order;
use App\Services\Payments\Exceptions\InvalidOrderTransition;
use App\Services\Payments\Exceptions\PaymentAmountMismatch;
use App\Services\Payments\Exceptions\PaymentReferenceMismatch;
use App\Services\Payments\OrderPaymentEventHandler;
use App\Services\Payments\PaymentEventApplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use LogicException;
use Tests\Support\DirectPublicOrderFixture;
use Tests\TestCase;

final class PaymentEventApplierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-08-25 13:00:00+07:00');
    }

    public function test_pending_and_expired_events_never_unlock_entitlement(): void
    {
        [$order, $entitlement] = $this->orderWithLockedEntitlement();
        $applier = app(PaymentEventApplier::class);

        $pending = $applier->apply($this->event(PaymentStatus::Pending, 'event-pending'));
        $expired = $applier->apply($this->event(PaymentStatus::Expired, 'event-expired'));

        $this->assertFalse($pending->unlocksEntitlements);
        $this->assertFalse($expired->unlocksEntitlements);
        $this->assertSame('expired', $order->fresh()->status->value);
        $this->assertSame('locked', $entitlement->fresh()->status);
        $this->assertNull($entitlement->fresh()->ready_at);
        $this->assertEntitlements($order, 'locked');
    }

    public function test_valid_paid_event_updates_order_and_unlocks_entitlement_atomically(): void
    {
        [$order, $entitlement] = $this->orderWithLockedEntitlement();

        $transition = app(PaymentEventApplier::class)->apply(
            $this->event(PaymentStatus::Paid, 'event-paid'),
        );

        $this->assertTrue($transition->unlocksEntitlements);
        $this->assertSame('paid', $order->fresh()->status->value);
        $this->assertTrue($order->fresh()->paid_at->equalTo(Date::now()));
        $this->assertSame('ready', $entitlement->fresh()->status);
        $this->assertTrue($entitlement->fresh()->ready_at->equalTo(Date::now()));
        $this->assertEntitlements($order, 'ready');
    }

    public function test_replayed_paid_event_is_a_no_op(): void
    {
        [$order, $entitlement] = $this->orderWithLockedEntitlement();
        $applier = app(PaymentEventApplier::class);
        $event = $this->event(PaymentStatus::Paid, 'event-paid');
        $first = $applier->apply($event);
        $paidAt = $order->fresh()->paid_at;
        $readyAt = $entitlement->fresh()->ready_at;
        Date::setTestNow('2026-08-25 14:00:00+07:00');

        $replay = $applier->apply($event);

        $this->assertTrue($first->changed);
        $this->assertFalse($replay->changed);
        $this->assertFalse($replay->unlocksEntitlements);
        $this->assertTrue($order->fresh()->paid_at->equalTo($paidAt));
        $this->assertTrue($entitlement->fresh()->ready_at->equalTo($readyAt));
        $this->assertEntitlements($order, 'ready');
    }

    public function test_reordered_terminal_event_is_rejected_without_partial_changes(): void
    {
        [$order, $entitlement] = $this->orderWithLockedEntitlement();
        $applier = app(PaymentEventApplier::class);
        $applier->apply($this->event(PaymentStatus::Paid, 'event-paid'));

        try {
            $applier->apply($this->event(PaymentStatus::Expired, 'event-expired'));
            $this->fail('Expected an invalid transition exception.');
        } catch (InvalidOrderTransition) {
            $this->assertSame('paid', $order->fresh()->status->value);
            $this->assertSame('ready', $entitlement->fresh()->status);
            $this->assertEntitlements($order, 'ready');
        }
    }

    public function test_event_for_another_provider_reference_is_rejected_and_remains_locked(): void
    {
        [$order, $entitlement] = $this->orderWithLockedEntitlement();

        try {
            app(PaymentEventApplier::class)->apply(
                new PaymentEvent(
                    eventId: 'event-forged',
                    providerReference: 'different-provider-reference',
                    merchantReference: $order->public_id,
                    status: PaymentStatus::Paid,
                    occurredAt: Date::now(),
                    amount: 350_000,
                    currency: 'IDR',
                ),
            );
            $this->fail('Expected a provider reference mismatch.');
        } catch (PaymentReferenceMismatch) {
            $this->assertSame('pending', $order->fresh()->status->value);
            $this->assertSame('locked', $entitlement->fresh()->status);
            $this->assertEntitlements($order, 'locked');
        }
    }

    public function test_transaction_handler_rejects_calls_without_an_active_service_context(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Payment events require an active service transaction.');

        app(OrderPaymentEventHandler::class)->applyInCurrentServiceTransaction(
            new PaymentEvent(
                eventId: 'event-outside-service-transaction',
                providerReference: 'fake-provider-reference',
                merchantReference: '01K3H9M5YXB62D9QK7E5V2G8Z1',
                status: PaymentStatus::Paid,
                occurredAt: Date::now(),
                amount: 350_000,
                currency: 'IDR',
            ),
        );
    }

    public function test_paid_event_with_different_money_snapshot_is_rejected_and_remains_locked(): void
    {
        [$order, $entitlement] = $this->orderWithLockedEntitlement();

        try {
            app(PaymentEventApplier::class)->apply(new PaymentEvent(
                eventId: 'event-wrong-amount',
                providerReference: 'fake-provider-reference',
                merchantReference: $order->public_id,
                status: PaymentStatus::Paid,
                occurredAt: Date::now(),
                amount: 349_999,
                currency: 'IDR',
            ));
            $this->fail('Expected a payment amount mismatch.');
        } catch (PaymentAmountMismatch) {
            $this->assertSame('pending', $order->fresh()->status->value);
            $this->assertSame('locked', $entitlement->fresh()->status);
            $this->assertEntitlements($order, 'locked');
        }
    }

    public function test_event_with_different_merchant_reference_is_rejected_and_remains_locked(): void
    {
        [$order, $entitlement] = $this->orderWithLockedEntitlement();

        try {
            app(PaymentEventApplier::class)->apply(new PaymentEvent(
                eventId: 'event-wrong-merchant-reference',
                providerReference: 'fake-provider-reference',
                merchantReference: '01K3H9M5YXB62D9QK7E5V2G8Z9',
                status: PaymentStatus::Paid,
                occurredAt: Date::now(),
                amount: 350_000,
                currency: 'IDR',
            ));
            $this->fail('Expected a merchant reference mismatch.');
        } catch (PaymentReferenceMismatch) {
            $this->assertSame('pending', $order->fresh()->status->value);
            $this->assertSame('locked', $entitlement->fresh()->status);
            $this->assertEntitlements($order, 'locked');
        }
    }

    /** @return array{Order, Entitlement} */
    private function orderWithLockedEntitlement(): array
    {
        $fixture = DirectPublicOrderFixture::create(
            paymentMethodCode: 'fake',
            amount: 350_000,
            gatewayReference: 'fake-provider-reference',
            paymentMethodActive: false,
            expiresAt: Date::now()->addHour(),
        );

        return [$fixture['order'], $fixture['entitlements']['ist']];
    }

    private function event(PaymentStatus $status, string $eventId): PaymentEvent
    {
        return new PaymentEvent(
            eventId: $eventId,
            providerReference: 'fake-provider-reference',
            merchantReference: Order::query()->where('gateway_ref', 'fake-provider-reference')->valueOrFail('public_id'),
            status: $status,
            occurredAt: Date::now(),
            amount: 350_000,
            currency: 'IDR',
        );
    }

    private function assertEntitlements(Order $order, string $status): void
    {
        $this->assertSame(
            ['dass21' => $status, 'ist' => $status],
            Entitlement::query()->where('order_id', $order->id)->orderBy('test_type')->pluck('status', 'test_type')->all(),
        );
    }
}
