<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Data\Payments\PaymentEvent;
use App\Data\Payments\PaymentWebhookResult;
use App\Enums\PaymentStatus;
use App\Enums\PaymentWebhookOutcome;
use App\Models\AssessmentBillItem;
use App\Models\Branch;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\Participant;
use App\Services\Payments\PaymentEventDispatcher;
use App\Services\Payments\PaymentWebhookProcessor;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentAccessFixture;

final class AssessmentBillWebhookDispatchTest extends OrganizationPaymentTestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::setUp();
        Date::setTestNow('2026-09-01T12:00:00Z');
    }

    public function test_paid_ab_reference_routes_only_to_bill_even_when_provider_reference_matches_legacy(): void
    {
        $bill = $this->assessmentBill();
        [$order, $entitlement] = $this->legacyOrder($bill['providerReference']);
        $legacyQueries = $this->countLegacyOrderQueries();

        $result = $this->process($this->event($bill));

        $this->assertSame(PaymentWebhookOutcome::Applied, $result->outcome);
        $this->assertSame(0, $legacyQueries());
        $this->assertDatabaseHas('assessment_bills', ['id' => $bill['bill'], 'status' => 'paid']);
        $this->assertSame('pending', $order->fresh()->status->value);
        $this->assertSame('locked', $entitlement->fresh()->status);
    }

    public function test_malformed_or_unknown_ab_never_falls_back_to_matching_legacy_order(): void
    {
        foreach (['AB_bad', 'AB_01K3H9M5YXB62D9QK7E5V2G8Z9'] as $index => $reference) {
            [$order, $entitlement] = $this->legacyOrder('shared-provider-'.$index);
            $legacyQueries = $this->countLegacyOrderQueries();
            $result = $this->process($this->event([
                'providerReference' => 'shared-provider-'.$index,
                'reference' => $reference,
                'amount' => 100,
            ], eventId: 'ab-rejected-'.$index));

            $this->assertSame(PaymentWebhookOutcome::Rejected, $result->outcome);
            $this->assertSame('reference_mismatch', $result->reason);
            $this->assertSame(0, $legacyQueries());
            $this->assertSame('pending', $order->fresh()->status->value);
            $this->assertSame('locked', $entitlement->fresh()->status);
        }
    }

    public function test_ab_from_non_xendit_provider_is_rejected_before_bill_mutation(): void
    {
        $bill = $this->assessmentBill();

        $result = $this->process($this->event($bill), 'fake');

        $this->assertSame(PaymentWebhookOutcome::Rejected, $result->outcome);
        $this->assertSame('reference_mismatch', $result->reason);
        $this->assertDatabaseHas('assessment_bills', ['id' => $bill['bill'], 'status' => 'pending', 'paid_at' => null]);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public function test_ab_amount_mismatch_is_rejected_without_bill_or_access_mutation(): void
    {
        $bill = $this->assessmentBill();

        $result = $this->process($this->event([...$bill, 'amount' => 99], eventId: 'wrong-bill-amount'));

        $this->assertSame(PaymentWebhookOutcome::Rejected, $result->outcome);
        $this->assertSame('money_mismatch', $result->reason);
        $this->assertDatabaseHas('assessment_bills', ['id' => $bill['bill'], 'status' => 'pending', 'paid_at' => null]);
        $this->assertDatabaseHas('assessment_bill_items', ['id' => $bill['item'], 'settled_at' => null]);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public function test_duplicate_event_with_different_merchant_reference_is_a_conflict(): void
    {
        [$order] = $this->legacyOrder('legacy-provider-reference');
        $event = new PaymentEvent('same-event-id', 'legacy-provider-reference', $order->public_id,
            PaymentStatus::Pending, now(), 100, 'IDR');
        $this->assertSame(PaymentWebhookOutcome::Ignored, $this->process($event)->outcome);

        $conflict = $this->process(new PaymentEvent('same-event-id', 'legacy-provider-reference', (string) Str::ulid(),
            PaymentStatus::Pending, now(), 100, 'IDR'));

        $this->assertSame(PaymentWebhookOutcome::Conflict, $conflict->outcome);
        $this->assertSame('intent_mismatch', $conflict->reason);
        $this->assertDatabaseCount('payment_webhook_events', 1);
    }

    public function test_every_current_payment_status_has_an_explicit_bill_mapping_and_only_paid_settles(): void
    {
        $expected = [
            PaymentStatus::Pending->value => [PaymentWebhookOutcome::Ignored, 'pending', false],
            PaymentStatus::Paid->value => [PaymentWebhookOutcome::Applied, 'paid', true],
            PaymentStatus::Expired->value => [PaymentWebhookOutcome::Applied, 'expired', false],
            PaymentStatus::Cancelled->value => [PaymentWebhookOutcome::Applied, 'rejected', false],
        ];
        $this->assertSame(array_keys($expected), array_map(
            static fn (PaymentStatus $status): string => $status->value,
            PaymentStatus::cases(),
        ));

        foreach (PaymentStatus::cases() as $status) {
            $bill = $this->assessmentBill();
            [$outcome, $billStatus, $settled] = $expected[$status->value];

            $this->assertSame($outcome,
                $this->process($this->event($bill, $status, 'bill-'.$status->value))->outcome);
            $this->assertDatabaseHas('assessment_bills', [
                'id' => $bill['bill'],
                'status' => $billStatus,
            ]);
            $this->assertSame($settled, DB::table('assessment_bill_items')->where('id', $bill['item'])
                ->whereNotNull('settled_at')->exists());
        }

        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.expired')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.rejected')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.paid')->count());
        $this->assertDatabaseCount('outbox_messages', 1);
    }

    public function test_paid_bill_ignores_late_nonpaid_events_without_downgrade(): void
    {
        $bill = $this->assessmentBill();
        $this->assertSame(PaymentWebhookOutcome::Applied, $this->process($this->event($bill))->outcome);

        foreach ([PaymentStatus::Pending, PaymentStatus::Expired, PaymentStatus::Cancelled] as $index => $status) {
            $this->assertSame(PaymentWebhookOutcome::Ignored,
                $this->process($this->event($bill, $status, 'late-'.$index))->outcome);
        }

        $this->assertDatabaseHas('assessment_bills', ['id' => $bill['bill'], 'status' => 'paid']);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.paid')->count());
        $this->assertSame(0, DB::table('audit_logs')->whereIn('action', ['assessment_bill.expired', 'assessment_bill.rejected'])->count());
        $this->assertDatabaseCount('outbox_messages', 1);
    }

    public function test_terminal_bill_cannot_be_revived_or_changed_by_later_status(): void
    {
        $expired = $this->assessmentBill();
        $cancelled = $this->assessmentBill();
        $this->process($this->event($expired, PaymentStatus::Expired, 'expired-first'));
        $this->process($this->event($cancelled, PaymentStatus::Cancelled, 'cancelled-first'));

        foreach ([
            [$expired, PaymentStatus::Paid, 'expired-paid'],
            [$expired, PaymentStatus::Cancelled, 'expired-cancelled'],
            [$cancelled, PaymentStatus::Paid, 'cancelled-paid'],
            [$cancelled, PaymentStatus::Expired, 'cancelled-expired'],
        ] as [$bill, $status, $eventId]) {
            $result = $this->process($this->event($bill, $status, $eventId));
            $this->assertSame(PaymentWebhookOutcome::Rejected, $result->outcome);
            $this->assertSame('bill_invalid', $result->reason);
        }

        $this->assertDatabaseHas('assessment_bills', ['id' => $expired['bill'], 'status' => 'expired', 'paid_at' => null]);
        $this->assertDatabaseHas('assessment_bills', ['id' => $cancelled['bill'], 'status' => 'rejected', 'paid_at' => null]);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public function test_exact_terminal_replay_is_idempotent(): void
    {
        $bill = $this->assessmentBill();
        $event = $this->event($bill, PaymentStatus::Expired, 'terminal-replay');

        $this->assertSame(PaymentWebhookOutcome::Applied, $this->process($event)->outcome);
        $this->assertSame(PaymentWebhookOutcome::Duplicate, $this->process($event)->outcome);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.expired')->count());
        $this->assertDatabaseCount('payment_webhook_events', 1);
    }

    public function test_unexpected_finalizer_failure_rolls_back_event_claim_and_all_payment_effects(): void
    {
        $bill = $this->assessmentBill();
        AssessmentBillItem::updating(static fn () => throw new RuntimeException('synthetic allocation crash'));
        try {
            $this->process($this->event($bill));
            $this->fail('Expected synthetic finalizer failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic allocation crash', $exception->getMessage());
        } finally {
            AssessmentBillItem::flushEventListeners();
        }

        $this->assertDatabaseCount('payment_webhook_events', 0);
        $this->assertDatabaseHas('assessment_bills', ['id' => $bill['bill'], 'status' => 'pending', 'paid_at' => null]);
        $this->assertDatabaseHas('assessment_bill_items', ['id' => $bill['item'], 'settled_at' => null]);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('outbox_messages', 0);

        $this->assertSame(PaymentWebhookOutcome::Applied, $this->process($this->event($bill))->outcome);
        $this->assertDatabaseCount('payment_webhook_events', 1);
    }

    public function test_non_ab_reference_preserves_legacy_paid_transition(): void
    {
        [$order, $entitlement] = $this->legacyOrder('legacy-paid-provider');

        $result = $this->process(new PaymentEvent('legacy-paid-event', 'legacy-paid-provider', $order->public_id,
            PaymentStatus::Paid, now(), 100, 'IDR'));

        $this->assertSame(PaymentWebhookOutcome::Applied, $result->outcome);
        $this->assertSame('paid', $order->fresh()->status->value);
        $this->assertSame('ready', $entitlement->fresh()->status);
    }

    public function test_dispatcher_requires_current_service_authority(): void
    {
        $this->expectException(LogicException::class);
        app(PaymentEventDispatcher::class)->applyInCurrentServiceTransaction('xendit', new PaymentEvent(
            'outside-service', 'provider-reference', (string) Str::ulid(), PaymentStatus::Pending, now(), 100, 'IDR',
        ));
    }

    private function process(PaymentEvent $event, string $provider = 'xendit'): PaymentWebhookResult
    {
        return app(PaymentWebhookProcessor::class)->process($provider, $event);
    }

    /** @return array<string, mixed> */
    private function assessmentBill(): array
    {
        $fixture = AssessmentAccessFixture::create();
        $providerReference = 'invoice-'.$fixture['bill'];
        DB::table('assessment_participants')->where('id', $fixture['attempt'])->update([
            'assessment_status' => 'PROVISIONED',
            'funding_mode' => 'INVOICED_TO_ORGANIZATION',
            'metadata' => json_encode([
                'checkout_contract_version' => 'checkout-v2',
                'checkout_initial_funding_mode' => 'INVOICED_TO_ORGANIZATION',
            ], JSON_THROW_ON_ERROR),
        ]);
        DB::table('assessment_entitlements')->where('id', $fixture['entitlement'])
            ->update(['status' => 'locked', 'ready_at' => null]);
        DB::table('assessment_bill_items')->where('id', $fixture['item'])->update(['settled_at' => null]);
        DB::table('assessment_bills')->where('id', $fixture['bill'])->update([
            'status' => 'pending', 'paid_at' => null, 'gateway_ref' => $providerReference,
        ]);

        return [...$fixture,
            'providerReference' => $providerReference,
            'reference' => DB::table('assessment_bills')->where('id', $fixture['bill'])->value('public_reference'),
            'amount' => 100,
        ];
    }

    /** @return array{Order, Entitlement} */
    private function legacyOrder(string $providerReference): array
    {
        $key = (string) Str::ulid();
        $branch = Branch::query()->create(['code' => $key, 'name' => 'Synthetic', 'ref_code' => $key]);
        $participant = Participant::query()->create([
            'branch_id' => $branch->id, 'referral_branch_id' => $branch->id, 'referral_source' => 'default',
            'source_system' => 'SELEKSI_BEASISWA_JEPANG',
            'full_name' => 'Synthetic', 'gender' => 'male', 'birth_date' => '2000-01-01',
            'education_level' => 'SMA/SMK', 'intended_field' => 'UMUM', 'phone' => '+620000000000',
        ]);
        $method = DB::table('payment_methods')->insertGetId([
            'code' => $key, 'display_name' => 'Synthetic', 'is_active' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $order = Order::query()->create([
            'public_id' => (string) Str::ulid(), 'participant_id' => $participant->id,
            'payment_method_id' => $method, 'status' => 'pending', 'amount' => 100,
            'currency' => 'IDR', 'gateway_ref' => $providerReference, 'expires_at' => now()->addHour(),
        ]);
        $entitlement = Entitlement::query()->create([
            'participant_id' => $participant->id, 'order_id' => $order->id,
            'test_type' => 'ist', 'status' => 'locked',
        ]);

        return [$order, $entitlement];
    }

    private function event(array $bill, PaymentStatus $status = PaymentStatus::Paid,
        string $eventId = 'bill-paid-event'): PaymentEvent
    {
        return new PaymentEvent($eventId, $bill['providerReference'], $bill['reference'], $status,
            now(), $bill['amount'], 'IDR');
    }

    /** @return callable(): int */
    private function countLegacyOrderQueries(): callable
    {
        $count = 0;
        DB::listen(static function (QueryExecuted $query) use (&$count): void {
            if (str_contains(strtolower($query->sql), '"orders"')) {
                $count++;
            }
        });

        return static function () use (&$count): int {
            return $count;
        };
    }
}
