<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Actions\Integrations\IntegrationContractViolation;
use App\Actions\Integrations\IssueCheckoutHandoff;
use App\Actions\Payments\ActivateSettledAssessment;
use App\Actions\Payments\ClaimAssessmentBillInvoice;
use App\Actions\Payments\FinalizeAssessmentBill;
use App\Actions\Payments\IssueAssessmentBillInvoice;
use App\Actions\Payments\PreviewAssessmentBill;
use App\Actions\Payments\ReserveAssessmentBill;
use App\Contracts\PaymentProvider;
use App\Data\Integrations\CheckoutHandoffIssueInput;
use App\Data\Payments\CreateInvoiceRequest;
use App\Data\Payments\PaymentEvent;
use App\Data\Payments\PaymentInvoice;
use App\Enums\AdminRole;
use App\Enums\CheckoutHandoffIntent;
use App\Enums\PayerType;
use App\Enums\PaymentStatus;
use App\Filament\Resources\OrganizationBills\OrganizationBillResource;
use App\Models\Admin;
use App\Models\AssessmentBill;
use App\Models\AssessmentCharge;
use App\Models\IntegrationClient;
use App\Models\OutboxMessage;
use App\Models\TestPackage;
use App\Registration\ConsentDocument;
use App\Security\RlsContextRunner;
use App\Services\ParticipantAuth\AssessmentEntitlementGate;
use App\Services\ParticipantAuth\AssessmentPrincipal;
use App\Services\ParticipantAuth\Exceptions\EntitlementLocked;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentPreviewFixture;

/** SQLite composition only: no webhook authentication, browser or concurrency claim. */
final class CollectiveBillLifecycleCompositionTest extends OrganizationPaymentTestCase
{
    use DatabaseTruncation;

    private const int TOTAL_IDR = 2020;

    private const string PRIVATE_DASS_MARKER = 'DASS_DETAIL_SENTINEL';

    protected function setUp(): void
    {
        parent::setUp();
        // SQLite cross-class ordering can retain a stale migration flag; see tasks/handoffs/sqlite-test-isolation-known-issue-2026-09-16.md.
        $this->restoreSchemaBaselineIfMissing();
        $this->freezeTime();
        Http::preventStrayRequests();
        Http::fake([]);
        Bus::fake();
        Queue::fake();
        config()->set('assessment_integration.checkout.enabled', true);
    }

