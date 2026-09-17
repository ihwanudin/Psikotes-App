<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Actions\Payments\FinalizeAssessmentBill;
use App\Data\Payments\PaymentEvent;
use App\Enums\PaymentStatus;
use App\Models\AssessmentBillItem;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentAccessFixture;

final class AssessmentBillPaymentFinalizationTest extends OrganizationPaymentTestCase
{
    use DatabaseTruncation;

    private array $bill;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::setUp();
        Date::setTestNow('2024-02-29 10:15:00+07:00');
        $this->bill = $this->pendingAttempt();
    }

    public function test_collective_payment_settles_every_item_and_activates_only_complete_attempts(): void
    {
        $incomplete = $this->appendAttempt();
        DB::table('consent_records')->where('participant_id', $incomplete['participant'])
            ->where('consent_type', 'psychotest')->update(['status' => 'declined', 'consented_at' => null]);

        $result = $this->finalize();

        $this->assertSame(['decision' => 'settled', 'allocationCount' => 2, 'activatedAttemptCount' => 1], $result);
        $this->assertDatabaseHas('assessment_bills', ['id' => $this->bill['bill'], 'status' => 'paid', 'paid_at' => now()]);
        $this->assertSame(0, DB::table('assessment_bill_items')->where('bill_id', $this->bill['bill'])->whereNull('settled_at')->count());
        $this->assertDatabaseHas('assessment_participants', ['id' => $this->bill['attempt'], 'assessment_status' => 'READY']);
        $this->assertDatabaseHas('assessment_participants', ['id' => $incomplete['attempt'], 'assessment_status' => 'PROVISIONED']);
        $this->assertDatabaseHas('assessment_entitlements', ['assessment_participant_id' => $incomplete['attempt'], 'status' => 'locked']);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.paid')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment.activated')->count());
        $this->assertDatabaseCount('outbox_messages', 1);
        $audit = DB::table('audit_logs')->where('action', 'assessment_bill.paid')->sole();
        $context = json_decode((string) $audit->context, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('2024-02-29 03:15:00', $audit->occurred_at);
        $this->assertSame('2029-02-28 03:15:00', $audit->expires_at);
        $this->assertSame([
            'version' => 1,
            'eventIdHash' => hash('sha256', 'xendit-invoice-event-paid'),
            'providerReferenceHash' => hash('sha256', 'xendit-invoice-reference'),
            'amount' => 200,
            'currency' => 'IDR',
            'paidAt' => '2024-02-29T03:15:00.000000Z',
            'billItemIds' => DB::table('assessment_bill_items')->where('bill_id', $this->bill['bill'])
                ->orderBy('id')->pluck('id')->all(),
        ], $context);
        $this->assertStringNotContainsString('xendit-invoice-event-paid', (string) $audit->context);
        $this->assertStringNotContainsString('xendit-invoice-reference', (string) $audit->context);
        $this->assertSame('2024-02-29 03:15:00', DB::table('assessment_bills')
            ->where('id', $this->bill['bill'])->value('paid_at'));
        $this->assertSame(0, DB::table('assessment_bill_items')->where('bill_id', $this->bill['bill'])
            ->where('settled_at', '!=', '2024-02-29 03:15:00')->count());
    }

    public function test_expired_event_uses_exact_audit_retention_without_settling_business_timestamps(): void
    {
        $billBefore = DB::table('assessment_bills')->where('id', $this->bill['bill'])->sole();
        $itemBefore = DB::table('assessment_bill_items')->where('id', $this->bill['item'])->sole();

        $result = $this->finalize($this->event(PaymentStatus::Expired));

        $this->assertSame('transitioned', $result['decision']);
        $audit = DB::table('audit_logs')->where('action', 'assessment_bill.expired')->sole();
        $this->assertSame('2024-02-29 03:15:00', $audit->occurred_at);
        $this->assertSame('2029-02-28 03:15:00', $audit->expires_at);
        $this->assertSame([
            'version' => 1,
            'eventIdHash' => hash('sha256', 'xendit-invoice-event-paid'),
            'providerReferenceHash' => hash('sha256', 'xendit-invoice-reference'),
            'status' => 'expired',
            'amount' => 100,
            'currency' => 'IDR',
            'occurredAt' => '2024-02-29T03:15:00.000000Z',
            'billItemIds' => [$this->bill['item']],
        ], json_decode((string) $audit->context, true, flags: JSON_THROW_ON_ERROR));
        $billAfter = DB::table('assessment_bills')->where('id', $this->bill['bill'])->sole();
        $itemAfter = DB::table('assessment_bill_items')->where('id', $this->bill['item'])->sole();
        $this->assertSame('expired', $billAfter->status);
        $this->assertNull($billAfter->paid_at);
        $this->assertSame($billBefore->created_at, $billAfter->created_at);
        $this->assertEquals($itemBefore, $itemAfter);
        $this->assertStringNotContainsString('xendit-invoice-event-paid', (string) $audit->context);
        $this->assertStringNotContainsString('xendit-invoice-reference', (string) $audit->context);
    }

    public function test_exact_replay_and_late_terminal_events_are_no_ops(): void
    {
        $this->assertSame('settled', $this->finalize()['decision']);
        $paidAt = DB::table('assessment_bills')->where('id', $this->bill['bill'])->value('paid_at');

        $this->travel(5)->minutes();
        $this->assertSame('replayed', $this->finalize($this->event(occurredAt: $paidAt))['decision']);
        $this->assertSame('ignored', $this->finalize($this->event(PaymentStatus::Expired))['decision']);
        $this->assertSame('ignored', $this->finalize($this->event(PaymentStatus::Cancelled))['decision']);

        $this->assertSame($paidAt, DB::table('assessment_bills')->where('id', $this->bill['bill'])->value('paid_at'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.paid')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment.activated')->count());
        $this->assertDatabaseCount('outbox_messages', 1);
    }

    public function test_paid_replay_identity_mismatch_or_corrupt_allocation_fails_closed(): void
    {
        $this->finalize();
        $paidAt = DB::table('assessment_bills')->where('id', $this->bill['bill'])->value('paid_at');
        try {
            $this->finalize($this->event(eventId: 'different-paid-event', occurredAt: $paidAt));
            $this->fail('Expected replay identity mismatch.');
        } catch (DomainException) {
            $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.paid')->count());
        }

        DB::table('assessment_bill_items')->where('id', $this->bill['item'])->update(['settled_at' => null]);
        try {
            $this->finalize($this->event(PaymentStatus::Expired));
            $this->fail('Expected corrupt paid allocation rejection.');
        } catch (DomainException) {
            $this->assertDatabaseHas('assessment_bills', ['id' => $this->bill['bill'], 'status' => 'paid']);
            $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.paid')->count());
            $this->assertDatabaseCount('outbox_messages', 1);
        }
    }

    public function test_partial_overpayment_and_reference_or_currency_mismatch_fail_without_mutation(): void
    {
        foreach ([99, 101] as $amount) {
            $this->assertRejected($this->event(amount: $amount));
        }
        $this->assertRejected($this->event(providerReference: 'foreign-provider-reference'));
        $this->assertRejected($this->event(merchantReference: 'AB_01K3H9M5YXB62D9QK7E5V2G8Z9'));
        DB::statement('PRAGMA foreign_keys = OFF');
        try {
            DB::table('assessment_bills')->where('id', $this->bill['bill'])->update(['currency' => 'USD']);
            $this->assertRejected($this->event());
        } finally {
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }

    public function test_corrupt_item_charge_or_attempt_scope_fails_closed(): void
    {
        DB::table('assessment_participants')->where('id', $this->bill['attempt'])->update(['metadata' => '{}']);
        $this->assertRejected($this->event());
        DB::table('assessment_participants')->where('id', $this->bill['attempt'])->update([
            'metadata' => json_encode(['checkout_contract_version' => 'checkout-v2'], JSON_THROW_ON_ERROR),
        ]);
        DB::table('assessment_charges')->where('id', $this->bill['charge'])->update(['price_snapshot' => '{}']);
        $this->assertRejected($this->event());
        DB::table('assessment_charges')->where('id', $this->bill['charge'])->update(['price_snapshot' => $this->bill['priceSnapshot']]);
        DB::table('assessment_bill_items')->where('id', $this->bill['item'])->update(['settled_at' => now()]);
        $this->assertRejected($this->event());
    }

    public function test_failure_while_saving_fifth_item_rolls_back_all_payment_effects(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->appendAttempt();
        }
        $readyBeforeFinalize = DB::table('assessment_entitlements')->where('status', 'ready')->count();
        $saved = 0;
        AssessmentBillItem::updating(function () use (&$saved): void {
            if (++$saved === 5) {
                throw new RuntimeException('synthetic fifth allocation crash');
            }
        });
        try {
            $this->finalize();
            $this->fail('Expected synthetic allocation crash.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic fifth allocation crash', $exception->getMessage());
        } finally {
            AssessmentBillItem::flushEventListeners();
        }

        $this->assertDatabaseHas('assessment_bills', ['id' => $this->bill['bill'], 'status' => 'pending', 'paid_at' => null]);
        $this->assertSame(6, DB::table('assessment_bill_items')->where('bill_id', $this->bill['bill'])->whereNull('settled_at')->count());
        $this->assertSame(0, DB::table('audit_logs')->whereIn('action', ['assessment_bill.paid', 'assessment.activated'])->count());
        $this->assertDatabaseCount('outbox_messages', 0);
        $this->assertSame(
            $readyBeforeFinalize,
            DB::table('assessment_entitlements')->where('status', 'ready')->count(),
        );
    }

    public function test_pending_nonpaid_event_does_not_mutate_payment_state(): void
    {
        $this->assertSame('ignored', $this->finalize($this->event(PaymentStatus::Pending))['decision']);
        $this->assertDatabaseHas('assessment_bills', ['id' => $this->bill['bill'], 'status' => 'pending', 'paid_at' => null]);
        $this->assertDatabaseHas('assessment_bill_items', ['id' => $this->bill['item'], 'settled_at' => null]);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public function test_nonservice_context_is_rejected(): void
    {
        $this->expectException(LogicException::class);
        app(RlsContextRunner::class)->run(new RlsContext('participant', $this->bill['organization'], $this->bill['participant']),
            fn () => $this->finalize());
    }

    public function test_outer_transaction_is_rejected(): void
    {
        $this->expectException(LogicException::class);
        DB::transaction(fn () => $this->finalize());
    }

    public function test_parent_service_rollback_cancels_finalization_activation_and_outbox(): void
    {
        try {
            app(RlsContextRunner::class)->run(new RlsContext('service'), function (): void {
                $this->assertSame('settled', $this->finalize()['decision']);
                throw new RuntimeException('synthetic parent rollback');
            });
            $this->fail('Expected parent rollback.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic parent rollback', $exception->getMessage());
        }

        $this->assertDatabaseHas('assessment_bills', ['id' => $this->bill['bill'], 'status' => 'pending', 'paid_at' => null]);
        $this->assertDatabaseHas('assessment_bill_items', ['id' => $this->bill['item'], 'settled_at' => null]);
        $this->assertDatabaseHas('assessment_entitlements', ['id' => $this->bill['entitlement'], 'status' => 'locked']);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    /** @return array{decision: string, allocationCount: int, activatedAttemptCount: int} */
    private function finalize(?PaymentEvent $event = null): array
    {
        return app(FinalizeAssessmentBill::class)->execute($event ?? $this->event());
    }

    private function event(
        PaymentStatus $status = PaymentStatus::Paid,
        ?int $amount = null,
        ?string $providerReference = null,
        ?string $merchantReference = null,
        mixed $occurredAt = null,
        string $eventId = 'xendit-invoice-event-paid',
    ): PaymentEvent {
        return new PaymentEvent(
            eventId: $eventId,
            providerReference: $providerReference ?? 'xendit-invoice-reference',
            merchantReference: $merchantReference ?? $this->bill['reference'],
            status: $status,
            occurredAt: $occurredAt === null ? now() : CarbonImmutable::parse($occurredAt),
            amount: $amount ?? (int) DB::table('assessment_bills')->where('id', $this->bill['bill'])->value('amount'),
            currency: 'IDR',
        );
    }

    private function assertRejected(PaymentEvent $event): void
    {
        try {
            $this->finalize($event);
            $this->fail('Expected fail-closed finalization.');
        } catch (DomainException) {
            $this->assertDatabaseHas('assessment_bills', ['id' => $this->bill['bill'], 'status' => 'pending', 'paid_at' => null]);
            $this->assertDatabaseCount('audit_logs', 0);
            $this->assertDatabaseCount('outbox_messages', 0);
        }
    }

    private function pendingAttempt(?array $identity = null): array
    {
        $fixture = AssessmentAccessFixture::create(identity: $identity);
        $snapshot = DB::table('assessment_charges')->where('id', $fixture['charge'])->value('price_snapshot');
        DB::table('assessment_participants')->where('id', $fixture['attempt'])->update(['assessment_status' => 'PROVISIONED']);
        DB::table('assessment_entitlements')->where('id', $fixture['entitlement'])->update(['status' => 'locked', 'ready_at' => null]);
        DB::table('assessment_bill_items')->where('id', $fixture['item'])->update(['settled_at' => null]);
        DB::table('assessment_participants')->where('id', $fixture['attempt'])->update([
            'funding_mode' => 'INVOICED_TO_ORGANIZATION',
            'metadata' => json_encode([
                'checkout_contract_version' => 'checkout-v2',
                'checkout_initial_funding_mode' => 'INVOICED_TO_ORGANIZATION',
            ], JSON_THROW_ON_ERROR),
        ]);
        DB::table('assessment_bills')->where('id', $fixture['bill'])->update([
            'status' => 'pending',
            'paid_at' => null,
            'gateway_ref' => $identity === null ? 'xendit-invoice-reference' : null,
        ]);

        return [...$fixture, 'reference' => DB::table('assessment_bills')->where('id', $fixture['bill'])->value('public_reference'),
            'priceSnapshot' => $snapshot];
    }

    private function appendAttempt(): array
    {
        $other = $this->pendingAttempt(['organization' => $this->bill['organization']]);
        DB::table('assessment_bill_items')->where('id', $other['item'])->update(['bill_id' => $this->bill['bill']]);
        DB::table('assessment_bills')->where('id', $other['bill'])->delete();
        DB::table('assessment_bills')->where('id', $this->bill['bill'])->update([
            'amount' => DB::raw('amount + 100'), 'item_count' => DB::raw('item_count + 1'),
        ]);

        return $other;
    }
}
