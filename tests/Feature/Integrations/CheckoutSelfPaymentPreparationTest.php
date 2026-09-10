<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Actions\Integrations\CheckoutSessionLifecycle;
use App\Actions\Integrations\CoordinateCheckoutSelfPayment;
use App\Actions\Integrations\EstablishCheckoutSession;
use App\Actions\Integrations\IssueCheckoutHandoff;
use App\Actions\Integrations\IssueCheckoutSelfPayment;
use App\Actions\Integrations\PrepareCheckoutSelfPayment;
use App\Actions\Payments\IssueAssessmentBillInvoice;
use App\Contracts\PaymentProvider;
use App\Data\Integrations\CheckoutHandoffIssueInput;
use App\Data\Integrations\CheckoutSelfPaymentClaimResult;
use App\Data\Integrations\CheckoutSelfPaymentIssuanceResult;
use App\Data\Integrations\CheckoutSelfPaymentPreparation;
use App\Data\Integrations\CheckoutSessionExchangeInput;
use App\Data\Integrations\CheckoutSessionMutationCredentials;
use App\Data\Payments\CreateInvoiceRequest;
use App\Data\Payments\PaymentInvoice;
use App\Enums\CheckoutHandoffIntent;
use App\Models\AssessmentBill;
use App\Models\CheckoutSession;
use App\Models\IntegrationClient;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Payments\Exceptions\PaymentProviderException;
use DomainException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentBillingFixture;

final class CheckoutSelfPaymentPreparationTest extends OrganizationPaymentTestCase
{
    use DatabaseTruncation;

