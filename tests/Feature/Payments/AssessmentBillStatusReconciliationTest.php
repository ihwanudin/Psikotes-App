<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Contracts\PaymentProvider;
use App\Data\Payments\PaymentEvent;
use App\Enums\PaymentStatus;
use App\Security\RlsContextRunner;
use App\Services\Payments\PaymentWebhookProcessor;
use App\Services\Payments\ReconcilePendingAssessmentBills;
use App\Services\Payments\XenditProvider;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentAccessFixture;

final class AssessmentBillStatusReconciliationTest extends OrganizationPaymentTestCase
{
    use DatabaseTruncation;

    private int $xenditMethod;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::setUp();
        Date::setTestNow('2026-09-01T12:00:00Z');
        config()->set('services.xendit.secret_key', 'xnd_test_reconciliation');
        config()->set('services.xendit.base_url', 'https://api.xendit.co');
        app()->instance(PaymentProvider::class, new XenditProvider);
        $this->xenditMethod = DB::table('payment_methods')->insertGetId([
            'code' => 'xendit', 'display_name' => 'Xendit', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_selection_and_outbound_work_are_deterministic_and_separately_bounded(): void
    {
        $bills = [$this->bill(), $this->bill(), $this->bill(), $this->bill()];
        Http::fake(fn (Request $request) => Http::response($this->payloadForRequest($request, $bills, 'PENDING')));

        $result = app(ReconcilePendingAssessmentBills::class)->handle(limit: 2, scan: 3);

        $this->assertSame([3, 2, 0, 2, 0], [
            $result->scanned, $result->checked, $result->applied, $result->ignored, $result->failed,
        ]);
        Http::assertSentCount(2);
        $requests = Http::recorded();
        $this->assertStringEndsWith('/'.$bills[0]['providerReference'], $requests[0][0]->url());
        $this->assertStringEndsWith('/'.$bills[1]['providerReference'], $requests[1][0]->url());
    }

    public function test_only_pending_xendit_bills_with_provider_reference_are_selected(): void
    {
        foreach (['reserved', 'issuing', 'unknown', 'paid', 'expired', 'rejected'] as $status) {
            $this->bill($status);
        }
        $this->bill('pending', gatewayReference: null);
        $this->bill('pending', xendit: false);
        $eligible = $this->bill();
        Http::fake([
            '*' => Http::response($this->payload($eligible, 'PENDING')),
        ]);

        $result = app(ReconcilePendingAssessmentBills::class)->handle();

        $this->assertSame([1, 1, 0, 1, 0], [
            $result->scanned, $result->checked, $result->applied, $result->ignored, $result->failed,
        ]);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/'.$eligible['providerReference']));
    }

    public function test_get_occurs_after_selection_transaction_and_rls_context_are_cleared(): void
    {
        $bill = $this->bill();
        Http::fake(function (Request $request) use ($bill) {
            $this->assertSame('GET', $request->method());
            $this->assertSame(0, DB::transactionLevel());
            $this->assertNull(app(RlsContextRunner::class)->current());

            return Http::response($this->payload($bill, 'PENDING'));
        });

        app(ReconcilePendingAssessmentBills::class)->handle();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->hasHeader('Authorization')
            && $request->url() === 'https://api.xendit.co/v2/invoices/'.$bill['providerReference']);
    }

    public function test_paid_and_expired_results_use_the_claim_dispatcher_and_finalizer_path(): void
    {
        $paid = $this->bill();
        $expired = $this->bill();
        Http::fake(fn (Request $request) => Http::response(
            str_ends_with($request->url(), '/'.$paid['providerReference'])
                ? $this->payload($paid, 'PAID')
                : $this->payload($expired, 'EXPIRED'),
        ));

        $result = app(ReconcilePendingAssessmentBills::class)->handle();

        $this->assertSame([2, 2, 2, 0, 0], [
            $result->scanned, $result->checked, $result->applied, $result->ignored, $result->failed,
        ]);
        $this->assertDatabaseHas('assessment_bills', ['id' => $paid['bill'], 'status' => 'paid']);
        $this->assertDatabaseHas('assessment_bills', ['id' => $expired['bill'], 'status' => 'expired', 'paid_at' => null]);
        $this->assertDatabaseCount('payment_webhook_events', 2);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.paid')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.expired')->count());
    }

    public function test_provider_timeout_and_malformed_payload_do_not_stop_later_candidate(): void
    {
        $timeout = $this->bill();
        $malformed = $this->bill();
        $paid = $this->bill();
        Http::fake(function (Request $request) use ($timeout, $malformed, $paid) {
            return match (true) {
                str_ends_with($request->url(), '/'.$timeout['providerReference']) => throw new ConnectionException('synthetic timeout'),
                str_ends_with($request->url(), '/'.$malformed['providerReference']) => Http::response(['id' => 'malformed']),
                default => Http::response($this->payload($paid, 'PAID')),
            };
        });

        $result = app(ReconcilePendingAssessmentBills::class)->handle(limit: 3, scan: 3);

        $this->assertSame([3, 3, 1, 0, 2], [
            $result->scanned, $result->checked, $result->applied, $result->ignored, $result->failed,
        ]);
        $this->assertDatabaseHas('assessment_bills', ['id' => $paid['bill'], 'status' => 'paid']);
    }

    public function test_webhook_winning_during_status_get_makes_reconciliation_duplicate_without_double_effects(): void
    {
        $bill = $this->bill();
        Http::fake(function () use ($bill) {
            app(PaymentWebhookProcessor::class)->process('xendit', new PaymentEvent(
                eventId: 'xendit-invoice:'.hash('sha256', $bill['providerReference']).':paid',
                providerReference: $bill['providerReference'],
                merchantReference: $bill['reference'],
                status: PaymentStatus::Paid,
                occurredAt: now(),
                amount: 100,
                currency: 'IDR',
            ));

            return Http::response($this->payload($bill, 'PAID'));
        });

        $result = app(ReconcilePendingAssessmentBills::class)->handle();

        $this->assertSame([1, 1, 0, 1, 0], [
            $result->scanned, $result->checked, $result->applied, $result->ignored, $result->failed,
        ]);
        $this->assertDatabaseCount('payment_webhook_events', 1);
        $this->assertDatabaseHas('assessment_bills', ['id' => $bill['bill'], 'status' => 'paid']);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.paid')->count());
        $this->assertDatabaseCount('outbox_messages', 1);
        Http::assertSentCount(1);
    }

    public function test_invalid_bounds_fail_before_selection_or_provider_request(): void
    {
        Http::fake();

        foreach ([[0, 1], [501, 501], [2, 1], [1, 501]] as [$limit, $scan]) {
            try {
                app(ReconcilePendingAssessmentBills::class)->handle($limit, $scan);
                $this->fail('Invalid reconciliation bounds must fail.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('Assessment bill reconciliation bounds are invalid.', $exception->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    public function test_status_checks_are_get_only_and_never_create_or_expire_invoice(): void
    {
        $bill = $this->bill();
        Http::fake(['*' => Http::response($this->payload($bill, 'PENDING'))]);

        app(ReconcilePendingAssessmentBills::class)->handle();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && ! str_contains($request->url(), 'expire'));
        Http::assertSentCount(1);
    }

    /** @return array<string, mixed> */
    private function bill(string $status = 'pending', ?string $gatewayReference = 'generated', bool $xendit = true): array
    {
        $fixture = AssessmentAccessFixture::create();
        $providerReference = $gatewayReference === 'generated' ? 'invoice-'.$fixture['bill'] : $gatewayReference;
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
            'status' => $status,
            'paid_at' => $status === 'paid' ? now() : null,
            'gateway_ref' => $providerReference,
            ...($xendit ? ['payment_method_id' => $this->xenditMethod] : []),
        ]);

        return [...$fixture,
            'providerReference' => $providerReference,
            'reference' => DB::table('assessment_bills')->where('id', $fixture['bill'])->value('public_reference'),
        ];
    }

    /** @param list<array<string, mixed>> $bills
     * @return array<string, mixed>
     */
    private function payloadForRequest(Request $request, array $bills, string $status): array
    {
        foreach ($bills as $bill) {
            if (str_ends_with($request->url(), '/'.$bill['providerReference'])) {
                return $this->payload($bill, $status);
            }
        }

        $this->fail('Unexpected provider reference.');
    }

    /** @param array<string, mixed> $bill
     * @return array<string, mixed>
     */
    private function payload(array $bill, string $status): array
    {
        return [
            'id' => $bill['providerReference'],
            'external_id' => $bill['reference'],
            'status' => $status,
            'amount' => 100,
            'currency' => 'IDR',
            'paid_at' => $status === 'PAID' ? now()->toIso8601String() : null,
            'created' => now()->subHour()->toIso8601String(),
            'updated' => now()->toIso8601String(),
        ];
    }
}
