<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Actions\Payments\ClaimAssessmentBillInvoice;
use App\Actions\Payments\FinalizeAssessmentBill;
use App\Actions\Payments\IssueAssessmentBillInvoice;
use App\Actions\Payments\PreviewAssessmentBill;
use App\Actions\Payments\ReserveAssessmentBill;
use App\Contracts\PaymentProvider;
use App\Data\Payments\CreateInvoiceRequest;
use App\Data\Payments\PaymentEvent;
use App\Data\Payments\PaymentInvoice;
use App\Enums\AdminRole;
use App\Enums\PayerType;
use App\Enums\PaymentStatus;
use App\Filament\Resources\OrganizationBills\OrganizationBillResource;
use App\Models\Admin;
use App\Models\AssessmentBill;
use App\Models\AssessmentCharge;
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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentPreviewFixture;

/** SQLite composition only: no webhook authentication, browser or concurrency claim. */
final class CollectiveBillLifecycleCompositionTest extends OrganizationPaymentTestCase
{
    use DatabaseTruncation;

    private const int TOTAL_IDR = 2020;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Http::preventStrayRequests();
        Http::fake([]);
        Bus::fake();
        Queue::fake();
        config()->set('assessment_integration.checkout.enabled', true);
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
    public function test_ten_attempts_share_one_invoice_and_exact_settlement_without_bypassing_consent(bool $lastConsentPending): void
    {
        $organization = null;
        $selection = $expectedSnapshots = $participants = [];
        foreach ([100, 200, 300, 100, 200, 300, 100, 200, 300, 100] as $index => $base) {
            $fixture = AssessmentPreviewFixture::create($organization === null ? null : ['organization' => $organization], $base);
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
                'packageName' => $package->name, 'testTypes' => ['ist'], 'baseAmount' => $base,
                'consultationRequested' => $consultation, 'consultationAmount' => $consultation ? 30 : 0,
                'amount' => $base + ($consultation ? 30 : 0), 'currency' => 'IDR',
            ];
            $this->prerequisites($fixture['participant'], ! ($lastConsentPending && $index === 9));
        }
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
        $provider->expects($this->once())->method('createInvoice')->with($this->callback(function (CreateInvoiceRequest $request) use ($bill): bool {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertNull(app(RlsContextRunner::class)->current());
            $this->assertSame($bill->public_reference, $request->orderReference);
            $this->assertSame(self::TOTAL_IDR, $request->amount);
            $this->assertSame('IDR', $request->currency);

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
        $this->assertDatabaseCount('assessment_entitlements', $readyCount);
        $this->assertSame($readyCount, DB::table('assessment_entitlements')->where('status', 'ready')->count());
        $lastAttempt = array_key_last($participants);
        foreach ($participants as $attempt => $participant) {
            $pending = $lastConsentPending && $attempt === $lastAttempt;
            if (! $pending) {
                $this->assertDatabaseHas('assessment_entitlements', ['assessment_participant_id' => $attempt, 'participant_id' => $participant, 'test_type' => 'ist', 'status' => 'ready']);
                $entitlement = app(RlsContextRunner::class)->runAsService(
                    fn () => app(AssessmentEntitlementGate::class)->assertReady(new AssessmentPrincipal($participant, $organization, $attempt), 'ist'),
                );
                $this->assertSame($attempt, $entitlement->assessment_participant_id);
            }
            $this->assertDatabaseHas('assessment_participants', ['id' => $attempt, 'assessment_status' => $pending ? 'PROVISIONED' : 'READY']);
        }
        if ($lastConsentPending) {
            $this->assertDatabaseMissing('consent_records', ['participant_id' => $participants[$lastAttempt]]);
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

    /** @return array<string, array{bool}> */
    public static function consentCases(): array
    {
        return ['all consent accepted' => [false], 'last consent still absent' => [true]];
    }

    private function admin(int $organization, string $suffix): Admin
    {
        return Admin::create(['branch_id' => $organization, 'name' => 'Synthetic '.$suffix,
            'email' => 'composition-'.$suffix.'@example.test', 'password' => 'synthetic-only', 'role' => AdminRole::BranchAdmin]);
    }

    private function prerequisites(int $participant, bool $consentAccepted): void
    {
        if ($consentAccepted) {
            $document = ConsentDocument::for('psychotest');
            DB::table('consent_records')->insert(['participant_id' => $participant, 'consent_type' => 'psychotest',
                'status' => 'accepted', 'document_version' => $document->version, 'document_hash' => $document->hash, 'consented_at' => now()]);
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
            'assessment_participants', 'audit_logs', 'outbox_messages', 'consent_records', 'identity_verifications', 'identity_evidence'] as $table) {
            $state[$table] = array_values(DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all());
        }

        return $state;
    }
}
