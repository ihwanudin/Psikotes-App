<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Data\Payments\PaymentEvent;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\Participant;
use App\Services\Payments\PaymentWebhookProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Support\DirectPublicPaymentFixture;

final class PaymentWebhookProcessorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-08-25 13:00:00+07:00');
    }

    public function test_paid_event_changes_order_and_entitlement_exactly_once(): void
    {
        [$order, $entitlement] = $this->orderWithLockedEntitlement();
        $processor = app(PaymentWebhookProcessor::class);
        $event = $this->event(PaymentStatus::Paid, 'invoice-event-1');

        $first = $processor->process('xendit', $event);
        Date::setTestNow('2026-08-25 14:00:00+07:00');
        $duplicate = $processor->process('xendit', $event);

        $this->assertSame('applied', $first->outcome->value);
        $this->assertSame('duplicate', $duplicate->outcome->value);
        $this->assertSame('paid', $order->fresh()->status->value);
        $this->assertTrue($order->fresh()->paid_at->equalTo('2026-08-25 13:00:00+07:00'));
        $this->assertSame('ready', $entitlement->fresh()->status);
        $this->assertTrue($entitlement->fresh()->ready_at->equalTo('2026-08-25 13:00:00+07:00'));
        $this->assertDatabaseCount('payment_webhook_events', 1);
        $this->assertDatabaseHas('payment_webhook_events', [
            'provider' => 'xendit',
            'event_id' => 'invoice-event-1',
            'outcome' => 'applied',
        ]);
    }

    public function test_same_event_id_with_different_normalized_intent_is_a_conflict(): void
    {
        [$order, $entitlement] = $this->orderWithLockedEntitlement();
        $processor = app(PaymentWebhookProcessor::class);

        $processor->process('xendit', $this->event(PaymentStatus::Pending, 'invoice-event-2'));
        $conflict = $processor->process('xendit', $this->event(PaymentStatus::Paid, 'invoice-event-2'));

        $this->assertSame('conflict', $conflict->outcome->value);
        $this->assertSame('pending', $order->fresh()->status->value);
        $this->assertSame('locked', $entitlement->fresh()->status);
        $this->assertDatabaseCount('payment_webhook_events', 1);
    }

    public function test_expired_event_keeps_entitlement_locked(): void
    {
        [$order, $entitlement] = $this->orderWithLockedEntitlement();

        $result = app(PaymentWebhookProcessor::class)->process(
            'xendit',
            $this->event(PaymentStatus::Expired, 'invoice-event-expired'),
        );

        $this->assertSame('applied', $result->outcome->value);
        $this->assertSame('expired', $order->fresh()->status->value);
        $this->assertSame('locked', $entitlement->fresh()->status);
    }

    public function test_reordered_terminal_event_is_recorded_as_rejected_without_partial_change(): void
    {
        [$order, $entitlement] = $this->orderWithLockedEntitlement();
        $processor = app(PaymentWebhookProcessor::class);
        $processor->process('xendit', $this->event(PaymentStatus::Paid, 'invoice-event-paid'));

        $reordered = $processor->process(
            'xendit',
            $this->event(PaymentStatus::Expired, 'invoice-event-late-expired'),
        );

        $this->assertSame('rejected', $reordered->outcome->value);
        $this->assertSame('invalid_transition', $reordered->reason);
        $this->assertSame('paid', $order->fresh()->status->value);
        $this->assertSame('ready', $entitlement->fresh()->status);
        $this->assertDatabaseHas('payment_webhook_events', [
            'event_id' => 'invoice-event-late-expired',
            'outcome' => 'rejected',
            'error_code' => 'invalid_transition',
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
        $participant = Participant::query()->create([
            'branch_id' => $branch->id,
            'referral_branch_id' => $branch->id,
            'referral_source' => 'default',
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
        $case = DirectPublicPaymentFixture::caseFor($participant, $branch, $orderPublicId, 350_000);
        $order = Order::query()->create([
            'public_id' => $orderPublicId,
            'participant_id' => $participant->id,
            'assessment_case_id' => $case->id,
            'payment_method_id' => $paymentMethodId,
            'status' => 'pending',
            'amount' => 350_000,
            'currency' => 'IDR',
            'gateway_ref' => 'xendit-invoice-reference',
            'expires_at' => Date::now()->addHour(),
        ]);
        $entitlement = Entitlement::query()->create([
            'participant_id' => $participant->id,
            'order_id' => $order->id,
            'assessment_case_id' => $case->id,
            'test_type' => 'ist',
            'status' => 'locked',
        ]);

        return [$order, $entitlement];
    }

    private function event(PaymentStatus $status, string $eventId): PaymentEvent
    {
        return new PaymentEvent(
            eventId: $eventId,
            providerReference: 'xendit-invoice-reference',
            merchantReference: Order::query()->where('gateway_ref', 'xendit-invoice-reference')->valueOrFail('public_id'),
            status: $status,
            occurredAt: Date::now(),
            amount: 350_000,
            currency: 'IDR',
        );
    }
}
