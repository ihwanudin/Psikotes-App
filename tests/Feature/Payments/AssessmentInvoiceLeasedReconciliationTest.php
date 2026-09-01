<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Actions\Payments\ClaimAssessmentBillInvoice;
use App\Actions\Payments\IssueAssessmentBillInvoice;
use App\Actions\Payments\PersistAssessmentInvoiceOutcome;
use App\Actions\Payments\PreviewAssessmentBill;
use App\Actions\Payments\ReconcileAssessmentBillInvoice;
use App\Actions\Payments\ReserveAssessmentBill;
use App\Actions\Payments\ReserveAssessmentInvoiceReconciliationHints;
use App\Actions\Payments\ValidateAssessmentInvoiceReconciliationLease;
use App\Contracts\PaymentProvider;
use App\Data\Payments\AssessmentInvoiceReconciliationPermit;
use App\Data\Payments\PaymentInvoice;
use App\Enums\AdminRole;
use App\Enums\PayerType;
use App\Models\Admin;
use App\Models\AssessmentBill;
use App\Models\OutboxMessage;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Payments\Exceptions\PaymentProviderException;
use App\Services\Payments\XenditProvider;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentPreviewFixture as Fixture;

final class AssessmentInvoiceLeasedReconciliationTest extends OrganizationPaymentTestCase
{
    use DatabaseTruncation;

    /** @var array{organization: int, participant: int, package: int, attempt: int, charge: int, bill: int, payer: string, source: int, client: int} */
    private array $fixture;

    private AssessmentBill $bill;

    private OutboxMessage $intent;

    private int $method;

