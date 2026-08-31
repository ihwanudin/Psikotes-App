<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Actions\Payments\ClaimAssessmentBillInvoice;
use App\Actions\Payments\IssueAssessmentBillInvoice;
use App\Actions\Payments\PreviewAssessmentBill;
use App\Actions\Payments\ReconcileAssessmentBillInvoice;
use App\Actions\Payments\ReserveAssessmentBill;
use App\Contracts\PaymentProvider;
use App\Data\Payments\PaymentInvoice;
use App\Enums\AdminRole;
use App\Enums\PayerType;
use App\Models\Admin;
use App\Models\AssessmentBill;
use App\Models\OutboxMessage;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Integrations\DispatchIntegrationOutbox;
use App\Services\Notifications\DispatchNotificationOutbox;
use App\Services\Payments\Exceptions\PaymentProviderException;
use App\Services\Payments\XenditProvider;
use DomainException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentPreviewFixture as Fixture;

final class AssessmentBillInvoiceReconciliationTest extends OrganizationPaymentTestCase
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
        $this->fixture = $this->createFixture();
        DB::table('assessment_participants')->where('id', $this->fixture['attempt'])->update([
            'funding_mode' => 'INVOICED_TO_ORGANIZATION',
            'metadata' => '{"checkout_contract_version":"checkout-v2","checkout_initial_funding_mode":null}',
        ]);
        $admin = Admin::create(['branch_id' => $this->fixture['organization'], 'name' => 'Synthetic reconciler',
            'email' => 'reconciler@example.test', 'password' => 'synthetic', 'role' => AdminRole::BranchAdmin]);
        $this->method = DB::table('payment_methods')->insertGetId(['code' => 'xendit', 'display_name' => 'Synthetic', 'is_active' => true]);
        app(RlsContextRunner::class)->runAsService(function () use ($admin): void {
            $selection = [['assessmentParticipantId' => $this->fixture['attempt'], 'consultationRequested' => false]];
            $preview = app(PreviewAssessmentBill::class)->execute($this->fixture['organization'], $selection, PayerType::Organization);
            $this->bill = app(ReserveAssessmentBill::class)->execute($admin, $selection, $this->method, $preview['selectionHash'], 'reconcile-one');
            app(ClaimAssessmentBillInvoice::class)->execute($this->fixture['organization'], $this->bill->id);
            $this->intent = OutboxMessage::query()->where('aggregate_id', (string) $this->bill->id)->sole();
        });
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

    #[DataProvider('recoverableStates')]
    public function test_exact_lookup_attaches_invoice_from_either_recoverable_state(string $state): void
    {
        $this->crashState();
        if ($state === 'unknown') {
            $this->unknownState();
        }
        $this->assertSame('issued', $this->reconcile($this->exactProvider())['decision']);
        $this->assertIssued();
    }

    /** @return iterable<string, array{string}> */
    public static function recoverableStates(): iterable
    {
        yield 'issuing processing' => ['issuing'];
        yield 'unknown failed' => ['unknown'];
    }

    #[DataProvider('unknownOutcomes')]
    public function test_unknown_lookup_moves_issuing_once_and_keeps_unknown_stable(string $outcome): void
    {
        $this->crashState();
        $provider = $this->lookupProvider($outcome);
        $this->assertSame('unknown', $this->reconcile($provider)['decision']);
        $firstBill = $this->bill->fresh()->getAttributes();
        $firstMessage = $this->intent->fresh()->getAttributes();
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.invoice_unknown')->count());

        $this->assertSame('unknown', $this->reconcile($provider)['decision']);
        $this->assertSame($firstBill, $this->bill->fresh()->getAttributes());
        $this->assertSame($firstMessage, $this->intent->fresh()->getAttributes());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.invoice_unknown')->count());
    }

    /** @return iterable<string, array{string}> */
    public static function unknownOutcomes(): iterable
    {
        yield 'empty' => ['empty'];
        yield 'timeout/error' => ['error'];
        yield 'mismatch' => ['mismatch'];
    }

    public function test_real_xendit_recovery_is_get_only_and_never_posts(): void
    {
        $this->crashState();
        $this->expectedHttp = true;
        config()->set('services.xendit', ['secret_key' => 'synthetic-reconciliation-secret',
            'base_url' => 'https://api.xendit.co', 'connect_timeout_seconds' => 3, 'timeout_seconds' => 10]);
        Http::fake(['https://api.xendit.co/v2/invoices*' => Http::response([[
            'id' => 'invoice-123', 'external_id' => $this->bill->public_reference, 'amount' => 100,
            'currency' => 'IDR', 'status' => 'PENDING', 'invoice_url' => 'https://invoice.xendit.co/invoice-123',
            'expiry_date' => '2026-09-02T00:00:00Z',
        ]])]);
        $this->assertSame('issued', $this->reconcile(new XenditProvider)['decision']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), 'external_id='.urlencode($this->bill->public_reference)));
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_exact_past_provider_expiry_is_attached_without_extension(): void
    {
        $this->crashState();
        $expired = new PaymentInvoice('invoice-expired', 'https://invoice.xendit.co/invoice-expired', 100, 'IDR',
            Date::now()->subHour());
        $provider = $this->createMock(PaymentProvider::class);
        $provider->expects($this->never())->method('createInvoice');
        $provider->expects($this->once())->method('lookupInvoice')->willReturn($expired);

        $this->assertSame('issued', $this->reconcile($provider)['decision']);
        $this->assertSame('invoice-expired', $this->bill->fresh()->gateway_ref);
        $this->assertTrue($this->bill->fresh()->expires_at->equalTo($expired->expiresAt));
    }

    #[DataProvider('invalidStates')]
    public function test_invalid_or_changed_state_fails_before_provider(string $case): void
    {
        if (! in_array($case, ['pending', 'missing'], true)) {
            $this->crashState();
        }
        match ($case) {
            'pending' => null,
            'processed' => $this->intent->forceFill(['status' => 'processed', 'processed_at' => now()])->save(),
            'paid' => $this->bill->update(['status' => 'paid', 'paid_at' => now()]),
            'expired' => $this->bill->update(['status' => 'expired']),
            'rejected' => $this->bill->update(['status' => 'rejected']),
            'policy-off' => DB::table('branches')->where('id', $this->bill->organization_id)->update(['allowed_payer_types' => '["self"]']),
            'channel-off' => DB::table('payment_methods')->where('id', $this->method)->update(['is_active' => false]),
            'revoked' => DB::table('assessment_participants')->where('id', $this->fixture['attempt'])->update(['revoked_at' => now()]),
            'corrupt' => $this->corruptPayload(),
            'foreign' => $this->foreignPayload(),
            'missing' => $this->intent->delete(),
            default => throw new LogicException('Unknown synthetic case.'),
        };

        app()->instance(PaymentProvider::class, $this->neverProvider());
        $this->expectException(DomainException::class);
        app(ReconcileAssessmentBillInvoice::class)->execute($this->intent->message_id);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidStates(): iterable
    {
        foreach (['pending', 'processed', 'paid', 'expired', 'rejected', 'policy-off', 'channel-off', 'revoked', 'corrupt', 'foreign', 'missing'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('ambientContexts')]
    public function test_ambient_context_or_transaction_is_rejected_before_provider(?string $role): void
    {
        $this->crashState();
        app()->instance(PaymentProvider::class, $this->neverProvider());
        $call = fn () => app(ReconcileAssessmentBillInvoice::class)->execute($this->intent->message_id);
        $this->expectException(LogicException::class);
        $role === null
            ? DB::transaction($call)
            : app(RlsContextRunner::class)->run(new RlsContext($role, $this->bill->organization_id, $this->fixture['participant']), $call);
    }

    /** @return iterable<int, array{string|null}> */
    public static function ambientContexts(): iterable
    {
        foreach ([null, 'service', 'participant', 'branch_admin', 'super_admin'] as $role) {
            yield [$role];
        }
    }

    public function test_late_paid_state_is_not_overwritten(): void
    {
        $this->crashState();
        $provider = $this->createMock(PaymentProvider::class);
        $provider->expects($this->never())->method('createInvoice');
        $provider->expects($this->once())->method('lookupInvoice')->willReturnCallback(function (): PaymentInvoice {
            $this->bill->update(['status' => 'paid', 'paid_at' => now()]);

            return $this->invoice();
        });
        $this->assertSame('recovery_required', $this->reconcile($provider)['decision']);
        $this->assertSame('paid', $this->bill->fresh()->status);
        $this->assertNull($this->bill->fresh()->gateway_ref);
        $this->assertSame('processing', $this->intent->fresh()->status);
    }

    public function test_persistence_failure_rolls_back_invoice_and_audit(): void
    {
        $this->crashState();
        DB::listen(function (QueryExecuted $query): void {
            if (str_contains($query->sql, 'insert into "audit_logs"')
                && in_array('assessment_bill.invoice_issued', $query->bindings, true)) {
                throw new RuntimeException('synthetic audit failure');
            }
        });
        try {
            $this->reconcile($this->exactProvider());
            $this->fail('Persistence failure must escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic audit failure', $exception->getMessage());
        }
        $this->assertSame('issuing', $this->bill->fresh()->status);
        $this->assertNull($this->bill->fresh()->gateway_ref);
        $this->assertSame('processing', $this->intent->fresh()->status);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'assessment_bill.invoice_issued')->count());
    }

    public function test_recovery_never_settles_or_dispatches_legacy_consumers(): void
    {
        $this->crashState();
        $this->reconcile($this->exactProvider());
        $this->assertSame(0, DB::table('assessment_bill_items')->where('bill_id', $this->bill->id)->whereNotNull('settled_at')->count());
        $this->assertSame(0, DB::table('assessment_entitlements')->where('organization_id', $this->bill->organization_id)->count());
        $this->assertSame(0, app(DispatchNotificationOutbox::class)->handle());
        $this->assertSame(0, app(DispatchIntegrationOutbox::class)->handle());
    }

    private function crashState(): void
    {
        $this->assertNotNull(app(IssueAssessmentBillInvoice::class)->consume($this->intent->message_id));
    }

    private function unknownState(): void
    {
        $this->bill->update(['status' => 'unknown']);
        $this->intent->forceFill(['status' => 'failed', 'last_error' => 'INVOICE_OUTCOME_UNKNOWN'])->save();
    }

    /** @return array{decision: string, messageId: string} */
    private function reconcile(PaymentProvider $provider): array
    {
        app()->instance(PaymentProvider::class, $provider);

        return app(ReconcileAssessmentBillInvoice::class)->execute($this->intent->message_id);
    }

    private function exactProvider(): PaymentProvider&MockObject
    {
        $provider = $this->createMock(PaymentProvider::class);
        $provider->expects($this->never())->method('createInvoice');
        $provider->expects($this->once())->method('lookupInvoice')
            ->with($this->bill->public_reference, 100, 'IDR')->willReturn($this->invoice());

        return $provider;
    }

    private function lookupProvider(string $outcome): PaymentProvider&MockObject
    {
        $provider = $this->createMock(PaymentProvider::class);
        $provider->expects($this->never())->method('createInvoice');
        $lookup = $provider->expects($this->exactly(2))->method('lookupInvoice');
        match ($outcome) {
            'mismatch' => $lookup->willReturn(new PaymentInvoice('invoice-123', 'https://invoice.xendit.co/invoice-123', 101, 'IDR', Date::now()->addDay())),
            default => $lookup->willThrowException(new PaymentProviderException('Synthetic unknown lookup.')),
        };

        return $provider;
    }

    private function neverProvider(): PaymentProvider&MockObject
    {
        $provider = $this->createMock(PaymentProvider::class);
        $provider->expects($this->never())->method('createInvoice');
        $provider->expects($this->never())->method('lookupInvoice');

        return $provider;
    }

    private function invoice(): PaymentInvoice
    {
        return new PaymentInvoice('invoice-123', 'https://invoice.xendit.co/invoice-123', 100, 'IDR', Date::now()->addDay());
    }

    private function assertIssued(): void
    {
        $this->assertSame('pending', $this->bill->fresh()->status);
        $this->assertSame('invoice-123', $this->bill->fresh()->gateway_ref);
        $this->assertSame('processed', $this->intent->fresh()->status);
        $this->assertSame(1, $this->intent->fresh()->attempts);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.invoice_issued')->count());
    }

    private function corruptPayload(): void
    {
        $payload = $this->intent->payload;
        $payload['snapshot']['amount']++;
        $this->intent->forceFill(['payload' => $payload])->save();
    }

    private function foreignPayload(): void
    {
        $payload = $this->intent->payload;
        $payload['snapshot']['organizationId']++;
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
