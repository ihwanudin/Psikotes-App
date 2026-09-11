<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Actions\Payments\ClaimAssessmentBillInvoice;
use App\Actions\Payments\IssueAssessmentBillInvoice;
use App\Actions\Payments\PreviewAssessmentBill;
use App\Actions\Payments\ReserveAssessmentBill;
use App\Contracts\PaymentProvider;
use App\Data\Payments\CreateInvoiceRequest;
use App\Data\Payments\PaymentInvoice;
use App\Domain\Retention\RetentionDataClass;
use App\Domain\Retention\RetentionPolicy;
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
use Carbon\CarbonImmutable;
use DomainException;
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
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentPreviewFixture as Fixture;

final class AssessmentBillInvoiceIssuanceTest extends OrganizationPaymentTestCase
{
    use DatabaseTruncation;

    private array $fixtures = [];

    private AssessmentBill $bill;

    private OutboxMessage $intent;

    private int $method;

    private bool $expectedHttp = false;

    protected function setUp(): void
    {
        // SQLite :memory: is a new database for each recreated application.
        RefreshDatabaseState::$migrated = false;
        parent::setUp();
        Date::setTestNow('2026-09-01T00:00:00Z');
        Http::preventStrayRequests();
        Http::fake([]);
        Bus::fake();
        Queue::fake();
        config()->set('assessment_integration.checkout.enabled', true);
        $this->fixtures[] = Fixture::create();
        $organization = $this->fixtures[0]['organization'];
        for ($index = 1; $index < 10; $index++) {
            $this->fixtures[] = Fixture::create(['organization' => $organization]);
        }
        foreach ($this->fixtures as $fixture) {
            DB::table('package_items')->insert([
                'package_id' => $fixture['package'],
                'test_type' => 'dass21',
                'sort_order' => 2,
            ]);
        }
        DB::table('assessment_participants')->where('organization_id', $organization)->update([
            'funding_mode' => 'INVOICED_TO_ORGANIZATION',
            'metadata' => '{"checkout_contract_version":"checkout-v2","checkout_initial_funding_mode":null}',
        ]);
        $admin = Admin::create(['branch_id' => $organization, 'name' => 'Synthetic issuer',
            'email' => 'issuer@example.test', 'password' => 'synthetic', 'role' => AdminRole::BranchAdmin]);
        $this->method = DB::table('payment_methods')->insertGetId(['code' => 'xendit', 'display_name' => 'Synthetic', 'is_active' => true]);
        $selection = array_map(Fixture::selection(...), $this->fixtures);
        app(RlsContextRunner::class)->runAsService(function () use ($organization, $admin, $selection): void {
            $preview = app(PreviewAssessmentBill::class)->execute($organization, $selection, PayerType::Organization);
            $this->bill = app(ReserveAssessmentBill::class)->execute($admin, $selection, $this->method, $preview['selectionHash'], 'issue-ten');
            app(ClaimAssessmentBillInvoice::class)->execute($organization, $this->bill->id);
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
            // Do not leak the in-memory migration marker into the next recreated app.
            RefreshDatabaseState::$migrated = false;
            parent::tearDown();
        }
    }

    public function test_ten_items_use_one_create_and_one_strict_lookup_after_permit_commit(): void
    {
        $provider = $this->provider($this->invoice());
        $provider->expects($this->once())->method('createInvoice')->with($this->callback(function (CreateInvoiceRequest $request): bool {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertNull(app(RlsContextRunner::class)->current());
            $this->assertSame($this->bill->public_reference, $request->orderReference);
            $this->assertSame(1_000, $request->amount);
            $this->assertSame('IDR', $request->currency);
            $this->assertSame('Psikotes LSI '.$this->bill->public_reference, $request->description);
            $this->assertTrue($request->expiresAt->equalTo('2026-09-02T00:00:00Z'));
            $this->assertSame('processing', $this->intent->fresh()->status);
            $this->assertSame(1, $this->intent->fresh()->attempts);

            return true;
        }))->willReturn($this->invoice());
        $result = $this->issue($provider);

        $this->assertSame(['decision' => 'issued', 'messageId' => $this->intent->message_id], $result);
        $this->assertIssued();
        $this->assertSame(10, DB::table('assessment_bill_items')->where('bill_id', $this->bill->id)->count());
        $this->assertSame(0, DB::table('assessment_bill_items')->where('bill_id', $this->bill->id)->whereNotNull('settled_at')->count());
        $this->assertSame(0, DB::table('assessment_entitlements')->where('organization_id', $this->bill->organization_id)->count());
        $this->assertSame(0, DB::table('orders')->whereIn('participant_id', array_column($this->fixtures, 'participant'))->count());
        $this->assertSame(0, app(DispatchNotificationOutbox::class)->handle());
        $this->assertSame(0, app(DispatchIntegrationOutbox::class)->handle());
    }

    public function test_create_exception_still_uses_exact_lookup_and_can_attach(): void
    {
        $provider = $this->provider($this->invoice());
        $provider->expects($this->once())->method('createInvoice')->willThrowException(new PaymentProviderException('synthetic create unknown'));
        $this->assertSame('issued', $this->issue($provider)['decision']);
        $this->assertIssued();
    }

    public function test_real_xendit_adapter_uses_one_post_then_one_strict_fake_get(): void
    {
        $this->expectedHttp = true;
        config()->set('services.xendit', ['secret_key' => 'synthetic-issuance-secret',
            'base_url' => 'https://api.xendit.co', 'connect_timeout_seconds' => 3, 'timeout_seconds' => 10]);
        $payload = ['id' => 'invoice-123', 'external_id' => $this->bill->public_reference, 'amount' => 1_000,
            'currency' => 'IDR', 'status' => 'PENDING', 'invoice_url' => 'https://invoice.xendit.co/invoice-123',
            'expiry_date' => '2026-09-02T00:00:00Z'];
        Http::fake(function (Request $request) use ($payload) {
            return $request->method() === 'POST' ? Http::response($payload) : Http::response([$payload]);
        });
        $this->assertSame('issued', $this->issue(new XenditProvider)['decision']);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && parse_url($request->url(), PHP_URL_PATH) === '/v2/invoices'
            && $request['external_id'] === $this->bill->public_reference && $request['amount'] === 1_000);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), 'external_id='.urlencode($this->bill->public_reference))
            && str_contains($request->url(), 'limit=2'));
        $this->assertSame(1, count(Http::recorded(fn (Request $request): bool => $request->method() === 'POST')));
        $this->assertIssued();
    }

    public function test_create_success_with_unknown_lookup_becomes_unknown_without_retry(): void
    {
        $provider = $this->provider(exception: new PaymentProviderException('Invoice lookup outcome is unknown.'));
        $provider->expects($this->once())->method('createInvoice')->willReturn($this->invoice());
        $this->assertSame(['decision' => 'unknown', 'messageId' => $this->intent->message_id], $this->issue($provider));
        $this->assertUnknown();
    }

    public function test_create_and_lookup_provider_references_must_match(): void
    {
        $created = new PaymentInvoice('invoice-other', 'https://invoice.xendit.co/invoice-other', 1_000, 'IDR', Date::now()->addDay());
        $provider = $this->provider($this->invoice());
        $provider->expects($this->once())->method('createInvoice')->willReturn($created);
        $this->assertSame('unknown', $this->issue($provider)['decision']);
        $this->assertUnknown();
    }

    public function test_crash_after_permit_commit_before_post_never_rearms_or_creates(): void
    {
        $issuer = app(IssueAssessmentBillInvoice::class);
        $messageExpiry = $this->intent->fresh()->expires_at;
        $permit = $issuer->consume($this->intent->message_id);
        $this->assertSame($this->intent->message_id, $permit?->messageId);
        $message = $this->intent->fresh();
        $this->assertSame('processing', $message->status);
        $this->assertSame(1, $message->attempts);
        $this->assertTrue($messageExpiry->equalTo($message->expires_at));
        $audit = DB::table('audit_logs')->where('action', 'assessment_bill.invoice_permit_consumed')->sole();
        $anchor = CarbonImmutable::parse((string) $audit->occurred_at)->utc();
        $this->assertTrue($message->updated_at->utc()->equalTo($anchor));
        $this->assertSame(
            app(RetentionPolicy::class)->expiresAt(RetentionDataClass::Audit, $anchor)->format('Y-m-d H:i:s.uP'),
            CarbonImmutable::parse((string) $audit->expires_at)->utc()->format('Y-m-d H:i:s.uP'),
        );
        $this->assertSame(
            '2029-02-28 03:15:00.000000+00:00',
            app(RetentionPolicy::class)->expiresAt(
                RetentionDataClass::Audit,
                CarbonImmutable::parse('2024-02-29 10:15:00+07:00')->utc(),
            )->format('Y-m-d H:i:s.uP'),
        );

        $provider = $this->providerNeverCalled();
        app()->instance(PaymentProvider::class, $provider);
        $this->assertSame(['decision' => 'recovery_required', 'messageId' => $this->intent->message_id],
            app(IssueAssessmentBillInvoice::class)->execute($this->intent->message_id));
        $this->assertSame('issuing', $this->bill->fresh()->status);
        $this->assertSame(1, $this->intent->fresh()->attempts);
    }

    #[DataProvider('ambientContexts')]
    public function test_ambient_context_or_transaction_is_rejected_before_provider(?string $role): void
    {
        $provider = $this->providerNeverCalled();
        app()->instance(PaymentProvider::class, $provider);
        $call = fn () => app(IssueAssessmentBillInvoice::class)->execute($this->intent->message_id);
        try {
            if ($role === null) {
                DB::transaction($call);
            } else {
                app(RlsContextRunner::class)->run(new RlsContext($role, $this->bill->organization_id, $this->fixtures[0]['participant']), $call);
            }
            $this->fail('Ambient context must be rejected.');
        } catch (LogicException) {
            $this->assertSame('pending', $this->intent->fresh()->status);
            $this->assertSame(0, $this->intent->fresh()->attempts);
        }
    }

    public static function ambientContexts(): iterable
    {
        foreach ([null, 'service', 'participant', 'branch_admin', 'super_admin', 'staff', 'psychologist'] as $role) {
            yield [$role];
        }
    }

    #[DataProvider('guardFailures')]
    public function test_guard_failure_before_permit_never_calls_provider(string $case): void
    {
        match ($case) {
            'policy-off' => DB::table('branches')->where('id', $this->bill->organization_id)->update(['allowed_payer_types' => '["self"]']),
            'method-off' => DB::table('payment_methods')->where('id', $this->method)->update(['is_active' => false]),
            'revoked' => DB::table('assessment_participants')->where('id', $this->fixtures[0]['attempt'])->update(['revoked_at' => now()]),
            'expired' => Date::setTestNow('2026-09-03T00:00:00Z'),
            'corrupt' => $this->corruptPayload(),
            'foreign-scope' => $this->foreignScopePayload(),
            'lost' => $this->intent->delete(),
        };
        app()->instance(PaymentProvider::class, $this->providerNeverCalled());
        try {
            app(IssueAssessmentBillInvoice::class)->execute($this->intent->message_id);
            $this->fail('Invalid permit must fail closed.');
        } catch (DomainException) {
            $this->assertSame('issuing', $this->bill->fresh()->status);
            if ($case !== 'lost') {
                $this->assertSame(0, $this->intent->fresh()->attempts);
                $this->assertSame('pending', $this->intent->fresh()->status);
            }
            $this->assertSame(0, DB::table('audit_logs')->where('action', 'assessment_bill.invoice_permit_consumed')->count());
        }
    }

    public static function guardFailures(): iterable
    {
        foreach (['policy-off', 'method-off', 'revoked', 'expired', 'corrupt', 'foreign-scope', 'lost'] as $case) {
            yield $case => [$case];
        }
    }

    public function test_late_exact_response_cannot_overwrite_paid_bill(): void
    {
        $provider = $this->provider($this->invoice());
        $provider->expects($this->once())->method('createInvoice')->willReturnCallback(function (): PaymentInvoice {
            $this->bill->update(['status' => 'paid', 'paid_at' => now()]);

            return $this->invoice();
        });
        $this->assertSame('recovery_required', $this->issue($provider)['decision']);
        $this->assertSame('paid', $this->bill->fresh()->status);
        $this->assertNotNull($this->bill->fresh()->paid_at);
        $this->assertNull($this->bill->fresh()->gateway_ref);
        $this->assertSame('processing', $this->intent->fresh()->status);
        $this->assertSame(1, $this->intent->fresh()->attempts);
    }

    public function test_replay_after_success_never_calls_provider_or_overwrites_invoice(): void
    {
        $this->issue($this->successfulProvider());
        $bill = $this->bill->fresh()->getAttributes();
        $message = $this->intent->fresh()->getAttributes();
        app()->instance(PaymentProvider::class, $this->providerNeverCalled());
        $this->assertSame('recovery_required', app(IssueAssessmentBillInvoice::class)->execute($this->intent->message_id)['decision']);
        $this->assertSame($bill, $this->bill->fresh()->getAttributes());
        $this->assertSame($message, $this->intent->fresh()->getAttributes());
    }

    private function issue(PaymentProvider $provider): array
    {
        app()->instance(PaymentProvider::class, $provider);

        return app(IssueAssessmentBillInvoice::class)->execute($this->intent->message_id);
    }

    private function provider(?PaymentInvoice $invoice = null, ?PaymentProviderException $exception = null): PaymentProvider&MockObject
    {
        $provider = $this->createMock(PaymentProvider::class);
        $lookup = $provider->expects($this->once())->method('lookupInvoice')
            ->with($this->bill->public_reference, 1_000, 'IDR');
        $exception === null ? $lookup->willReturn($invoice ?? $this->invoice()) : $lookup->willThrowException($exception);

        return $provider;
    }

    private function successfulProvider(): PaymentProvider
    {
        $provider = $this->provider($this->invoice());
        $provider->expects($this->once())->method('createInvoice')->willReturn($this->invoice());

        return $provider;
    }

    private function providerNeverCalled(): PaymentProvider&MockObject
    {
        $provider = $this->createMock(PaymentProvider::class);
        $provider->expects($this->never())->method('createInvoice');
        $provider->expects($this->never())->method('lookupInvoice');

        return $provider;
    }

    private function invoice(): PaymentInvoice
    {
        return new PaymentInvoice('invoice-123', 'https://invoice.xendit.co/invoice-123', 1_000, 'IDR', Date::now()->addDay());
    }

    private function assertIssued(): void
    {
        $bill = $this->bill->fresh();
        $message = $this->intent->fresh();
        $this->assertSame('pending', $bill->status);
        $this->assertSame('invoice-123', $bill->gateway_ref);
        $this->assertSame('https://invoice.xendit.co/invoice-123', $bill->invoice_url);
        $this->assertTrue($bill->expires_at->equalTo(Date::now()->addDay()));
        $this->assertSame('processed', $message->status);
        $this->assertSame(1, $message->attempts);
        $this->assertNotNull($message->processed_at);
        $this->assertNull($message->last_error);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.invoice_permit_consumed')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.invoice_issued')->count());
    }

    private function assertUnknown(): void
    {
        $this->assertSame('unknown', $this->bill->fresh()->status);
        $this->assertNull($this->bill->fresh()->gateway_ref);
        $this->assertSame('failed', $this->intent->fresh()->status);
        $this->assertSame(1, $this->intent->fresh()->attempts);
        $this->assertSame('INVOICE_OUTCOME_UNKNOWN', $this->intent->fresh()->last_error);
        $this->assertNull($this->intent->fresh()->processed_at);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.invoice_unknown')->count());
    }

    private function corruptPayload(): void
    {
        $payload = $this->intent->payload;
        $payload['snapshot']['amount']++;
        $this->intent->forceFill(['payload' => $payload])->save();
    }

    private function foreignScopePayload(): void
    {
        $payload = $this->intent->payload;
        $payload['snapshot']['organizationId']++;
        $this->intent->forceFill(['payload' => $payload])->save();
    }
}