    private function restoreSchemaBaselineIfMissing(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('branches') || ! \Illuminate\Support\Facades\Schema::hasTable('payment_methods')) {
            $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]));
        }
    }

    protected function tearDown(): void
    {
        try {
            Http::assertNothingSent();
            Mail::assertNothingOutgoing();
            Bus::assertNothingDispatched();
            Queue::assertNothingPushed();
            $this->assertNull(app(RlsContextRunner::class)->current());
            $this->assertSame(0, DB::transactionLevel());
        } finally {
            parent::tearDown();
        }
    }

    #[DataProvider('consentCases')]
    public function test_ten_attempts_share_one_invoice_and_exact_settlement_without_bypassing_consent(bool $lastConsentPending, ?string $mismatch): void
    {
        $organization = null;
        $selection = $expectedSnapshots = $participants = [];
        foreach ([100, 200, 300, 100, 200, 300, 100, 200, 300, 100] as $index => $base) {
            $fixture = AssessmentPreviewFixture::create($organization === null ? null : ['organization' => $organization], $base);
            DB::table('package_items')->insert([
                'package_id' => $fixture['package'], 'test_type' => 'dass21', 'sort_order' => 2,
            ]);
            $organization = $fixture['organization'];
            $consultation = in_array($index, [1, 3, 5, 9], true);
            $selection[] = ['assessmentParticipantId' => (int) $fixture['attempt'], 'consultationRequested' => $consultation];
            $participants[$fixture['attempt']] = $fixture['participant'];
            DB::table('participants')->where('id', $fixture['participant'])->update(['full_name' => 'Composition participant '.($index + 1)]);
            DB::table('packages')->where('id', $fixture['package'])->update(['name' => 'Composition package '.($index + 1)]);
            DB::table('assessment_participants')->where('id', $fixture['attempt'])->update([
                'funding_mode' => 'INVOICED_TO_ORGANIZATION',
                'metadata' => '{"checkout_contract_version":"checkout-v2","checkout_initial_funding_mode":null}',
            ]);
            $package = TestPackage::query()->whereKey($fixture['package'])->sole();
            $expectedSnapshots[$fixture['attempt']] = [
                'version' => 1, 'packageId' => $package->id, 'packageCode' => $package->code,
                'packageName' => $package->name, 'testTypes' => ['dass21', 'ist'], 'baseAmount' => $base,
                'consultationRequested' => $consultation, 'consultationAmount' => $consultation ? 30 : 0,
                'amount' => $base + ($consultation ? 30 : 0), 'currency' => 'IDR',
            ];
            $this->prerequisites($fixture['participant'], ! ($lastConsentPending && $index === 9));
        }
        $privateDass = $this->priorPrivateDassResult((int) array_values($participants)[0]);
        $this->assertDatabaseCount('assessment_bills', 0);
        $this->assertDatabaseCount('assessment_charges', 0);
        $this->assertDatabaseCount('assessment_bill_items', 0);
        $this->assertDatabaseCount('assessment_entitlements', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
        $admin = $this->admin($organization, 'owner');
        $method = DB::table('payment_methods')->insertGetId(['code' => 'xendit', 'display_name' => 'Synthetic invoice', 'is_active' => true]);

        $bill = app(RlsContextRunner::class)->runAsService(function () use ($organization, $selection, $admin, $method): AssessmentBill {
            $preview = app(PreviewAssessmentBill::class)->execute($organization, $selection, PayerType::Organization);
            $this->assertTrue($preview['canReserve']);
            $this->assertSame(self::TOTAL_IDR, $preview['totalAmount']);
            $this->assertSame(10, $preview['paidCount']);
            $this->assertSame(0, $preview['freeCount']);
            $bill = app(ReserveAssessmentBill::class)->execute($admin, $selection, $method, $preview['selectionHash'], 'composition-ten');
            $replayed = app(ReserveAssessmentBill::class)->execute($admin, array_reverse($selection), $method, $preview['selectionHash'], 'composition-ten');
            $this->assertSame($bill->id, $replayed->id);
            app(ClaimAssessmentBillInvoice::class)->execute($organization, $bill->id);

            return $bill;
        });
        $this->assertDatabaseCount('assessment_bills', 1);
        $this->assertDatabaseCount('assessment_charges', 10);
        $this->assertDatabaseCount('assessment_bill_items', 10);
        $this->assertDatabaseCount('outbox_messages', 1);
        $this->assertSnapshots($expectedSnapshots);

        // Catalog changes after reservation must not reprice this bill or its allocations.
        DB::table('packages')->whereIn('id', array_column($expectedSnapshots, 'packageId'))->update([
            'amount' => 9999, 'consultation_amount' => 999, 'name' => 'Changed catalog, never the bill snapshot',
        ]);
        $intent = OutboxMessage::query()->where('aggregate_id', (string) $bill->id)->sole();
        $invoice = new PaymentInvoice('composition-invoice', 'https://invoice.xendit.co/composition-invoice', self::TOTAL_IDR, 'IDR', now()->addDay());
        $provider = $this->createMock(PaymentProvider::class);
        $invoiceProjection = null;
        $provider->expects($this->once())->method('createInvoice')->with($this->callback(function (CreateInvoiceRequest $request) use ($bill, &$invoiceProjection): bool {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertNull(app(RlsContextRunner::class)->current());
            $this->assertSame($bill->public_reference, $request->orderReference);
            $this->assertSame(self::TOTAL_IDR, $request->amount);
            $this->assertSame('IDR', $request->currency);
            $invoiceProjection = json_encode([
                'reference' => $request->orderReference, 'amount' => $request->amount,
                'currency' => $request->currency, 'description' => $request->description,
                'expiresAt' => $request->expiresAt->toIso8601String(),
            ], JSON_THROW_ON_ERROR);

            return true;
        }))->willReturn($invoice);
        $provider->expects($this->once())->method('lookupInvoice')
            ->with($bill->public_reference, self::TOTAL_IDR, 'IDR')->willReturn($invoice);
        foreach (['checkStatus', 'normalizeWebhook', 'expireInvoice'] as $unused) {
            $provider->expects($this->never())->method($unused);
        }
        app()->instance(PaymentProvider::class, $provider);
        $this->assertSame(['decision' => 'issued', 'messageId' => $intent->message_id], app(IssueAssessmentBillInvoice::class)->execute($intent->message_id));
        $this->assertDatabaseHas('assessment_bills', ['id' => $bill->id, 'status' => 'pending', 'amount' => self::TOTAL_IDR, 'paid_at' => null]);
        $this->assertSame(0, DB::table('assessment_bill_items')->whereNotNull('settled_at')->count());
        $this->assertDatabaseCount('assessment_entitlements', 0);

        $pendingState = $this->durableState();
        app(IssueAssessmentBillInvoice::class)->execute($intent->message_id);
        $this->assertSame($pendingState, $this->durableState(), 'Issuance replay while pending must not create again.');
        $event = new PaymentEvent(
            eventId: 'composition-paid', providerReference: $invoice->providerReference,
            merchantReference: $bill->public_reference, status: PaymentStatus::Paid,
            occurredAt: now(), amount: self::TOTAL_IDR, currency: 'IDR',
        );
        if ($mismatch !== null) {
            $this->assertRejectedEventPreservesPendingBill($bill, $event, $mismatch);
        }
        $this->assertSame([
            'decision' => 'settled', 'allocationCount' => 10, 'activatedAttemptCount' => $lastConsentPending ? 9 : 10,
        ], app(FinalizeAssessmentBill::class)->execute($event));
        $this->assertDatabaseHas('assessment_bills', ['id' => $bill->id, 'status' => 'paid', 'amount' => self::TOTAL_IDR, 'paid_at' => now()]);
        $items = DB::table('assessment_bill_items')->orderBy('id')->get();
        $this->assertCount(10, $items);
        $this->assertSame(self::TOTAL_IDR, (int) $items->sum('amount'));
        foreach ($items as $item) {
            $this->assertSame($bill->id, $item->bill_id);
            $this->assertSame(now()->toDateTimeString(), $item->settled_at);
            $snapshot = $expectedSnapshots[AssessmentCharge::query()->whereKey($item->charge_id)->sole()->assessment_participant_id];
            $this->assertSame($snapshot['amount'], $item->amount);
            $this->assertSame('IDR', $item->currency);
        }
        $this->assertSnapshots($expectedSnapshots);
        $readyCount = $lastConsentPending ? 9 : 10;
        $this->assertDatabaseCount('assessment_entitlements', $readyCount * 2);
        $this->assertSame($readyCount * 2, DB::table('assessment_entitlements')->where('status', 'ready')->count());
        $lastAttempt = array_key_last($participants);
        foreach ($participants as $attempt => $participant) {
            $pending = $lastConsentPending && $attempt === $lastAttempt;
            if (! $pending) {
                foreach (['dass21', 'ist'] as $testType) {
                    $this->assertDatabaseHas('assessment_entitlements', ['assessment_participant_id' => $attempt, 'participant_id' => $participant, 'test_type' => $testType, 'status' => 'ready']);
                    $entitlement = app(RlsContextRunner::class)->runAsService(
                        fn () => app(AssessmentEntitlementGate::class)->assertReady(new AssessmentPrincipal($participant, $organization, $attempt), $testType),
                    );
                    $this->assertSame($attempt, $entitlement->assessment_participant_id);
                }
            }
            $this->assertDatabaseHas('assessment_participants', ['id' => $attempt, 'assessment_status' => $pending ? 'PROVISIONED' : 'READY']);
        }
        if ($lastConsentPending) {
            foreach (['psychotest', 'dass'] as $consentType) {
                $this->assertDatabaseMissing('consent_records', ['participant_id' => $participants[$lastAttempt], 'consent_type' => $consentType]);
            }
            // Absent consent creates no new entitlement; locked means the real access gate denies.
            $this->assertDatabaseMissing('assessment_entitlements', ['assessment_participant_id' => $lastAttempt]);
            try {
                app(RlsContextRunner::class)->runAsService(
                    fn () => app(AssessmentEntitlementGate::class)->assertReady(new AssessmentPrincipal($participants[$lastAttempt], $organization, $lastAttempt), 'ist'),
                );
                $this->fail('Paid without consent must not grant access.');
            } catch (EntitlementLocked) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.paid')->count());
        foreach (['assessment_bill.reserved', 'assessment_bill.invoice_permit_consumed', 'assessment_bill.invoice_issued'] as $action) {
            $this->assertSame(1, DB::table('audit_logs')->where('action', $action)->count());
        }
        $this->assertDatabaseCount('assessment_bills', 1);
        $this->assertDatabaseCount('assessment_charges', 10);
        $this->assertSame($readyCount, DB::table('audit_logs')->where('action', 'assessment.activated')->count());
        $this->assertSame($readyCount, DB::table('outbox_messages')->where('topic', 'assessment.activation')->count());
        $this->assertDatabaseCount('outbox_messages', 1 + $readyCount);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('entitlements', 0);

        $settledState = $this->durableState();
        $this->assertSame(['decision' => 'replayed', 'allocationCount' => 10, 'activatedAttemptCount' => 0], app(FinalizeAssessmentBill::class)->execute($event));
        try {
            app(IssueAssessmentBillInvoice::class)->execute($intent->message_id);
            $this->fail('An issuance permit cannot be replayed as pending after settlement.');
        } catch (DomainException $exception) {
            $this->assertSame('INVOICE_PERMIT_INVALID', $exception->getMessage());
        }
        $this->assertSame($settledState, $this->durableState(), 'Replay must preserve exact rows, not only counts.');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        $this->actingAs($admin, 'admin');
        $projection = OrganizationBillResource::getEloquentQuery()->sole();
        $this->assertSame($bill->id, $projection->id);
        $this->assertSame('paid', $projection->status);
        $this->assertSame(self::TOTAL_IDR, $projection->amount);
        $this->assertSame(10, $projection->item_count);
        $response = $this->get('/admin/organization-bills/'.$bill->id)->assertOk()->assertSee('Lunas');
        foreach (range(1, 10) as $number) {
            $response->assertSee('Composition participant '.$number)->assertSee('Composition package '.$number);
        }
        foreach (DB::table('assessment_participants')->whereIn('id', array_keys($participants))->pluck('assessment_attempt_id') as $attemptReference) {
            $response->assertSee($attemptReference);
        }
        $response->assertDontSee('Changed catalog, never the bill snapshot')->assertDontSee($invoice->providerReference)->assertDontSee($invoice->paymentUrl);
        $privateValues = [...$privateDass['resultMarkers'], $privateDass['consentVersion'],
            $privateDass['consentHash'], $privateDass['consentTitle'], $privateDass['consentText']];
        foreach ($privateValues as $privateValue) {
            $response->assertDontSee($privateValue);
            $this->assertStringNotContainsString($privateValue, (string) $invoiceProjection);
            $this->assertStringNotContainsString($privateValue, json_encode(
                DB::table('assessment_bills')->where('id', $bill->id)->get()->all(), JSON_THROW_ON_ERROR,
            ));
            $this->assertStringNotContainsString($privateValue, json_encode(
                DB::table('audit_logs')->get(['action', 'context'])->all(), JSON_THROW_ON_ERROR,
            ));
            $this->assertStringNotContainsString($privateValue, json_encode(
                DB::table('outbox_messages')->get(['topic', 'payload'])->all(), JSON_THROW_ON_ERROR,
            ));
            $this->assertStringNotContainsString($privateValue, json_encode(
                DB::table('participants')->whereIn('id', array_values($participants))
                    ->where('id', '!=', $privateDass['participant'])->get()->all(), JSON_THROW_ON_ERROR,
            ));
        }
        $this->assertSame(1, DB::table('dass_assessments')->where('participant_id', $privateDass['participant'])->count());
        $this->assertSame(0, DB::table('dass_assessments')->whereIn('participant_id', array_values($participants))
            ->where('participant_id', '!=', $privateDass['participant'])->count());
        $this->assertSame(1, DB::table('dass_results')->count());
        $foreignOrganization = DB::table('branches')->insertGetId([
            'code' => 'COMPOSITION_FOREIGN', 'ref_code' => 'COMPOSITION_FOREIGN', 'name' => 'Synthetic foreign',
            'organization_code' => 'COMPOSITION_FOREIGN', 'display_name' => 'Synthetic foreign',
        ]);
        // A separate login must not inherit the owner's AuthenticateSession password hash.
        $this->flushSession();
        $foreignAdmin = $this->admin($foreignOrganization, 'foreign');
        $this->actingAs($foreignAdmin, 'admin');
        $this->assertSame(0, OrganizationBillResource::getEloquentQuery()->count());
        $this->assertFalse(OrganizationBillResource::canView($bill->fresh()));
        $this->get('/admin/organization-bills/'.$bill->id)->assertNotFound();
        $this->assertAuthenticatedAs($foreignAdmin, 'admin');
        $this->assertSame($settledState, $this->durableState(), 'Reading owner/foreign projections must not write payment state.');
    }

    public function test_same_participant_attempts_from_two_trusted_sources_keep_lookup_payment_and_access_scoped(): void
    {
        config()->set('assessment_integration.checkout_handoff.enabled', true);
        config()->set('assessment_integration.checkout_handoff.ttl_seconds', 600);
        $first = AssessmentPreviewFixture::create(null, 111);
        $second = AssessmentPreviewFixture::create([
            'organization' => $first['organization'], 'participant' => $first['participant'],
        ], 222);
        $foreign = AssessmentPreviewFixture::create(null, 333);
        $secondSource = 'P17A_SECOND_'.$second['attempt'];
        DB::table('assessment_participants')->where('id', $second['attempt'])->update(['source_system' => $secondSource]);
        DB::table('integration_sources')->where('id', $second['source'])->update(['source_system' => $secondSource]);
        DB::table('assessment_participants')->whereIn('id', [$first['attempt'], $second['attempt']])->update([
            'funding_mode' => 'INVOICED_TO_ORGANIZATION',
            'metadata' => json_encode([
                'checkout_contract_version' => 'checkout-v2', 'checkout_initial_funding_mode' => null,
            ], JSON_THROW_ON_ERROR),
        ]);
        DB::table('participants')->where('id', $first['participant'])->update(['full_name' => 'PRIVATE SHARED PROFILE']);
        foreach ([$first, $second, $foreign] as $index => $fixture) {
            DB::table('packages')->where('id', $fixture['package'])->update([
                'name' => 'PRIVATE SOURCE '.($index + 1).' PACKAGE',
            ]);
            DB::table('package_items')->insert([
                'package_id' => $fixture['package'], 'test_type' => 'dass21', 'sort_order' => 2,
            ]);
        }
        $this->assertNotSame($first['attempt'], $second['attempt']);
        $this->assertNotSame($first['client'], $second['client']);
        $this->assertNotSame($first['source'], $second['source']);
        $this->assertNotSame($first['package'], $second['package']);
        $this->assertSame(2, DB::table('assessment_participants')
            ->where('organization_id', $first['organization'])->where('participant_id', $first['participant'])->count());
        $this->prerequisites($first['participant'], false);

        $firstAttemptReference = DB::table('assessment_participants')->where('id', $first['attempt'])->value('assessment_attempt_id');
        $secondAttemptReference = DB::table('assessment_participants')->where('id', $second['attempt'])->value('assessment_attempt_id');
        $firstSource = DB::table('assessment_participants')->where('id', $first['attempt'])->value('source_system');
        if (! is_string($firstAttemptReference) || ! is_string($secondAttemptReference) || ! is_string($firstSource)) {
            throw new \LogicException('Synthetic attempt source identity is unavailable.');
        }
        $firstClient = IntegrationClient::query()->whereKey($first['client'])->sole();
        $secondClient = IntegrationClient::query()->whereKey($second['client'])->sole();
        $foreignClient = IntegrationClient::query()->whereKey($foreign['client'])->sole();
        $this->assertMatchesRegularExpression(
            '/^och1_[0-9a-f]{64}$/D', $this->issueHandoff($firstClient, $firstAttemptReference, $firstSource, 'first-valid'),
        );
        $this->assertMatchesRegularExpression(
            '/^och1_[0-9a-f]{64}$/D', $this->issueHandoff($secondClient, $secondAttemptReference, $secondSource, 'second-valid'),
        );

        foreach ([
            [$firstClient, $secondAttemptReference, $firstSource, 'cross-client-a'],
            [$secondClient, $firstAttemptReference, $secondSource, 'cross-client-b'],
            [$firstClient, $firstAttemptReference, $secondSource, 'cross-source'],
            [$foreignClient, $firstAttemptReference, $firstSource, 'cross-organization'],
        ] as [$client, $attemptReference, $source, $key]) {
            try {
                $this->issueHandoff($client, $attemptReference, $source, $key);
                $this->fail('A mismatched trusted-source lookup returned a handoff.');
            } catch (IntegrationContractViolation $exception) {
                $this->assertSame('HANDOFF_NOT_ALLOWED', $exception->getMessage());
                foreach (['PRIVATE SHARED PROFILE', 'PRIVATE SOURCE 1 PACKAGE', 'organization', 'dass21'] as $privateValue) {
                    $this->assertStringNotContainsString($privateValue, $exception->getMessage());
                }
            }
        }

        $foreignProjection = app(RlsContextRunner::class)->runAsService(fn (): array => app(PreviewAssessmentBill::class)->execute(
            $foreign['organization'], [['assessmentParticipantId' => $first['attempt'], 'consultationRequested' => false]],
            PayerType::Organization,
        ));
        $this->assertSame('ASSESSMENT_NOT_AVAILABLE', $foreignProjection['items'][0]['reason']);
        foreach (['snapshot', 'policySnapshot'] as $field) {
            $this->assertNull($foreignProjection['items'][0][$field]);
        }
        $this->assertNull($foreignProjection['totalAmount']);
        $this->assertNull($foreignProjection['selectionHash']);
        foreach (['PRIVATE SHARED PROFILE', 'PRIVATE SOURCE 1 PACKAGE', 'dass21'] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, json_encode($foreignProjection, JSON_THROW_ON_ERROR));
        }

        $admin = $this->admin($first['organization'], 'two-source');
        $method = DB::table('payment_methods')->insertGetId([
            'code' => 'xendit', 'display_name' => 'Synthetic source invoice', 'is_active' => true,
        ]);
        $selection = [['assessmentParticipantId' => $first['attempt'], 'consultationRequested' => false]];
        $bill = app(RlsContextRunner::class)->runAsService(function () use ($first, $selection, $admin, $method): AssessmentBill {
            $preview = app(PreviewAssessmentBill::class)->execute($first['organization'], $selection, PayerType::Organization);
            $this->assertSame(['dass21', 'ist'], $preview['items'][0]['snapshot']['testTypes']);
            $bill = app(ReserveAssessmentBill::class)->execute(
                $admin, $selection, $method, $preview['selectionHash'], 'first-source-only',
            );
            app(ClaimAssessmentBillInvoice::class)->execute($first['organization'], $bill->id);

            return $bill;
        });
        $invoice = new PaymentInvoice(
            'two-source-invoice', 'https://invoice.xendit.co/two-source-invoice', $bill->amount,
            'IDR', now()->addDay(),
        );
        $provider = $this->createMock(PaymentProvider::class);
        $provider->expects($this->once())->method('createInvoice')->willReturn($invoice);
        $provider->expects($this->once())->method('lookupInvoice')->willReturn($invoice);
        foreach (['checkStatus', 'normalizeWebhook', 'expireInvoice'] as $unused) {
            $provider->expects($this->never())->method($unused);
        }
        app()->instance(PaymentProvider::class, $provider);
        $intent = OutboxMessage::query()->where('aggregate_id', (string) $bill->id)->sole();
        app(IssueAssessmentBillInvoice::class)->execute($intent->message_id);
        $settled = app(FinalizeAssessmentBill::class)->execute(new PaymentEvent(
            eventId: 'two-source-paid', providerReference: $invoice->providerReference,
            merchantReference: $bill->public_reference, status: PaymentStatus::Paid,
            occurredAt: now(), amount: $bill->amount, currency: 'IDR',
        ));
        $this->assertSame(['decision' => 'settled', 'allocationCount' => 1, 'activatedAttemptCount' => 0], $settled);
        $this->assertDatabaseHas('assessment_bills', ['id' => $bill->id, 'status' => 'paid']);
        foreach ([$first['attempt'], $second['attempt']] as $attempt) {
            $this->assertDatabaseHas('assessment_participants', ['id' => $attempt, 'assessment_status' => 'PROVISIONED']);
            $this->assertDatabaseMissing('assessment_entitlements', ['assessment_participant_id' => $attempt]);
        }
        $this->assertDatabaseMissing('assessment_charges', ['assessment_participant_id' => $second['attempt']]);
        $this->assertSame($first['attempt'], AssessmentCharge::query()
            ->whereKey(DB::table('assessment_bill_items')->where('bill_id', $bill->id)->value('charge_id'))
            ->sole()->assessment_participant_id);
        foreach ([$first['attempt'], $second['attempt']] as $attempt) {
            $this->assertAttemptLocked($first['participant'], $first['organization'], $attempt);
        }

        $this->acceptConsents($first['participant']);
        $activated = app(RlsContextRunner::class)->runAsService(fn (): array => app(ActivateSettledAssessment::class)->execute(
            new AssessmentPrincipal($first['participant'], $first['organization'], $first['attempt']),
        ));
        $this->assertSame(['dass21', 'ist'], $activated);
        $this->assertDatabaseHas('assessment_participants', ['id' => $first['attempt'], 'assessment_status' => 'READY']);
        $this->assertSame(2, DB::table('assessment_entitlements')->where('assessment_participant_id', $first['attempt'])->count());
        $this->assertDatabaseHas('assessment_participants', ['id' => $second['attempt'], 'assessment_status' => 'PROVISIONED']);
        $this->assertDatabaseMissing('assessment_entitlements', ['assessment_participant_id' => $second['attempt']]);
        $this->assertDatabaseMissing('assessment_charges', ['assessment_participant_id' => $second['attempt']]);
        $this->assertAttemptLocked($first['participant'], $first['organization'], $second['attempt']);
        $this->assertSame(1, DB::table('assessment_bills')->count());
        $this->assertSame(1, DB::table('assessment_bill_items')->count());
        $this->assertSame(2, DB::table('consent_records')->where('participant_id', $first['participant'])->count());
    }

    /** @return array<string, array{bool, string|null}> */
    public static function consentCases(): array
    {
        return [
            'all consent accepted' => [false, null],
            'last consent still absent' => [true, null],
            'amount mismatch then valid payment' => [true, 'amount'],
            'invalid currency DTO then valid payment' => [true, 'currency'],
        ];
    }

    private function assertRejectedEventPreservesPendingBill(AssessmentBill $bill, PaymentEvent $valid, string $mismatch): void
    {
        $original = $this->durableState();
        if ($mismatch === 'currency') {
            // The public DTO rejects non-IDR before finalization; do not bypass its constructor.
            try {
                new PaymentEvent($valid->eventId, $valid->providerReference, $valid->merchantReference,
                    $valid->status, $valid->occurredAt, $valid->amount, 'USD');
                $this->fail('Non-IDR event must not pass the canonical DTO boundary.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('Payment event money values are invalid.', $exception->getMessage());
            }
            $this->assertSame($original, $this->durableState(), 'Rejected DTO must not alter durable rows.');
            $this->assertNull(app(RlsContextRunner::class)->current());
            $this->assertSame(0, DB::transactionLevel());

            return;
        }
        $rejected = new PaymentEvent($valid->eventId, $valid->providerReference, $valid->merchantReference,
            $valid->status, $valid->occurredAt, self::TOTAL_IDR - 1, 'IDR');
        try {
            app(FinalizeAssessmentBill::class)->execute($rejected);
            $this->fail('Mismatched payment must be rejected before any allocation is settled.');
        } catch (DomainException $exception) {
            $this->assertSame('ASSESSMENT_PAYMENT_MONEY_MISMATCH', $exception->getMessage());
        }
        $this->assertSame($original, $this->durableState(), 'Finalizer denial must preserve every durable row.');
        $this->assertDatabaseHas('assessment_bills', ['id' => $bill->id, 'status' => 'pending', 'paid_at' => null]);
        $this->assertSame(0, DB::table('assessment_bill_items')->whereNotNull('settled_at')->count());
        $this->assertDatabaseCount('assessment_entitlements', 0);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'assessment_bill.paid')->count());
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame(0, DB::transactionLevel());
    }

    private function admin(int $organization, string $suffix): Admin
    {
        return Admin::create(['branch_id' => $organization, 'name' => 'Synthetic '.$suffix,
            'email' => 'composition-'.$suffix.'@example.test', 'password' => 'synthetic-only', 'role' => AdminRole::BranchAdmin]);
    }

    private function issueHandoff(IntegrationClient $client, string $attemptReference, string $source, string $key): string
    {
        $result = app(RlsContextRunner::class)->runAsService(fn () => app(IssueCheckoutHandoff::class)->execute(
            new CheckoutHandoffIssueInput(
                $client, $attemptReference, $source, 'ih1_'.substr(hash('sha256', $key), 0, 32), CheckoutHandoffIntent::Issue,
            ),
        ));
        $raw = $result->rawToken();
        if (! is_string($raw)) {
            throw new \LogicException('Synthetic handoff did not return its one-time bearer.');
        }

        return $raw;
    }

    private function acceptConsents(int $participant): void
    {
        foreach (['psychotest', 'dass'] as $consentType) {
            $document = ConsentDocument::for($consentType);
            DB::table('consent_records')->insert([
                'participant_id' => $participant, 'consent_type' => $consentType,
                'status' => 'accepted', 'document_version' => $document->version,
                'document_hash' => $document->hash, 'consented_at' => now(),
            ]);
        }
    }

    private function assertAttemptLocked(int $participant, int $organization, int $attempt): void
    {
        try {
            app(RlsContextRunner::class)->runAsService(fn () => app(AssessmentEntitlementGate::class)->assertReady(
                new AssessmentPrincipal($participant, $organization, $attempt), 'ist',
            ));
            $this->fail('An attempt inherited access from another payment or consent transition.');
        } catch (EntitlementLocked) {
            $this->addToAssertionCount(1);
        }
    }

    private function prerequisites(int $participant, bool $consentAccepted): void
    {
        if ($consentAccepted) {
            foreach (['psychotest', 'dass'] as $consentType) {
                $document = ConsentDocument::for($consentType);
                DB::table('consent_records')->insert(['participant_id' => $participant, 'consent_type' => $consentType,
                    'status' => 'accepted', 'document_version' => $document->version, 'document_hash' => $document->hash, 'consented_at' => now()]);
            }
        }
        foreach (['identity_document', 'initial_selfie'] as $type) {
            $id = (string) Str::ulid();
            DB::table('identity_evidence')->insert(['public_id' => $id, 'participant_id' => $participant, 'type' => $type,
                'disk' => 'local', 'object_key' => 'synthetic/'.$id, 'mime_type' => 'image/jpeg', 'size_bytes' => 100,
                'width' => 10, 'height' => 10, 'checksum_sha256' => hash('sha256', $id), 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('identity_verifications')->insert(['participant_id' => $participant, 'matcher' => 'synthetic',
            'outcome' => 'match', 'manual_status' => 'pending', 'checked_at' => now()]);
    }

    /** @return array{participant: int, resultMarkers: list<string>, consentVersion: string, consentHash: string, consentTitle: string, consentText: string} */
    private function priorPrivateDassResult(int $participant): array
    {
        $consent = DB::table('consent_records')->where('participant_id', $participant)->where('consent_type', 'dass')->sole();
        $assessment = DB::table('dass_assessments')->insertGetId([
            'public_id' => (string) Str::ulid(), 'participant_id' => $participant,
            'consent_record_id' => $consent->id, 'status' => 'completed',
            'started_at' => now()->subMinute(), 'completed_at' => now(), 'expires_at' => now()->addYear(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('dass_results')->insert([
            'assessment_id' => $assessment,
            'depression_raw' => 1, 'anxiety_raw' => 2, 'stress_raw' => 3,
            'depression_score' => 2, 'anxiety_score' => 4, 'stress_score' => 6,
            'depression_category' => 'DASS_DEP_SENTINEL',
            'anxiety_category' => 'DASS_ANX_SENTINEL',
            'stress_category' => 'DASS_STRESS_SENTINEL',
            'overall_category' => 'DASS_OVERALL_SENTINEL',
            'follow_up' => 'DASS_FOLLOWUP_SENTINEL',
            'validity_flags' => json_encode(['private' => self::PRIVATE_DASS_MARKER], JSON_THROW_ON_ERROR),
            'expires_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $document = ConsentDocument::for('dass');

        return ['participant' => $participant,
            'resultMarkers' => [self::PRIVATE_DASS_MARKER, 'DASS_DEP_SENTINEL', 'DASS_ANX_SENTINEL',
                'DASS_STRESS_SENTINEL', 'DASS_OVERALL_SENTINEL', 'DASS_FOLLOWUP_SENTINEL'],
            'consentVersion' => $document->version, 'consentHash' => $document->hash,
            'consentTitle' => $document->title, 'consentText' => $document->text];
    }

    /** @param array<int, array<string, mixed>> $expected */
    private function assertSnapshots(array $expected): void
    {
        foreach (AssessmentCharge::query()->orderBy('id')->get() as $charge) {
            $this->assertSame($expected[$charge->assessment_participant_id], $charge->price_snapshot);
            $this->assertSame($expected[$charge->assessment_participant_id]['amount'], $charge->amount);
        }
    }

    /** @return array<string, list<array<array-key, mixed>>> */
    private function durableState(): array
    {
        $state = [];
        foreach (['assessment_bills', 'assessment_bill_items', 'assessment_charges', 'assessment_entitlements',
            'assessment_participants', 'audit_logs', 'outbox_messages', 'consent_records', 'identity_verifications', 'identity_evidence',
            'dass_assessments', 'dass_responses', 'dass_results'] as $table) {
            $state[$table] = array_values(DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all());
        }

        return $state;
    }
}