    private bool $expectedHttp = false;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::setUp();
        Date::setTestNow('2026-09-01T00:00:00Z');
        Http::preventStrayRequests();
        Http::fake([]);
        Bus::fake();
        Queue::fake();
        config()->set('assessment_integration.checkout.enabled', true);
        config()->set('assessment_billing.invoice_reconciliation_batch_size', 25);
        config()->set('assessment_billing.invoice_reconciliation_scan_limit', 100);
        config()->set('assessment_billing.invoice_reconciliation_lease_seconds', 60);
        config()->set('assessment_billing.invoice_reconciliation_cooldown_seconds', 300);
        config()->set('assessment_billing.invoice_reconciliation_max_lookups', 12);
        $this->fixture = $this->createFixture();
        DB::table('assessment_participants')->where('id', $this->fixture['attempt'])->update([
            'funding_mode' => 'INVOICED_TO_ORGANIZATION',
            'metadata' => '{"checkout_contract_version":"checkout-v2","checkout_initial_funding_mode":null}',
        ]);
        $admin = Admin::create(['branch_id' => $this->fixture['organization'], 'name' => 'Synthetic leased reconciler',
            'email' => 'leased-reconciler@example.test', 'password' => 'synthetic', 'role' => AdminRole::BranchAdmin]);
        $this->method = DB::table('payment_methods')->insertGetId([
            'code' => 'xendit', 'display_name' => 'Synthetic', 'is_active' => true,
        ]);
        app(RlsContextRunner::class)->runAsService(function () use ($admin): void {
            $selection = [['assessmentParticipantId' => $this->fixture['attempt'], 'consultationRequested' => false]];
            $preview = app(PreviewAssessmentBill::class)->execute($this->fixture['organization'], $selection, PayerType::Organization);
            $this->bill = app(ReserveAssessmentBill::class)->execute(
                $admin, $selection, $this->method, $preview['selectionHash'], 'leased-reconcile-one',
            );
            app(ClaimAssessmentBillInvoice::class)->execute($this->fixture['organization'], $this->bill->id);
            $this->intent = OutboxMessage::query()->where('aggregate_id', (string) $this->bill->id)->sole();
        });
        $this->assertNotNull(app(IssueAssessmentBillInvoice::class)->consume($this->intent->message_id));
    }

    protected function tearDown(): void
    {
        try {
            if (! $this->expectedHttp) {
                Http::assertNothingSent();
            }
            Bus::assertNothingDispatched();
            Queue::assertNothingPushed();
            $this->assertNull(app(RlsContextRunner::class)->current());
            $this->assertSame(0, DB::transactionLevel());
        } finally {
            RefreshDatabaseState::$migrated = false;
            parent::tearDown();
        }
    }

    #[DataProvider('canonicalStates')]
    public function test_exact_lookup_persists_once_and_clears_lease_from_both_states(string $state): void
    {
        $permit = $this->permit($state);
        $invoice = $this->invoice(Date::now()->subHour());

        $result = $this->executeLeased($permit, $this->exactProvider($invoice));

        $this->assertSame('issued', $result['decision']);
        $this->assertSame('pending', $this->bill->fresh()->status);
        $this->assertSame($invoice->providerReference, $this->bill->fresh()->gateway_ref);
        $this->assertTrue($this->bill->fresh()->expires_at->equalTo($invoice->expiresAt));
        $message = $this->intent->fresh();
        $this->assertSame('processed', $message->status);
        $this->assertNull($message->reconciliation_lease_token);
        $this->assertNull($message->reconciliation_lease_expires_at);
        $this->assertNull($message->reconciliation_next_at);
        $this->assertSame($permit->lookupGeneration, $message->reconciliation_lookup_attempts);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.invoice_issued')->count());
    }

    /** @return iterable<string, array{string}> */
    public static function canonicalStates(): iterable
    {
        yield 'issuing processing' => ['processing'];
        yield 'unknown failed' => ['unknown'];
    }

    #[DataProvider('unknownOutcomes')]
    public function test_unknown_outcomes_set_cooldown_once_without_counter_or_audit_spam(string $state, string $outcome): void
    {
        $permit = $this->permit('processing');
        if ($state === 'unknown') {
            $this->unknownState();
        }
        $audit = DB::table('audit_logs')->where('action', 'assessment_bill.invoice_unknown')->count();

        $result = $this->executeLeased($permit, $this->unknownProvider($outcome));

        $this->assertSame('unknown', $result['decision']);
        $message = $this->intent->fresh();
        $this->assertSame('unknown', $this->bill->fresh()->status);
        $this->assertSame('failed', $message->status);
        $this->assertSame('INVOICE_OUTCOME_UNKNOWN', $message->last_error);
        $this->assertNull($message->reconciliation_lease_token);
        $this->assertNull($message->reconciliation_lease_expires_at);
        $this->assertNotNull($message->reconciliation_next_at);
        $databaseNow = CarbonImmutable::parse((string) ((array) DB::selectOne(
            'SELECT CURRENT_TIMESTAMP AS reconciliation_now',
        ))['reconciliation_now'])->utc()->startOfSecond();
        $cooldown = $databaseNow->diffInSeconds($message->reconciliation_next_at, false);
        $this->assertGreaterThanOrEqual(299, $cooldown);
        $this->assertLessThanOrEqual(300, $cooldown);
        $this->assertSame($permit->lookupGeneration, $message->reconciliation_lookup_attempts);
        $expectedAudit = $state === 'processing' ? $audit + 1 : $audit;
        $this->assertSame($expectedAudit,
            DB::table('audit_logs')->where('action', 'assessment_bill.invoice_unknown')->count());
        $attributes = $message->getAttributes();
        $this->assertSame('recovery_required', $this->executeLeased($permit, $this->neverProvider())['decision']);
        $this->assertSame($attributes, $this->intent->fresh()->getAttributes());
        $this->assertSame($expectedAudit,
            DB::table('audit_logs')->where('action', 'assessment_bill.invoice_unknown')->count());
    }

    /** @return iterable<string, array{string, string}> */
    public static function unknownOutcomes(): iterable
    {
        yield 'issuing empty/error' => ['processing', 'error'];
        yield 'issuing mismatch' => ['processing', 'mismatch'];
        yield 'unknown existing stable' => ['unknown', 'error'];
    }

    public function test_real_xendit_leased_path_is_get_only_and_never_posts(): void
    {
        $permit = $this->permit('processing');
        $this->expectedHttp = true;
        config()->set('services.xendit', ['secret_key' => 'synthetic-leased-secret',
            'base_url' => 'https://api.xendit.co', 'connect_timeout_seconds' => 3, 'timeout_seconds' => 10]);
        Http::fake(['https://api.xendit.co/v2/invoices*' => Http::response([[
            'id' => 'leased-invoice', 'external_id' => $this->bill->public_reference, 'amount' => 100,
            'currency' => 'IDR', 'status' => 'PENDING', 'invoice_url' => 'https://invoice.xendit.co/leased-invoice',
            'expiry_date' => '2026-09-02T00:00:00Z',
        ]])]);

        $this->assertSame('issued', $this->executeLeased($permit, new XenditProvider)['decision']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), 'external_id='.urlencode($this->bill->public_reference)));
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    #[DataProvider('stalePreflightCases')]
    public function test_stale_preflight_skips_provider_without_mutation(string $case): void
    {
        $permit = $this->permit('processing');
        match ($case) {
            'token' => DB::table('outbox_messages')->where('id', $this->intent->id)
                ->update(['reconciliation_lease_token' => (string) Str::uuid()]),
            'generation' => DB::table('outbox_messages')->where('id', $this->intent->id)
                ->update(['reconciliation_lookup_attempts' => $permit->lookupGeneration + 1]),
            'expiry' => DB::table('outbox_messages')->where('id', $this->intent->id)
                ->update(['reconciliation_lease_expires_at' => Date::now()->subMinute()]),
            'digest' => $this->corruptPayload(),
            default => throw new RuntimeException('Unknown stale preflight case.'),
        };
        $before = $this->intent->fresh()->getAttributes();

        $this->assertSame('recovery_required', $this->executeLeased($permit, $this->neverProvider())['decision']);
        $this->assertSame($before, $this->intent->fresh()->getAttributes());
        $this->assertSame(0, DB::table('audit_logs')->whereIn('action', [
            'assessment_bill.invoice_unknown', 'assessment_bill.invoice_issued',
        ])->count());
    }

    /** @return iterable<string, array{string}> */
    public static function stalePreflightCases(): iterable
    {
        yield 'token stolen' => ['token'];
        yield 'generation changed' => ['generation'];
        yield 'lease expired' => ['expiry'];
        yield 'payload digest changed' => ['digest'];
    }

    public function test_invalid_token_and_generation_are_rejected_before_provider(): void
    {
        $permit = $this->permit('processing');
        foreach ([
            new AssessmentInvoiceReconciliationPermit(
                $permit->invoice, 'not-a-uuid', $permit->lookupGeneration, $permit->leaseExpiresAt,
            ),
            new AssessmentInvoiceReconciliationPermit(
                $permit->invoice, $permit->leaseToken, 0, $permit->leaseExpiresAt,
            ),
        ] as $invalid) {
            $this->assertSame('recovery_required', $this->executeLeased($invalid, $this->neverProvider())['decision']);
        }
    }

    #[DataProvider('lateFenceCases')]
    public function test_late_leased_response_is_discarded_without_deleting_new_owner_or_terminal_state(string $case): void
    {
        $permit = $this->permit('processing');
        $newOwner = (string) Str::uuid();
        $provider = $this->createMock(PaymentProvider::class);
        $provider->expects($this->never())->method('createInvoice');
        $provider->expects($this->once())->method('lookupInvoice')->willReturnCallback(function () use ($case, $newOwner): PaymentInvoice {
            if ($case === 'stolen') {
                DB::table('outbox_messages')->where('id', $this->intent->id)
                    ->update(['reconciliation_lease_token' => $newOwner]);
            } else {
                $this->bill->update(['status' => 'paid', 'paid_at' => now()]);
            }

            return $this->invoice();
        });

        $this->assertSame('recovery_required', $this->executeLeased($permit, $provider)['decision']);
        if ($case === 'stolen') {
            $this->assertSame($newOwner, $this->intent->fresh()->reconciliation_lease_token);
        } else {
            $this->assertSame('paid', $this->bill->fresh()->status);
        }
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'assessment_bill.invoice_issued')->count());
    }

    /** @return iterable<string, array{string}> */
    public static function lateFenceCases(): iterable
    {
        yield 'token stolen after GET' => ['stolen'];
        yield 'bill paid after GET' => ['paid'];
    }

    public function test_original_issuance_exact_wins_race_clears_lease_and_fences_old_response(): void
    {
        $permit = $this->permit('processing');
        $invoice = $this->invoice();
        $provider = $this->createMock(PaymentProvider::class);
        $provider->expects($this->never())->method('createInvoice');
        $provider->expects($this->once())->method('lookupInvoice')->willReturnCallback(
            function () use ($permit, $invoice): PaymentInvoice {
                $this->assertSame('issued', app(PersistAssessmentInvoiceOutcome::class)
                    ->execute($permit->invoice, $invoice)['decision']);

                return $invoice;
            },
        );

        $this->assertSame('recovery_required', $this->executeLeased($permit, $provider)['decision']);
        $message = $this->intent->fresh();
        $this->assertSame('processed', $message->status);
        $this->assertNull($message->reconciliation_lease_token);
        $this->assertNull($message->reconciliation_lease_expires_at);
        $this->assertNull($message->reconciliation_next_at);
        $this->assertSame($permit->lookupGeneration, $message->reconciliation_lookup_attempts);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.invoice_issued')->count());
    }

    public function test_original_issuance_unknown_preserves_active_canonical_lease(): void
    {
        $permit = $this->permit('processing');

        $this->assertSame('unknown', app(PersistAssessmentInvoiceOutcome::class)
            ->execute($permit->invoice, null)['decision']);
        $message = $this->intent->fresh();
        $this->assertSame($permit->leaseToken, $message->reconciliation_lease_token);
        $this->assertTrue($permit->leaseExpiresAt->equalTo($message->reconciliation_lease_expires_at));
        $this->assertNull($message->reconciliation_next_at);
        $this->assertSame($permit->lookupGeneration, $message->reconciliation_lookup_attempts);
    }

    public function test_audit_failure_rolls_back_outcome_cooldown_and_lease_clear(): void
    {
        $permit = $this->permit('processing');
        $beforeBill = $this->bill->fresh()->getAttributes();
        $beforeMessage = $this->intent->fresh()->getAttributes();
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed): void {
            if ($armed && str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'audit_logs')) {
                $armed = false;
                throw new RuntimeException('synthetic-leased-audit-failure');
            }
        });
        try {
            $this->executeLeased($permit, $this->exactProvider($this->invoice()));
            $this->fail('Audit failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic-leased-audit-failure', $exception->getMessage());
        }
        $this->assertFalse($armed);
        $this->assertSame($beforeBill, $this->bill->fresh()->getAttributes());
        $this->assertSame($beforeMessage, $this->intent->fresh()->getAttributes());
    }

    public function test_rejects_ambient_context_and_outer_transaction_before_provider(): void
    {
        $permit = $this->permit('processing');
        $action = app(ReconcileAssessmentBillInvoice::class);
        foreach ([new RlsContext('service'), new RlsContext('participant', 1, 1)] as $context) {
            try {
                app(RlsContextRunner::class)->run($context, fn () => $action->executeLeased($permit));
                $this->fail('Ambient RLS context must fail.');
            } catch (LogicException $exception) {
                $this->assertSame('Invoice reconciliation requires an empty RLS context and no ambient transaction.', $exception->getMessage());
            }
        }
        try {
            DB::transaction(fn () => $action->executeLeased($permit));
            $this->fail('Outer transaction must fail.');
        } catch (LogicException $exception) {
            $this->assertSame('Invoice reconciliation requires an empty RLS context and no ambient transaction.', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    private function permit(string $state): AssessmentInvoiceReconciliationPermit
    {
        if ($state === 'unknown') {
            $this->unknownState();
        }
        $leases = app(ReserveAssessmentInvoiceReconciliationHints::class)->execute(1, 1);
        $this->assertCount(1, $leases);
        $permit = app(ValidateAssessmentInvoiceReconciliationLease::class)->execute($leases[0]);
        $this->assertNotNull($permit);

        return $permit;
    }

    /** @return array{decision: string, messageId: string} */
    private function executeLeased(AssessmentInvoiceReconciliationPermit $permit, PaymentProvider $provider): array
    {
        app()->instance(PaymentProvider::class, $provider);

        return app(ReconcileAssessmentBillInvoice::class)->executeLeased($permit);
    }

    private function exactProvider(PaymentInvoice $invoice): PaymentProvider&MockObject
    {
        $provider = $this->createMock(PaymentProvider::class);
        $provider->expects($this->never())->method('createInvoice');
        $provider->expects($this->once())->method('lookupInvoice')
            ->with($this->bill->public_reference, 100, 'IDR')->willReturn($invoice);

        return $provider;
    }

    private function unknownProvider(string $outcome): PaymentProvider&MockObject
    {
        $provider = $this->createMock(PaymentProvider::class);
        $provider->expects($this->never())->method('createInvoice');
        $lookup = $provider->expects($this->once())->method('lookupInvoice');
        if ($outcome === 'mismatch') {
            $lookup->willReturn(new PaymentInvoice(
                'mismatch', 'https://invoice.xendit.co/mismatch', 101, 'IDR', Date::now()->addDay(),
            ));
        } else {
            $lookup->willThrowException(new PaymentProviderException('Synthetic lookup unknown.'));
        }

        return $provider;
    }

    private function neverProvider(): PaymentProvider&MockObject
    {
        $provider = $this->createMock(PaymentProvider::class);
        $provider->expects($this->never())->method('createInvoice');
        $provider->expects($this->never())->method('lookupInvoice');

        return $provider;
    }

    private function invoice(?\DateTimeInterface $expiry = null): PaymentInvoice
    {
        return new PaymentInvoice(
            'leased-invoice-123', 'https://invoice.xendit.co/leased-invoice-123', 100, 'IDR',
            $expiry === null ? Date::now()->addDay() : CarbonImmutable::instance($expiry),
        );
    }

    private function unknownState(): void
    {
        $this->bill->update(['status' => 'unknown']);
        $this->intent->forceFill(['status' => 'failed', 'last_error' => 'INVOICE_OUTCOME_UNKNOWN'])->save();
    }

    private function corruptPayload(): void
    {
        $payload = $this->intent->payload;
        $payload['snapshot']['amount']++;
        $this->intent->forceFill(['payload' => $payload])->save();
    }

    /** @return array{organization: int, participant: int, package: int, attempt: int, charge: int, bill: int, payer: string, source: int, client: int} */
    private function createFixture(): array
    {
        $fixture = Fixture::create();
        foreach (['organization', 'participant', 'package', 'attempt', 'charge', 'bill', 'source', 'client'] as $key) {
            if (! is_int($fixture[$key] ?? null)) {
                throw new RuntimeException('Synthetic billing fixture is invalid.');
            }
        }
        if (! is_string($fixture['payer'] ?? null)) {
            throw new RuntimeException('Synthetic billing fixture payer is invalid.');
        }

        return ['organization' => $fixture['organization'], 'participant' => $fixture['participant'],
            'package' => $fixture['package'], 'attempt' => $fixture['attempt'], 'charge' => $fixture['charge'],
            'bill' => $fixture['bill'], 'payer' => $fixture['payer'], 'source' => $fixture['source'],
            'client' => $fixture['client']];
    }
}
