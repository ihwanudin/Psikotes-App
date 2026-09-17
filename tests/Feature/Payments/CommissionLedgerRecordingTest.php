<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Data\Payments\PaymentEvent;
use App\Enums\PaymentStatus;
use App\Models\Admin;
use App\Models\Order;
use App\Services\Payments\PaymentWebhookProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssessmentAccessFixture;
use Tests\Support\DirectPublicOrderFixture;
use Tests\TestCase;

final class CommissionLedgerRecordingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-09-17 10:15:00+07:00');
    }

    public function test_direct_order_paid_webhook_records_commission_once_after_payment_is_applied(): void
    {
        $fixture = DirectPublicOrderFixture::create(
            paymentMethodCode: 'xendit',
            amount: 350_000,
            gatewayReference: 'commission-direct-reference',
            expiresAt: Date::now()->addHour(),
        );
        $this->feeRule((int) $fixture['branch']->id, percentageBps: 1000);
        $event = $this->directOrderEvent($fixture['order'], 'commission-direct-paid');
        $processor = app(PaymentWebhookProcessor::class);

        $first = $processor->process('xendit', $event);
        $duplicate = $processor->process('xendit', $event);

        $this->assertSame('applied', $first->outcome->value);
        $this->assertSame('duplicate', $duplicate->outcome->value);
        $this->assertDatabaseHas('orders', ['id' => $fixture['order']->id, 'status' => 'paid']);
        $this->assertDatabaseCount('commission_entries', 1);
        $this->assertDatabaseHas('commission_entries', [
            'branch_id' => $fixture['branch']->id,
            'participant_id' => $fixture['participant']->id,
            'source_type' => 'direct_order',
            'source_id' => $fixture['order']->id,
            'order_id' => $fixture['order']->id,
            'rate_basis' => 'base_amount',
            'rate_basis_amount' => 350_000,
            'gross_amount' => 350_000,
            'commission_amount' => 35_000,
            'period_month' => '2026-09-01',
            'status' => 'accrued',
        ]);
        $this->assertDatabaseCount('commission_ledger_gaps', 0);
    }

    public function test_missing_fee_rule_records_actionable_gap_without_blocking_payment_or_entitlement(): void
    {
        $fixture = DirectPublicOrderFixture::create(
            paymentMethodCode: 'xendit',
            amount: 150_000,
            gatewayReference: 'commission-gap-reference',
            expiresAt: Date::now()->addHour(),
        );

        $result = app(PaymentWebhookProcessor::class)->process(
            'xendit',
            $this->directOrderEvent($fixture['order'], 'commission-gap-paid'),
        );

        $this->assertSame('applied', $result->outcome->value);
        $this->assertDatabaseHas('orders', ['id' => $fixture['order']->id, 'status' => 'paid']);
        $this->assertDatabaseHas('entitlements', [
            'order_id' => $fixture['order']->id,
            'test_type' => 'ist',
            'status' => 'ready',
        ]);
        $this->assertDatabaseHas('entitlements', [
            'order_id' => $fixture['order']->id,
            'test_type' => 'dass21',
            'status' => 'ready',
        ]);
        $this->assertDatabaseCount('commission_entries', 0);
        $this->assertDatabaseHas('commission_ledger_gaps', [
            'branch_id' => $fixture['branch']->id,
            'source_type' => 'direct_order',
            'source_id' => $fixture['order']->id,
            'reason_code' => 'fee_rule_missing',
            'status' => 'open',
        ]);
    }

    public function test_invalid_fee_rule_shape_records_calculation_gap_without_blocking_payment(): void
    {
        $fixture = DirectPublicOrderFixture::create(
            paymentMethodCode: 'xendit',
            amount: 150_000,
            gatewayReference: 'commission-calculation-gap-reference',
            expiresAt: Date::now()->addHour(),
        );
        $admin = Admin::query()->create([
            'branch_id' => $fixture['branch']->id,
            'name' => 'Commission Admin',
            'email' => 'commission-calculation-gap-admin@example.test',
            'password' => 'password',
            'role' => 'branch_admin',
        ]);
        DB::table('branch_fee_rules')->insert([
            'branch_id' => $fixture['branch']->id,
            'rate_basis' => 'base_amount',
            'rate_type' => 'fixed',
            'currency' => 'IDR',
            'rounding_mode' => 'floor',
            'effective_from' => Date::now()->subDay(),
            'created_by_admin_id' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(PaymentWebhookProcessor::class)->process(
            'xendit',
            $this->directOrderEvent($fixture['order'], 'commission-calculation-gap-paid'),
        );

        $this->assertSame('applied', $result->outcome->value);
        $this->assertDatabaseHas('orders', ['id' => $fixture['order']->id, 'status' => 'paid']);
        $this->assertDatabaseCount('commission_entries', 0);
        $this->assertDatabaseHas('commission_ledger_gaps', [
            'branch_id' => $fixture['branch']->id,
            'source_type' => 'direct_order',
            'source_id' => $fixture['order']->id,
            'reason_code' => 'calculation_error',
            'status' => 'open',
        ]);
    }

    public function test_assessment_bill_paid_webhook_records_commission_per_settled_item(): void
    {
        $fixture = $this->pendingAssessmentBill();
        $this->feeRule((int) DB::table('participants')->where('id', $fixture['participant'])->value('referral_branch_id'), percentageBps: 1250);

        $result = app(PaymentWebhookProcessor::class)->process(
            'xendit',
            $this->assessmentBillEvent($fixture),
        );

        $this->assertSame('applied', $result->outcome->value);
        $this->assertDatabaseHas('assessment_bills', ['id' => $fixture['bill'], 'status' => 'paid']);
        $this->assertDatabaseCount('commission_entries', 1);
        $this->assertDatabaseHas('commission_entries', [
            'participant_id' => $fixture['participant'],
            'assessment_bill_id' => $fixture['bill'],
            'assessment_bill_item_id' => $fixture['item'],
            'source_type' => 'assessment_bill_item',
            'source_id' => $fixture['item'],
            'rate_basis' => 'base_amount',
            'rate_basis_amount' => 100,
            'gross_amount' => 100,
            'commission_amount' => 12,
            'period_month' => '2026-09-01',
            'status' => 'accrued',
        ]);
    }

    private function directOrderEvent(Order $order, string $eventId): PaymentEvent
    {
        return new PaymentEvent(
            eventId: $eventId,
            providerReference: (string) $order->gateway_ref,
            merchantReference: (string) $order->public_id,
            status: PaymentStatus::Paid,
            occurredAt: Date::now(),
            amount: (int) $order->amount,
            currency: 'IDR',
        );
    }

    /** @param array<string, mixed> $fixture */
    private function assessmentBillEvent(array $fixture): PaymentEvent
    {
        return new PaymentEvent(
            eventId: 'commission-assessment-bill-paid',
            providerReference: 'commission-bill-reference',
            merchantReference: (string) $fixture['reference'],
            status: PaymentStatus::Paid,
            occurredAt: Date::now(),
            amount: (int) DB::table('assessment_bills')->where('id', $fixture['bill'])->value('amount'),
            currency: 'IDR',
        );
    }

    /** @return array<string, mixed> */
    private function pendingAssessmentBill(): array
    {
        $fixture = AssessmentAccessFixture::create();
        DB::table('assessment_participants')->where('id', $fixture['attempt'])->update([
            'assessment_status' => 'PROVISIONED',
            'funding_mode' => 'INVOICED_TO_ORGANIZATION',
            'metadata' => json_encode([
                'checkout_contract_version' => 'checkout-v2',
                'checkout_initial_funding_mode' => 'INVOICED_TO_ORGANIZATION',
            ], JSON_THROW_ON_ERROR),
        ]);
        DB::table('assessment_entitlements')->where('id', $fixture['entitlement'])->update(['status' => 'locked', 'ready_at' => null]);
        DB::table('assessment_bill_items')->where('id', $fixture['item'])->update(['settled_at' => null]);
        DB::table('assessment_bills')->where('id', $fixture['bill'])->update([
            'status' => 'pending',
            'paid_at' => null,
            'gateway_ref' => 'commission-bill-reference',
        ]);

        return [
            ...$fixture,
            'reference' => DB::table('assessment_bills')->where('id', $fixture['bill'])->value('public_reference'),
        ];
    }

    private function feeRule(int $branchId, int $percentageBps): void
    {
        $admin = Admin::query()->create([
            'branch_id' => $branchId,
            'name' => 'Commission Admin',
            'email' => 'commission-admin-'.uniqid().'@example.test',
            'password' => 'password',
            'role' => 'branch_admin',
        ]);
        DB::table('branch_fee_rules')->insert([
            'branch_id' => $branchId,
            'rate_basis' => 'base_amount',
            'rate_type' => 'percentage',
            'percentage_bps' => $percentageBps,
            'currency' => 'IDR',
            'rounding_mode' => 'floor',
            'effective_from' => Date::now()->subDay(),
            'created_by_admin_id' => $admin->id,
            'metadata' => json_encode(['overlapGuard' => 'service-layer'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
