<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Models\Entitlement;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\Support\DirectPublicOrderFixture;
use Tests\TestCase;

final class XenditWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-08-25 13:00:00+07:00');
        config()->set('services.xendit.callback_token', 'callback-secret');
    }

    public function test_forged_callback_is_rejected_without_state_change_or_detail_leak(): void
    {
        [$order, $entitlement] = $this->orderWithLockedEntitlement();

        $this->postJson('/webhooks/xendit', $this->payload($order), [
            'x-callback-token' => 'wrong-token',
        ])->assertUnauthorized()->assertExactJson([
            'error' => [
                'code' => 'WEBHOOK_REJECTED',
                'message' => 'Webhook ditolak.',
            ],
        ]);

        $this->assertSame('pending', $order->fresh()->status->value);
        $this->assertSame('locked', $entitlement->fresh()->status);
        $this->assertEntitlements($order, 'locked');
        $this->assertDatabaseCount('payment_webhook_events', 0);
    }

    public function test_unknown_status_is_rejected_without_persisting_the_payload(): void
    {
        [$order, $entitlement] = $this->orderWithLockedEntitlement();

        $this->postJson('/webhooks/xendit', $this->payload($order, [
            'status' => 'UNKNOWN_PROVIDER_STATUS',
        ]), $this->validHeaders())->assertUnprocessable()->assertExactJson([
            'error' => [
                'code' => 'WEBHOOK_REJECTED',
                'message' => 'Webhook ditolak.',
            ],
        ]);

        $this->assertSame('pending', $order->fresh()->status->value);
        $this->assertSame('locked', $entitlement->fresh()->status);
        $this->assertEntitlements($order, 'locked');
        $this->assertDatabaseCount('payment_webhook_events', 0);
    }

    public function test_paid_callback_and_retry_unlock_exactly_once(): void
    {
        [$order, $entitlement] = $this->orderWithLockedEntitlement();
        $payload = $this->payload($order);

        $this->postJson('/webhooks/xendit', $payload, $this->validHeaders())
            ->assertOk()
            ->assertExactJson(['status' => 'received']);
        Date::setTestNow('2026-08-25 14:00:00+07:00');
        $this->postJson('/webhooks/xendit', $payload, $this->validHeaders())
            ->assertOk()
            ->assertExactJson(['status' => 'received']);

        $this->assertSame('paid', $order->fresh()->status->value);
        $this->assertSame(
            CarbonImmutable::parse('2026-08-25 13:05:00+07:00')->getTimestamp(),
            $order->fresh()->paid_at->getTimestamp(),
        );
        $this->assertSame('ready', $entitlement->fresh()->status);
        $this->assertSame(
            CarbonImmutable::parse('2026-08-25 13:05:00+07:00')->getTimestamp(),
            $entitlement->fresh()->ready_at->getTimestamp(),
        );
        $this->assertEntitlements($order, 'ready');
        $this->assertDatabaseCount('payment_webhook_events', 1);
        $this->assertDatabaseCount('outbox_messages', 1);
        $this->assertDatabaseHas('outbox_messages', [
            'topic' => 'participant.activation',
            'aggregate_type' => Order::class,
            'aggregate_id' => $order->public_id,
            'status' => 'pending',
            'attempts' => 0,
        ]);
    }

    public function test_settled_retry_is_the_same_logical_paid_event(): void
    {
        [$order] = $this->orderWithLockedEntitlement();

        $this->postJson('/webhooks/xendit', $this->payload($order), $this->validHeaders())->assertOk();
        $this->postJson('/webhooks/xendit', $this->payload($order, [
            'status' => 'SETTLED',
        ]), $this->validHeaders())->assertOk();

        $this->assertSame('paid', $order->fresh()->status->value);
        $this->assertDatabaseCount('payment_webhook_events', 1);
    }

    public function test_reordered_expired_callback_cannot_reverse_a_paid_order(): void
    {
        [$order, $entitlement] = $this->orderWithLockedEntitlement();
        $this->postJson('/webhooks/xendit', $this->payload($order), $this->validHeaders())->assertOk();

        $this->postJson('/webhooks/xendit', $this->payload($order, [
            'status' => 'EXPIRED',
            'updated' => '2026-08-25T13:06:00+07:00',
        ]), $this->validHeaders())->assertOk()->assertExactJson(['status' => 'received']);

        $this->assertSame('paid', $order->fresh()->status->value);
        $this->assertSame('ready', $entitlement->fresh()->status);
        $this->assertEntitlements($order, 'ready');
        $this->assertDatabaseCount('payment_webhook_events', 2);
        $this->assertDatabaseHas('payment_webhook_events', [
            'event_id' => $this->eventId('expired'),
            'outcome' => 'rejected',
            'error_code' => 'invalid_transition',
        ]);
    }

    public function test_expired_callback_keeps_entitlement_locked(): void
    {
        [$order, $entitlement] = $this->orderWithLockedEntitlement();

        $this->postJson('/webhooks/xendit', $this->payload($order, [
            'status' => 'EXPIRED',
        ]), $this->validHeaders())->assertOk();

        $this->assertSame('expired', $order->fresh()->status->value);
        $this->assertSame('locked', $entitlement->fresh()->status);
        $this->assertEntitlements($order, 'locked');
    }

    public function test_paid_callback_with_wrong_amount_is_rejected_and_remains_locked(): void
    {
        [$order, $entitlement] = $this->orderWithLockedEntitlement();

        $this->postJson('/webhooks/xendit', $this->payload($order, [
            'amount' => 349_999,
            'paid_amount' => 349_999,
        ]), $this->validHeaders())->assertUnprocessable()->assertExactJson([
            'error' => [
                'code' => 'WEBHOOK_REJECTED',
                'message' => 'Webhook ditolak.',
            ],
        ]);

        $this->assertSame('pending', $order->fresh()->status->value);
        $this->assertSame('locked', $entitlement->fresh()->status);
        $this->assertEntitlements($order, 'locked');
        $this->assertDatabaseHas('payment_webhook_events', [
            'event_id' => $this->eventId('paid'),
            'outcome' => 'rejected',
            'error_code' => 'money_mismatch',
        ]);
    }

    /** @return array{Order, Entitlement} */
    private function orderWithLockedEntitlement(): array
    {
        $fixture = DirectPublicOrderFixture::create(
            paymentMethodCode: 'xendit',
            amount: 350_000,
            gatewayReference: 'invoice-123',
            expiresAt: Date::now()->addHour(),
        );

        return [$fixture['order'], $fixture['entitlements']['ist']];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Order $order, array $overrides = []): array
    {
        return array_merge([
            'id' => 'invoice-123',
            'external_id' => $order->public_id,
            'status' => 'PAID',
            'amount' => 350_000,
            'paid_amount' => 350_000,
            'currency' => 'IDR',
            'paid_at' => '2026-08-25T13:05:00+07:00',
            'created' => '2026-08-25T13:00:00+07:00',
            'updated' => '2026-08-25T13:05:00+07:00',
        ], $overrides);
    }

    /** @return array<string, string> */
    private function validHeaders(): array
    {
        return ['x-callback-token' => 'callback-secret'];
    }

    private function eventId(string $status): string
    {
        return 'xendit-invoice:'.hash('sha256', 'invoice-123').':'.$status;
    }

    private function assertEntitlements(Order $order, string $status): void
    {
        $this->assertSame(
            ['dass21' => $status, 'ist' => $status],
            Entitlement::query()->where('order_id', $order->id)->orderBy('test_type')->pluck('status', 'test_type')->all(),
        );
    }
}
