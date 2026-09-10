<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Models\Branch;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\Participant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        $this->assertDatabaseHas('payment_webhook_events', [
            'event_id' => $this->eventId('paid'),
            'outcome' => 'rejected',
            'error_code' => 'money_mismatch',
        ]);
    }

    /** @return array{Order, Entitlement} */
    private function orderWithLockedEntitlement(): array
    {
        $branch = Branch::query()->create([
            'code' => 'CENTRAL',
            'name' => 'LSI Pusat',
            'ref_code' => 'CENTRAL-REF',
            'is_default' => true,
        ]);
        $packageId = $this->directPackage();
        $participant = Participant::query()->create([
            'branch_id' => $branch->id,
            'referral_branch_id' => $branch->id,
            'referral_source' => 'default',
            'package_id' => $packageId,
            'source_system' => 'DIRECT_PUBLIC',
            'full_name' => 'Ayu Pratiwi',
            'gender' => 'female',
            'birth_date' => '2001-04-15',
            'education_level' => 'SMA/SMK',
            'intended_field' => 'KAIGO',
            'phone' => '+6281234567890',
            'test_number' => 'LSI-202608-000001-ABCDEF',
        ]);
        $paymentMethodId = DB::table('payment_methods')->insertGetId([
            'code' => 'xendit',
            'display_name' => 'Xendit Invoice',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $orderPublicId = (string) Str::ulid();
        $caseId = DB::table('assessment_cases')->insertGetId([
            'public_id' => $orderPublicId,
            'participant_id' => $participant->id,
            'organization_id' => $branch->id,
            'package_id' => $packageId,
            'origin' => 'DIRECT_PUBLIC',
            'intended_field_snapshot' => $participant->intended_field,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $order = Order::query()->create([
            'public_id' => $orderPublicId,
            'participant_id' => $participant->id,
            'assessment_case_id' => $caseId,
            'payment_method_id' => $paymentMethodId,
            'status' => 'pending',
            'amount' => 350_000,
            'currency' => 'IDR',
            'gateway_ref' => 'invoice-123',
            'expires_at' => Date::now()->addHour(),
        ]);
        $entitlement = Entitlement::query()->create([
            'participant_id' => $participant->id,
            'order_id' => $order->id,
            'test_type' => 'ist',
            'status' => 'locked',
        ]);

        return [$order, $entitlement];
    }

    private function directPackage(): int
    {
        $key = (string) Str::ulid();
        $packageId = DB::table('packages')->insertGetId([
            'code' => 'PKG-'.$key,
            'name' => 'Paket pembayaran langsung',
            'amount' => 350_000,
            'currency' => 'IDR',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('package_items')->insert([
            ['package_id' => $packageId, 'test_type' => 'ist', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['package_id' => $packageId, 'test_type' => 'dass21', 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()],
        ]);

        return $packageId;
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
}