    private int $method;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('assessment_integration.checkout_handoff.enabled', true);
        config()->set('assessment_integration.checkout_handoff.ttl_seconds', 600);
        config()->set('assessment_integration.checkout_session', [
            'enabled' => true, 'idle_minutes' => 30, 'absolute_minutes' => 120,
            'terminal_retention_days' => 30,
        ]);
        config()->set('assessment_integration.checkout.enabled', true);
        $this->method = DB::table('payment_methods')->insertGetId([
            'code' => 'xendit', 'display_name' => 'Synthetic Xendit', 'is_active' => true,
        ]);
    }

    public function test_positive_self_payment_creates_once_and_exact_replay_returns_same_preparation(): void
    {
        $fixture = $this->established();
        $first = $this->prepare($fixture, true);
        DB::table('packages')->where('id', $fixture['package'])->update(['amount' => 999]);
        DB::table('payment_methods')->where('id', $this->method)->update(['is_active' => false]);
        $second = $this->prepare($fixture, true);

        $this->assertInstanceOf(CheckoutSelfPaymentPreparation::class, $first);
        $this->assertSame($first->billId, $second->billId);
        $this->assertSame($fixture['organization'], $first->organizationId);
        $this->assertSame('reserved', $first->status);
        $this->assertTrue($first->created);
        $this->assertFalse($second->created);
        $this->assertDatabaseCount('assessment_bills', 1);
        $this->assertDatabaseCount('assessment_charges', 1);
        $this->assertDatabaseCount('assessment_bill_items', 1);
        $this->assertSame(130, (int) DB::table('assessment_bills')->value('amount'));
        $this->assertSame('checkout-self-v1:'.$fixture['attemptPublicId'], DB::table('assessment_bills')->value('idempotency_key'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.reserved')->count());
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public function test_changed_consultation_conflicts_without_a_second_bill(): void
    {
        $fixture = $this->established();
        $this->prepare($fixture, false);

        $this->assertUnavailable(fn () => $this->prepare($fixture, true));
        $this->assertDatabaseCount('assessment_bills', 1);
        $this->assertDatabaseCount('assessment_charges', 1);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.reserved')->count());
    }

    public function test_organization_and_zero_total_never_create_payment_rows(): void
    {
        $organization = $this->established('INVOICED_TO_ORGANIZATION', 100);
        $zero = $this->established('COMMERCIAL_SELF_PAY', 0);

        $this->assertUnavailable(fn () => $this->prepare($organization, false));
        $this->assertUnavailable(fn () => $this->prepare($zero, false));
        $this->assertDatabaseCount('assessment_bills', 0);
        $this->assertDatabaseCount('assessment_charges', 0);
        $this->assertDatabaseCount('assessment_bill_items', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    #[DataProvider('staleAuthorityChanges')]
    public function test_stale_hydrated_authority_is_denied_before_reservation(string $change): void
    {
        $fixture = $this->established();
        $snapshot = app(CheckoutSessionLifecycle::class)->hydrateWithCsrfDelivery($this->credentials($fixture));
        $this->assertSame($fixture['attempt'], $snapshot->assessmentParticipantId);
        $this->changeAuthority($change, $fixture);

        $this->assertUnavailable(fn () => $this->prepare($fixture, false));
        $this->assertDatabaseCount('assessment_bills', 0);
        $this->assertDatabaseCount('assessment_charges', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame(0, DB::transactionLevel());
    }

    public static function staleAuthorityChanges(): iterable
    {
        yield 'session revoked' => ['session'];
        yield 'recovery generation' => ['recovery'];
        yield 'payer policy changed' => ['policy'];
        yield 'client disabled' => ['client'];
        yield 'source disabled' => ['source'];
        yield 'package disabled' => ['package'];
        yield 'participant deleted' => ['participant'];
        yield 'attempt revoked' => ['revoked'];
        yield 'attempt finalized' => ['finalized'];
    }

    public function test_active_exact_xendit_method_is_required(): void
    {
        $fixture = $this->established();
        DB::table('payment_methods')->where('id', $this->method)->update(['is_active' => false]);
        $this->assertUnavailable(fn () => $this->prepare($fixture, false));
        DB::table('payment_methods')->where('id', $this->method)->update([
            'is_active' => true, 'code' => 'manual_transfer',
        ]);
        $this->assertUnavailable(fn () => $this->prepare($fixture, false));
        $this->assertDatabaseCount('assessment_bills', 0);
    }

    public function test_action_rejects_ambient_context_or_transaction_without_partial_rows(): void
    {
        $fixture = $this->established();
        foreach ([
            fn () => app(RlsContextRunner::class)->runAsService(fn () => $this->prepare($fixture, false)),
            fn () => DB::transaction(fn () => $this->prepare($fixture, false)),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Ambient payment preparation was accepted.');
            } catch (\LogicException $exception) {
                $this->assertSame('Checkout payment preparation owns its service transaction.', $exception->getMessage());
            }
        }
        $this->assertDatabaseCount('assessment_bills', 0);
        $this->assertDatabaseCount('assessment_charges', 0);
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame(0, DB::transactionLevel());
    }

    #[DataProvider('acceptedExistingStates')]
    public function test_canonical_existing_states_replay_even_after_xendit_is_disabled(string $status): void
    {
        $fixture = $this->established();
        $created = $this->prepare($fixture, false);
        $bill = AssessmentBill::query()->findOrFail($created->billId);
        if ($status === 'pending') {
            $bill->update([
                'status' => 'pending', 'gateway_ref' => 'synthetic-provider-ref',
                'invoice_url' => 'https://invoice.xendit.co/synthetic', 'expires_at' => now()->addDay(),
            ]);
        } elseif ($status === 'paid') {
            $paidAt = now()->startOfSecond();
            $bill->update([
                'status' => 'paid', 'gateway_ref' => 'synthetic-provider-ref',
                'invoice_url' => 'https://invoice.xendit.co/synthetic', 'expires_at' => now()->addDay(),
                'paid_at' => $paidAt,
            ]);
            DB::table('assessment_bill_items')->where('bill_id', $bill->id)->update(['settled_at' => $paidAt]);
            DB::table('audit_logs')->insert([
                'branch_id' => $fixture['organization'], 'actor_type' => 'system', 'actor_id' => 'payment-provider',
                'action' => 'assessment_bill.paid', 'subject_type' => AssessmentBill::class,
                'subject_id' => (string) $bill->id, 'context' => '{}', 'occurred_at' => $paidAt,
                'expires_at' => $paidAt->copy()->addYears(2),
            ]);
        }
        DB::table('payment_methods')->where('id', $this->method)->update(['is_active' => false]);

        $replay = $this->prepare($fixture, false);
        $this->assertSame($bill->id, $replay->billId);
        $this->assertSame($status, $replay->status);
        $this->assertFalse($replay->created);
        $this->assertDatabaseCount('assessment_bills', 1);
        $this->assertDatabaseCount('assessment_charges', 1);
        $this->assertDatabaseCount('assessment_bill_items', 1);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public static function acceptedExistingStates(): iterable
    {
        yield ['reserved'];
        yield ['pending'];
        yield ['paid'];
    }

    #[DataProvider('blockedExistingStates')]
    public function test_recovery_and_terminal_existing_states_fail_closed(string $status): void
    {
        $fixture = $this->established();
        $created = $this->prepare($fixture, false);
        AssessmentBill::query()->whereKey($created->billId)->update(['status' => $status]);

        $this->assertUnavailable(fn () => $this->prepare($fixture, false));
        $this->assertDatabaseCount('assessment_bills', 1);
        $this->assertDatabaseCount('assessment_charges', 1);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public static function blockedExistingStates(): iterable
    {
        yield 'unknown' => ['unknown'];
        yield 'expired' => ['expired'];
        yield 'rejected' => ['rejected'];
    }

    #[DataProvider('corruptExistingChanges')]
    public function test_corrupt_existing_bill_fails_closed_without_replacement(string $change): void
    {
        $fixture = $this->established();
        $created = $this->prepare($fixture, false);
        $this->corruptExisting($change, $created->billId);

        $this->assertUnavailable(fn () => $this->prepare($fixture, false));
        $this->assertDatabaseCount('assessment_bills', 1);
        $this->assertDatabaseCount('assessment_charges', 1);
        $this->assertDatabaseCount('assessment_bill_items', 1);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public static function corruptExistingChanges(): iterable
    {
        yield 'bill total' => ['bill-total'];
        yield 'request hash' => ['request-hash'];
        yield 'item state' => ['item-state'];
        yield 'price snapshot' => ['snapshot'];
        yield 'payment method' => ['method'];
    }

    public function test_unexpected_reservation_failure_rolls_back_every_payment_row_and_context(): void
    {
        $fixture = $this->established();
        $auditCount = DB::table('audit_logs')->count();
        DB::listen(function (QueryExecuted $query): void {
            if (str_contains($query->sql, 'insert into "audit_logs"')) {
                throw new RuntimeException('synthetic-reservation-failure');
            }
        });

        try {
            $this->prepare($fixture, false);
            $this->fail('Synthetic failure did not run.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic-reservation-failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('assessment_bills', 0);
        $this->assertDatabaseCount('assessment_charges', 0);
        $this->assertDatabaseCount('assessment_bill_items', 0);
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
        $this->assertDatabaseCount('outbox_messages', 0);
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_claim_runs_after_preparation_commit_and_replays_one_canonical_message(): void
    {
        $fixture = $this->established();
        $preparationCommitted = false;
        DB::listen(function (QueryExecuted $query) use (&$preparationCommitted): void {
            if (str_contains($query->sql, 'insert into "audit_logs"')
                && in_array('assessment_bill.reserved', $query->bindings, true)) {
                DB::afterCommit(function () use (&$preparationCommitted): void {
                    $preparationCommitted = true;
                });
            }
            if (str_contains($query->sql, 'insert into "outbox_messages"')) {
                $this->assertTrue($preparationCommitted, 'Claim began before preparation committed.');
            }
        });

        $first = $this->coordinate($fixture, false);
        $second = $this->coordinate($fixture, false);
        $this->assertInstanceOf(CheckoutSelfPaymentClaimResult::class, $first);
        $this->assertSame('issuance_required', $first->state);
        $this->assertNotNull($first->messageId);
        $this->assertTrue(Str::isUlid($first->messageId));
        $this->assertSame($first->messageId, $second->messageId);
        $this->assertSame('issuing', DB::table('assessment_bills')->value('status'));
        $this->assertDatabaseCount('assessment_bills', 1);
        $this->assertDatabaseCount('assessment_charges', 1);
        $this->assertDatabaseCount('assessment_bill_items', 1);
        $this->assertDatabaseCount('outbox_messages', 1);
        $this->assertDatabaseHas('outbox_messages', [
            'message_id' => $first->messageId, 'topic' => 'assessment.bill.invoice-issuance',
            'status' => 'pending', 'attempts' => 0, 'processed_at' => null,
        ]);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.invoice_claimed')->count());
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame(0, DB::transactionLevel());
    }

    #[DataProvider('claimBypassStates')]
    public function test_pending_and_paid_bypass_claim_even_when_xendit_is_inactive(string $status): void
    {
        $fixture = $this->established();
        $prepared = $this->prepare($fixture, false);
        $bill = AssessmentBill::query()->findOrFail($prepared->billId);
        $attributes = [
            'status' => $status, 'gateway_ref' => 'synthetic-provider-ref',
            'invoice_url' => 'https://invoice.xendit.co/synthetic', 'expires_at' => now()->addDay(),
        ];
        if ($status === 'paid') {
            $paidAt = now()->startOfSecond();
            $attributes['paid_at'] = $paidAt;
            DB::table('assessment_bill_items')->where('bill_id', $bill->id)->update(['settled_at' => $paidAt]);
            DB::table('audit_logs')->insert([
                'branch_id' => $fixture['organization'], 'actor_type' => 'system', 'actor_id' => 'payment-provider',
                'action' => 'assessment_bill.paid', 'subject_type' => AssessmentBill::class,
                'subject_id' => (string) $bill->id, 'context' => '{}', 'occurred_at' => $paidAt,
                'expires_at' => $paidAt->copy()->addYears(2),
            ]);
        }
        $bill->update($attributes);
        DB::table('payment_methods')->where('id', $this->method)->update(['is_active' => false]);

        $result = $this->coordinate($fixture, false);
        $this->assertSame($status, $result->state);
        $this->assertNull($result->messageId);
        $this->assertDatabaseCount('outbox_messages', 0);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'assessment_bill.invoice_claimed')->count());
    }

    public static function claimBypassStates(): iterable
    {
        yield ['pending'];
        yield ['paid'];
    }

    #[DataProvider('claimUnavailableChanges')]
    public function test_recovery_terminal_corrupt_and_stale_claims_fail_generic(string $change): void
    {
        $fixture = $this->established();
        if ($change === 'recovery-required') {
            $claimed = $this->coordinate($fixture, false);
            DB::table('outbox_messages')->where('message_id', $claimed->messageId)->update([
                'status' => 'processing', 'attempts' => 1,
            ]);
        } elseif (in_array($change, ['expired', 'corrupt'], true)) {
            $prepared = $this->prepare($fixture, false);
            AssessmentBill::query()->whereKey($prepared->billId)->update($change === 'expired'
                ? ['status' => 'expired'] : ['request_hash' => str_repeat('f', 64)]);
        } elseif ($change === 'policy') {
            app(CheckoutSessionLifecycle::class)->hydrateWithCsrfDelivery($this->credentials($fixture));
            $this->changeAuthority('policy', $fixture);
        } else {
            app(CheckoutSessionLifecycle::class)->hydrateWithCsrfDelivery($this->credentials($fixture));
            $this->changeAuthority('session', $fixture);
        }

        $this->assertUnavailable(fn () => $this->coordinate($fixture, false));
        $this->assertSame(in_array($change, ['policy', 'session'], true) ? 0 : 1,
            DB::table('assessment_bills')->count());
        $this->assertLessThanOrEqual(1, DB::table('outbox_messages')->count());
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame(0, DB::transactionLevel());
    }

    public static function claimUnavailableChanges(): iterable
    {
        yield ['recovery-required'];
        yield ['expired'];
        yield ['corrupt'];
        yield ['policy'];
        yield ['session'];
    }

    public function test_claim_audit_failure_rolls_back_to_reserved_then_retry_claims_once(): void
    {
        $fixture = $this->established();
        $fail = true;
        DB::listen(function (QueryExecuted $query) use (&$fail): void {
            if ($fail && str_contains($query->sql, 'insert into "audit_logs"')
                && in_array('assessment_bill.invoice_claimed', $query->bindings, true)) {
                $fail = false;
                throw new RuntimeException('synthetic-claim-audit-failure');
            }
        });
        try {
            $this->coordinate($fixture, false);
            $this->fail('Synthetic claim failure did not run.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic-claim-audit-failure', $exception->getMessage());
        }
        $this->assertDatabaseHas('assessment_bills', ['status' => 'reserved']);
        $this->assertDatabaseCount('assessment_bills', 1);
        $this->assertDatabaseCount('assessment_charges', 1);
        $this->assertDatabaseCount('outbox_messages', 0);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'assessment_bill.invoice_claimed')->count());
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame(0, DB::transactionLevel());

        $result = $this->coordinate($fixture, false);
        $this->assertSame('issuance_required', $result->state);
        $this->assertDatabaseCount('outbox_messages', 1);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.invoice_claimed')->count());
    }

    public function test_claim_coordinator_rejects_ambient_context_or_transaction(): void
    {
        $fixture = $this->established();
        foreach ([
            fn () => app(RlsContextRunner::class)->runAsService(fn () => $this->coordinate($fixture, false)),
            fn () => DB::transaction(fn () => $this->coordinate($fixture, false)),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Ambient claim coordination was accepted.');
            } catch (\LogicException $exception) {
                $this->assertSame('Checkout payment claim owns an empty context and transaction.', $exception->getMessage());
            }
        }
        $this->assertDatabaseCount('assessment_bills', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_issuance_runs_outside_context_once_and_returns_only_pending_state(): void
    {
        $fixture = $this->established();
        $provider = $this->createMock(PaymentProvider::class);
        $invoice = null;
        $provider->expects($this->once())->method('createInvoice')
            ->with($this->callback(function (CreateInvoiceRequest $request) use (&$invoice): bool {
                $this->assertNull(app(RlsContextRunner::class)->current());
                $this->assertSame(0, DB::transactionLevel());
                $invoice = new PaymentInvoice('provider-p16', 'https://provider-return.example.test/not-authority',
                    $request->amount, $request->currency, $request->expiresAt);

                return true;
            }))->willReturnCallback(fn (): PaymentInvoice => $invoice);
        $provider->expects($this->once())->method('lookupInvoice')
            ->willReturnCallback(function (string $reference, int $amount, string $currency) use (&$invoice): PaymentInvoice {
                $this->assertNull(app(RlsContextRunner::class)->current());
                $this->assertSame(0, DB::transactionLevel());
                $this->assertSame((string) DB::table('assessment_bills')->value('public_reference'), $reference);
                $this->assertSame(100, $amount);
                $this->assertSame('IDR', $currency);

                return new PaymentInvoice($invoice->providerReference,
                    'https://payments.example.test/persisted-p16', $amount, $currency, $invoice->expiresAt);
            });
        app()->instance(PaymentProvider::class, $provider);

        $result = $this->issue($fixture, false);
        $this->assertInstanceOf(CheckoutSelfPaymentIssuanceResult::class, $result);
        $this->assertSame('pending', $result->state);
        $this->assertSame('https://payments.example.test/persisted-p16', $result->paymentUrl);
        $this->assertSame(['state', 'paymentUrl'], array_keys(get_object_vars($result)));
        $this->assertDatabaseHas('assessment_bills', ['status' => 'pending', 'gateway_ref' => 'provider-p16']);
        $this->assertDatabaseHas('outbox_messages', ['status' => 'processed', 'attempts' => 1]);
        $this->assertDatabaseCount('assessment_bills', 1);
        $this->assertDatabaseCount('outbox_messages', 1);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.invoice_issued')->count());
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame(0, DB::transactionLevel());
    }

    #[DataProvider('finalReadAuthorityChanges')]
    public function test_issue_revalidates_session_policy_and_catalog_after_provider_persistence(string $change): void
    {
        $fixture = $this->established();
        $provider = $this->createMock(PaymentProvider::class);
        $invoice = null;
        $provider->expects($this->once())->method('createInvoice')
            ->willReturnCallback(function (CreateInvoiceRequest $request) use (&$invoice): PaymentInvoice {
                return $invoice = new PaymentInvoice('provider-final-read',
                    'https://provider.example.test/final-read', $request->amount, $request->currency,
                    $request->expiresAt);
            });
        $provider->expects($this->once())->method('lookupInvoice')
            ->willReturnCallback(function () use (&$invoice, $change, $fixture): PaymentInvoice {
                match ($change) {
                    'session' => $this->changeAuthority('session', $fixture),
                    'policy' => $this->changeAuthority('policy', $fixture),
                    'catalog' => DB::table('packages')->where('id', $fixture['package'])
                        ->update(['name' => 'Changed During Provider Gap']),
                    default => throw new RuntimeException('Unknown final-read authority change.'),
                };

                return $invoice;
            });
        app()->instance(PaymentProvider::class, $provider);

        $this->assertUnavailable(fn () => $this->issue($fixture, false));
        $this->assertDatabaseHas('assessment_bills', [
            'status' => 'pending', 'invoice_url' => 'https://provider.example.test/final-read',
        ]);
    }

    public static function finalReadAuthorityChanges(): iterable
    {
        yield 'session revoked' => ['session'];
        yield 'payer policy changed' => ['policy'];
        yield 'catalog changed' => ['catalog'];
    }

    public function test_exact_pending_replay_never_calls_provider_again(): void
    {
        $pending = $this->established();
        app()->instance(PaymentProvider::class, $this->successfulProvider());
        $this->assertSame('pending', $this->issue($pending, false)->state);
        $never = $this->createMock(PaymentProvider::class);
        $never->expects($this->never())->method('createInvoice');
        $never->expects($this->never())->method('lookupInvoice');
        app()->instance(PaymentProvider::class, $never);
        $replay = $this->issue($pending, false);
        $this->assertSame('pending', $replay->state);
        $this->assertSame('https://payments.example.test/success', $replay->paymentUrl);
    }

    public function test_paid_state_never_calls_provider(): void
    {
        $paid = $this->established();
        $never = $this->createMock(PaymentProvider::class);
        $never->expects($this->never())->method('createInvoice');
        $never->expects($this->never())->method('lookupInvoice');
        app()->instance(PaymentProvider::class, $never);
        $prepared = $this->prepare($paid, false);
        $bill = AssessmentBill::query()->findOrFail($prepared->billId);
        $paidAt = now()->startOfSecond();
        $bill->update([
            'status' => 'paid', 'gateway_ref' => 'provider-paid',
            'invoice_url' => 'https://payments.example.test/paid', 'expires_at' => now()->addDay(),
            'paid_at' => $paidAt,
        ]);
        DB::table('assessment_bill_items')->where('bill_id', $bill->id)->update(['settled_at' => $paidAt]);
        DB::table('audit_logs')->insert([
            'branch_id' => $paid['organization'], 'actor_type' => 'system', 'actor_id' => 'payment-provider',
            'action' => 'assessment_bill.paid', 'subject_type' => AssessmentBill::class,
            'subject_id' => (string) $bill->id, 'context' => '{}', 'occurred_at' => $paidAt,
            'expires_at' => $paidAt->copy()->addYears(2),
        ]);
        $result = $this->issue($paid, false);
        $this->assertSame('paid', $result->state);
        $this->assertNull($result->paymentUrl);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public function test_create_exception_with_exact_lookup_still_returns_pending(): void
    {
        $fixture = $this->established();
        $provider = $this->createMock(PaymentProvider::class);
        $provider->expects($this->once())->method('createInvoice')
            ->willThrowException(new PaymentProviderException('synthetic create outcome unknown'));
        $provider->expects($this->once())->method('lookupInvoice')
            ->willReturnCallback(fn (string $reference, int $amount, string $currency): PaymentInvoice => new PaymentInvoice(
                'provider-recovered', 'https://payments.example.test/recovered', $amount, $currency, now()->addDay(),
            ));
        app()->instance(PaymentProvider::class, $provider);

        $result = $this->issue($fixture, false);
        $this->assertSame('pending', $result->state);
        $this->assertSame('https://payments.example.test/recovered', $result->paymentUrl);
        $this->assertDatabaseHas('assessment_bills', ['status' => 'pending', 'gateway_ref' => 'provider-recovered']);
    }

    public function test_result_shape_requires_persisted_pending_https_or_paid_null(): void
    {
        $pending = new CheckoutSelfPaymentIssuanceResult('pending', 'https://payments.example.test/persisted');
        $paid = new CheckoutSelfPaymentIssuanceResult('paid', null);
        $this->assertSame(['state', 'paymentUrl'], array_keys(get_object_vars($pending)));
        $this->assertSame('https://payments.example.test/persisted', $pending->paymentUrl);
        $this->assertNull($paid->paymentUrl);

        foreach ([
            ['pending', null], ['paid', 'https://payments.example.test/leak'],
            ['pending', 'http://payments.example.test/insecure'],
            ['pending', 'https://user:secret@payments.example.test/private'], ['reserved', null],
        ] as [$state, $url]) {
            try {
                new CheckoutSelfPaymentIssuanceResult($state, $url);
                $this->fail('Invalid browser-safe payment result was accepted.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_persisted_read_requires_an_existing_intent_and_never_creates_or_calls_provider(): void
    {
        $fixture = $this->established();
        $never = $this->createMock(PaymentProvider::class);
        $never->expects($this->never())->method('createInvoice');
        $never->expects($this->never())->method('lookupInvoice');
        app()->instance(PaymentProvider::class, $never);

        $this->assertUnavailable(fn () => $this->readPersisted($fixture, false));
        $this->assertDatabaseCount('assessment_bills', 0);
        $this->assertDatabaseCount('assessment_charges', 0);
        $this->assertDatabaseCount('assessment_bill_items', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public function test_persisted_read_returns_exact_database_url_and_isolates_other_organizations(): void
    {
        $fixture = $this->established();
        $prepared = $this->prepare($fixture, false);
        $this->markPending($prepared->billId, 'https://persisted.example.test/exact');
        $other = $this->established();

        $result = $this->readPersisted($fixture, false);
        $this->assertSame('pending', $result->state);
        $this->assertSame('https://persisted.example.test/exact', $result->paymentUrl);
        $this->assertUnavailable(fn () => $this->readPersisted($other, false));
    }

    #[DataProvider('invalidPersistedProjectionChanges')]
    public function test_persisted_read_rejects_stale_or_corrupt_state_without_leaking_url(string $change): void
    {
        $fixture = $this->established();
        $prepared = $this->prepare($fixture, false);
        $this->markPending($prepared->billId, 'https://private.example.test/do-not-leak');

        match ($change) {
            'session' => $this->changeAuthority('session', $fixture),
            'catalog-price' => DB::table('packages')->where('id', $fixture['package'])->update(['amount' => 101]),
            'catalog-name' => DB::table('packages')->where('id', $fixture['package'])->update(['name' => 'Changed']),
            'catalog-items' => DB::table('package_items')->insert([
                'package_id' => $fixture['package'], 'test_type' => 'papi', 'sort_order' => 3,
            ]),
            'policy-list' => $this->broadenPolicy($fixture),
            'policy-lock' => DB::table('integration_sources')->where('id', $fixture['source'])
                ->update(['locked_payer_type' => 'self']),
            'request-hash' => DB::table('assessment_bills')->where('id', $prepared->billId)
                ->update(['request_hash' => str_repeat('f', 64)]),
            'wrong-graph' => DB::table('assessment_bills')->where('id', $prepared->billId)
                ->update(['idempotency_key' => 'checkout-self-v1:'.Str::ulid()]),
            'insecure-url' => DB::table('assessment_bills')->where('id', $prepared->billId)
                ->update(['invoice_url' => 'http://private.example.test/leak']),
            'userinfo-url' => DB::table('assessment_bills')->where('id', $prepared->billId)
                ->update(['invoice_url' => 'https://user:secret@private.example.test/leak']),
            'unknown', 'expired', 'rejected' => DB::table('assessment_bills')->where('id', $prepared->billId)
                ->update(['status' => $change]),
            'consultation' => null,
            default => throw new RuntimeException('Unknown projection corruption.'),
        };

        $this->assertUnavailable(fn () => $this->readPersisted($fixture, $change === 'consultation'));
        $this->assertDatabaseCount('assessment_bills', 1);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.reserved')->count());
    }

    public static function invalidPersistedProjectionChanges(): iterable
    {
        foreach (['session', 'catalog-price', 'catalog-name', 'catalog-items', 'policy-list', 'policy-lock',
            'request-hash', 'wrong-graph', 'insecure-url', 'userinfo-url', 'unknown', 'expired', 'rejected',
            'consultation'] as $change) {
            yield $change => [$change];
        }
    }

    public function test_issue_returns_persisted_lookup_url_and_observes_paid_transition_before_final_read(): void
    {
        $fixture = $this->established();
        $provider = $this->createMock(PaymentProvider::class);
        $provider->expects($this->once())->method('createInvoice')
            ->willReturnCallback(fn (CreateInvoiceRequest $request): PaymentInvoice => new PaymentInvoice(
                'provider-race', 'https://provider-return.example.test/not-authority',
                $request->amount, $request->currency, $request->expiresAt,
            ));
        $provider->expects($this->once())->method('lookupInvoice')
            ->willReturnCallback(fn (string $reference, int $amount, string $currency): PaymentInvoice => new PaymentInvoice(
                'provider-race', 'https://persisted.example.test/authoritative', $amount, $currency, now()->addDay(),
            ));
        app()->instance(PaymentProvider::class, $provider);

        $promote = true;
        DB::listen(function (QueryExecuted $query) use (&$promote, $fixture): void {
            if (! $promote || ! str_contains(strtolower($query->sql), 'update "assessment_bills"')
                || ! in_array('pending', $query->bindings, true)) {
                return;
            }
            $promote = false;
            $bill = AssessmentBill::query()->where('organization_id', $fixture['organization'])->first();
            if (! $bill instanceof AssessmentBill || $bill->status !== 'pending') {
                return;
            }
            $at = now()->startOfSecond();
            $bill->update(['status' => 'paid', 'paid_at' => $at]);
            DB::table('assessment_bill_items')->where('bill_id', $bill->id)->update(['settled_at' => $at]);
            DB::table('audit_logs')->insert([
                'branch_id' => $fixture['organization'], 'actor_type' => 'system', 'actor_id' => 'payment-provider',
                'action' => 'assessment_bill.paid', 'subject_type' => AssessmentBill::class,
                'subject_id' => (string) $bill->id, 'context' => '{}', 'occurred_at' => $at,
                'expires_at' => $at->copy()->addYears(2),
            ]);
        });

        $result = $this->issue($fixture, false);
        $this->assertSame('paid', $result->state);
        $this->assertNull($result->paymentUrl);
        $this->assertDatabaseHas('assessment_bills', [
            'status' => 'paid', 'invoice_url' => 'https://persisted.example.test/authoritative',
        ]);
    }

    #[DataProvider('unknownProviderOutcomes')]
    public function test_unknown_provider_outcome_is_generic_and_retry_never_creates_again(string $outcome): void
    {
        $fixture = $this->established();
        $provider = $this->createMock(PaymentProvider::class);
        $provider->expects($this->once())->method('createInvoice')
            ->willReturnCallback(fn (CreateInvoiceRequest $request): PaymentInvoice => new PaymentInvoice(
                'provider-created', 'https://payments.example.test/created',
                $request->amount, $request->currency, $request->expiresAt,
            ));
        $lookup = $provider->expects($this->once())->method('lookupInvoice');
        if ($outcome === 'mismatch') {
            $lookup->willReturnCallback(fn (string $reference, int $amount, string $currency): PaymentInvoice => new PaymentInvoice(
                'provider-mismatch', 'https://payments.example.test/mismatch', $amount, $currency, now()->addDay(),
            ));
        } else {
            $lookup->willThrowException(new PaymentProviderException('synthetic lookup unknown'));
        }
        app()->instance(PaymentProvider::class, $provider);
        $this->assertUnavailable(fn () => $this->issue($fixture, false));
        $this->assertDatabaseHas('assessment_bills', ['status' => 'unknown']);
        $this->assertDatabaseHas('outbox_messages', [
            'status' => 'failed', 'attempts' => 1, 'last_error' => 'INVOICE_OUTCOME_UNKNOWN',
        ]);

        $never = $this->createMock(PaymentProvider::class);
        $never->expects($this->never())->method('createInvoice');
        $never->expects($this->never())->method('lookupInvoice');
        app()->instance(PaymentProvider::class, $never);
        $this->assertUnavailable(fn () => $this->issue($fixture, false));
        $this->assertDatabaseCount('assessment_bills', 1);
        $this->assertDatabaseCount('outbox_messages', 1);
    }

    public static function unknownProviderOutcomes(): iterable
    {
        yield ['mismatch'];
        yield ['lookup-error'];
    }

    public function test_consumed_permit_and_stale_session_fail_before_any_provider_call(): void
    {
        $consumed = $this->established();
        $claim = $this->coordinate($consumed, false);
        $this->assertNotNull(app(IssueAssessmentBillInvoice::class)->consume($claim->messageId));
        $never = $this->createMock(PaymentProvider::class);
        $never->expects($this->never())->method('createInvoice');
        $never->expects($this->never())->method('lookupInvoice');
        app()->instance(PaymentProvider::class, $never);
        $this->assertUnavailable(fn () => $this->issue($consumed, false));

        $stale = $this->established();
        app(CheckoutSessionLifecycle::class)->hydrateWithCsrfDelivery($this->credentials($stale));
        $this->changeAuthority('session', $stale);
        $this->assertUnavailable(fn () => $this->issue($stale, false));
        $this->assertSame(1, DB::table('assessment_bills')->count());
    }

    public function test_issuance_orchestrator_rejects_ambient_context_or_transaction(): void
    {
        $fixture = $this->established();
        foreach ([
            fn () => app(RlsContextRunner::class)->runAsService(fn () => $this->issue($fixture, false)),
            fn () => DB::transaction(fn () => $this->issue($fixture, false)),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Ambient issuance orchestration was accepted.');
            } catch (\LogicException $exception) {
                $this->assertSame('Checkout payment issuance owns an empty context and transaction.', $exception->getMessage());
            }
        }
        $this->assertDatabaseCount('assessment_bills', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    /** @param array<string, mixed> $fixture */
    private function prepare(array $fixture, bool $consultation): CheckoutSelfPaymentPreparation
    {
        return app(PrepareCheckoutSelfPayment::class)->execute($this->credentials($fixture), $consultation);
    }

    /** @param array<string, mixed> $fixture */
    private function coordinate(array $fixture, bool $consultation): CheckoutSelfPaymentClaimResult
    {
        return app(CoordinateCheckoutSelfPayment::class)->execute($this->credentials($fixture), $consultation);
    }

    /** @param array<string, mixed> $fixture */
    private function issue(array $fixture, bool $consultation): CheckoutSelfPaymentIssuanceResult
    {
        return app(IssueCheckoutSelfPayment::class)->execute($this->credentials($fixture), $consultation);
    }

    /** @param array<string, mixed> $fixture */
    private function readPersisted(array $fixture, bool $consultation): CheckoutSelfPaymentIssuanceResult
    {
        return app(PrepareCheckoutSelfPayment::class)->readPersisted($this->credentials($fixture), $consultation);
    }

    private function markPending(int $billId, string $url): void
    {
        DB::table('assessment_bills')->where('id', $billId)->update([
            'status' => 'pending', 'gateway_ref' => 'persisted-provider-ref',
            'invoice_url' => $url, 'expires_at' => now()->addDay(),
        ]);
    }

    /** @param array<string, mixed> $fixture */
    private function broadenPolicy(array $fixture): void
    {
        $allowed = json_encode(['self', 'organization'], JSON_THROW_ON_ERROR);
        DB::table('branches')->where('id', $fixture['organization'])->update(['allowed_payer_types' => $allowed]);
        DB::table('integration_sources')->where('id', $fixture['source'])->update(['allowed_payer_types' => $allowed]);
    }

    private function successfulProvider(): PaymentProvider
    {
        $provider = $this->createMock(PaymentProvider::class);
        $invoice = null;
        $provider->expects($this->once())->method('createInvoice')
            ->willReturnCallback(function (CreateInvoiceRequest $request) use (&$invoice): PaymentInvoice {
                return $invoice = new PaymentInvoice('provider-success', 'https://payments.example.test/success',
                    $request->amount, $request->currency, $request->expiresAt);
            });
        $provider->expects($this->once())->method('lookupInvoice')
            ->willReturnCallback(function () use (&$invoice): PaymentInvoice {
                return $invoice;
            });

        return $provider;
    }

    private function assertUnavailable(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Unavailable checkout payment was prepared.');
        } catch (DomainException $exception) {
            $this->assertSame('CHECKOUT_PAYMENT_UNAVAILABLE', $exception->getMessage());
        }
    }

    /** @param array<string, mixed> $fixture */
    private function credentials(array $fixture): CheckoutSessionMutationCredentials
    {
        return new CheckoutSessionMutationCredentials($fixture['selector'], $fixture['csrf']);
    }

    /** @param array<string, mixed> $fixture */
    private function changeAuthority(string $change, array $fixture): void
    {
        match ($change) {
            'session' => DB::table('checkout_sessions')->where('id', $fixture['session'])->update([
                'status' => 'REVOKED', 'active_marker' => null, 'revoked_at' => now(),
                'revocation_reason' => 'REPLACED', 'updated_at' => now(),
            ]),
            'recovery' => app(RlsContextRunner::class)->runAsService(fn () => app(IssueCheckoutHandoff::class)
                ->execute(new CheckoutHandoffIssueInput(IntegrationClient::query()->findOrFail($fixture['client']),
                    $fixture['attemptPublicId'], $fixture['sourceSystem'], 'ih1_'.bin2hex(random_bytes(16)),
                    CheckoutHandoffIntent::Recovery))),
            'policy' => DB::table('branches')->where('id', $fixture['organization'])->update([
                'allowed_payer_types' => json_encode(['organization'], JSON_THROW_ON_ERROR),
            ]),
            'client' => DB::table('integration_clients')->where('id', $fixture['client'])->update(['enabled' => false]),
            'source' => DB::table('integration_sources')->where('id', $fixture['source'])->update(['status' => 'INACTIVE']),
            'package' => DB::table('packages')->where('id', $fixture['package'])->update(['is_active' => false]),
            'participant' => DB::table('participants')->where('id', $fixture['participant'])->update(['deleted_at' => now()]),
            'revoked' => DB::table('assessment_participants')->where('id', $fixture['attempt'])->update([
                'assessment_status' => 'REVOKED', 'revoked_at' => now(),
            ]),
            'finalized' => DB::table('assessment_participants')->where('id', $fixture['attempt'])->update([
                'assessment_status' => 'COMPLETED', 'finalized_at' => now(),
            ]),
            default => throw new RuntimeException('Unknown synthetic authority change.'),
        };
    }

    private function corruptExisting(string $change, int $billId): void
    {
        $chargeId = (int) DB::table('assessment_bill_items')->where('bill_id', $billId)->value('charge_id');
        match ($change) {
            'bill-total' => DB::table('assessment_bills')->where('id', $billId)->update(['amount' => 101]),
            'request-hash' => DB::table('assessment_bills')->where('id', $billId)->update([
                'request_hash' => str_repeat('f', 64),
            ]),
            'item-state' => DB::table('assessment_bill_items')->where('bill_id', $billId)->update(['settled_at' => now()]),
            'snapshot' => DB::table('assessment_charges')->where('id', $chargeId)->update([
                'price_snapshot' => json_encode(['version' => 1], JSON_THROW_ON_ERROR),
            ]),
            'method' => DB::table('payment_methods')->where('id', $this->method)->update(['code' => 'manual_transfer']),
            default => throw new RuntimeException('Unknown synthetic corruption.'),
        };
    }

    /** @return array<string, int|string> */
    private function established(string $funding = 'COMMERCIAL_SELF_PAY', int $amount = 100): array
    {
        $key = (string) Str::ulid();
        $sourceSystem = 'PAYPREP_'.$key;
        $packageCode = 'PP'.$key;
        $payer = $funding === 'COMMERCIAL_SELF_PAY' ? 'self' : 'organization';
        $organization = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => 'Synthetic', 'organization_code' => $key,
            'display_name' => 'Synthetic', 'status' => 'ACTIVE', 'is_active' => true,
            'allowed_payer_types' => json_encode([$payer], JSON_THROW_ON_ERROR),
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $organization, 'referral_branch_id' => $organization,
            'referral_source' => 'manual', 'source_system' => $sourceSystem,
            'full_name' => 'Synthetic Person', 'phone' => '620000000000',
        ]);
        $client = DB::table('integration_clients')->insertGetId([
            'organization_id' => $organization, 'client_id' => $key,
            'credential_reference' => 'synthetic-only', 'enabled' => true,
        ]);
        $source = DB::table('integration_sources')->insertGetId([
            'integration_client_id' => $client, 'source_system' => $sourceSystem,
            'contract_version' => 'checkout-v2',
            'allowed_assessment_packages' => json_encode([$packageCode], JSON_THROW_ON_ERROR),
            'allowed_funding_modes' => '[]', 'allowed_payer_types' => json_encode([$payer], JSON_THROW_ON_ERROR),
            'status' => 'ACTIVE',
        ]);
        $package = DB::table('packages')->insertGetId([
            'code' => $packageCode, 'name' => 'Synthetic', 'amount' => $amount,
            'consultation_amount' => 30, 'currency' => 'IDR', 'is_active' => true,
        ]);
        DB::table('package_items')->insert([
            ['package_id' => $package, 'test_type' => 'ist', 'sort_order' => 1],
            ['package_id' => $package, 'test_type' => 'dass21', 'sort_order' => 2],
        ]);
        $attemptPublicId = (string) Str::ulid();
        $case = AssessmentBillingFixture::createExactIntegratedCase(
            $participant, $organization, $package, $attemptPublicId,
        );
        $attempt = DB::table('assessment_participants')->insertGetId([
            'organization_id' => $organization, 'integration_client_id' => $client,
            'participant_id' => $participant, 'package_id' => $package,
            'assessment_case_id' => $case, 'assessment_attempt_id' => $attemptPublicId, 'source_system' => $sourceSystem,
            'external_candidate_id' => $key, 'funding_mode' => $funding,
            'assessment_status' => 'PROVISIONED', 'idempotency_key' => $key,
            'request_hash' => hash('sha256', $key), 'logical_assessment_key' => hash('sha256', 'logical'.$key),
            'metadata' => json_encode([
                'checkout_contract_version' => 'checkout-v2', 'checkout_initial_funding_mode' => $funding,
            ], JSON_THROW_ON_ERROR),
        ]);
        $issued = app(RlsContextRunner::class)->run(new RlsContext('service'), fn () => app(IssueCheckoutHandoff::class)
            ->execute(new CheckoutHandoffIssueInput(IntegrationClient::query()->findOrFail($client),
                $attemptPublicId, $sourceSystem, 'ih1_'.bin2hex(random_bytes(16)), CheckoutHandoffIntent::Issue)));
        $raw = $issued->rawToken();
        if (! is_string($raw)) {
            $this->fail('Synthetic handoff unavailable.');
        }
        $result = app(EstablishCheckoutSession::class)->execute(new CheckoutSessionExchangeInput($raw));
        $session = CheckoutSession::query()->where('public_id', $result->sessionPublicId)->value('id');
        if (! is_int($session)) {
            $this->fail('Synthetic session unavailable.');
        }

        return compact('organization', 'participant', 'client', 'source', 'package', 'attempt',
            'attemptPublicId', 'sourceSystem', 'session') + [
                'selector' => $result->rawSelector(), 'csrf' => $result->rawCsrfToken(),
            ];
    }
}
