<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Data\Integrations\CheckoutSessionPrincipal;
use App\Data\Integrations\CheckoutSummaryEvidence;
use App\Models\AssessmentBill;
use App\Models\AssessmentBillItem;
use App\Models\AssessmentCharge;
use App\Models\AssessmentParticipant;
use App\Models\Branch;
use App\Models\IntegrationClient;
use App\Models\IntegrationSource;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Registration\ConsentDocument;
use App\Security\RlsContextRunner;
use App\Services\Integrations\CheckoutSummaryComposer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentAccessFixture as Fixture;

final class CheckoutSummaryComposerTest extends OrganizationPaymentTestCase
{
    use RefreshDatabase;

    private array $fixture;

    private CarbonImmutable $asOf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-04 10:00:00 UTC'));
        $this->asOf = CarbonImmutable::instance(now());
        $this->fixture = Fixture::create();
        DB::table('assessment_participants')->update(['funding_mode' => 'INVOICED_TO_ORGANIZATION',
            'metadata' => '{"checkout_contract_version":"checkout-v2","checkout_initial_funding_mode":null}']);
        DB::table('branches')->update(['status' => 'ACTIVE', 'is_active' => true]);
        DB::table('integration_clients')->update(['enabled' => true]);
        $attempt = AssessmentParticipant::findOrFail($this->fixture['attempt']);
        DB::table('integration_sources')->insert(['integration_client_id' => $attempt->integration_client_id,
            'source_system' => $attempt->source_system, 'contract_version' => 'checkout-v2', 'status' => 'ACTIVE',
            'allowed_assessment_packages' => json_encode([DB::table('packages')->where('id', $attempt->package_id)->value('code')], JSON_THROW_ON_ERROR),
            'allowed_funding_modes' => '[]']);
    }

    public function test_complete_summary_has_recursive_exact_privacy_keys_and_no_business_writes(): void
    {
        $before = $this->rows();
        $summary = $this->read();
        $this->assertSame(['contractVersion', 'sourceName', 'branchName', 'packageName', 'packageSource', 'attemptLabel',
            'profile', 'identityMessage', 'payment', 'access', 'consents'], array_keys($summary));
        $this->assertSame('checkout-summary-v2', $summary['contractVersion']);
        $this->assertSame('Integrasi seleksi', $summary['sourceName']);
        $this->assertSame('Assessment Anda', $summary['attemptLabel']);
        $this->assertCount(7, $summary['profile']);
        foreach ($summary['profile'] as $field) {
            $this->assertSame($field['state'] === 'missing' ? ['key', 'label', 'state', 'required'] : ['key', 'label', 'state', 'required', 'displayValue'], array_keys($field));
        }
        $this->assertSame(['payer', 'state', 'amountIdr', 'amountSource', 'consultationRequested', 'actionAvailable', 'action', 'organizationName'], array_keys($summary['payment']));
        $this->assertSame('Synthetic', $summary['payment']['organizationName']);
        $this->assertSame(['state', 'tests', 'startAvailable', 'message'], array_keys($summary['access']));
        $this->assertSame([
            ['testType' => 'dass21', 'state' => 'ready'],
            ['testType' => 'ist', 'state' => 'ready'],
        ], $summary['access']['tests']);
        $this->assertSame('ready', $summary['access']['state']);
        $this->assertFalse($summary['access']['startAvailable']);
        $this->assertFalse($summary['payment']['actionAvailable']);
        $this->assertNull($summary['payment']['action']);
        $this->assertSame(['psychotest', 'dass', 'legalReviewPending'], array_keys($summary['consents']));
        $this->assertSame(['state' => 'accepted', 'version' => ConsentDocument::for('psychotest')->version], $summary['consents']['psychotest']);
        $this->assertSame(['state' => 'accepted', 'version' => ConsentDocument::for('dass')->version], $summary['consents']['dass']);
        $this->assertTrue($summary['consents']['legalReviewPending']);
        $this->assertSame($before, $this->rows());
        $this->assertNull(app(RlsContextRunner::class)->current());
    }

    public function test_missing_profile_and_consent_never_erase_paid_or_invent_declined(): void
    {
        DB::table('participants')->update(['full_name' => null, 'birth_date' => null, 'gender' => null,
            'education_level' => null, 'intended_field' => null, 'email' => null, 'phone' => null]);
        DB::table('consent_records')->update(['status' => 'declined']);
        $summary = $this->read();
        $this->assertSame(array_fill(0, 7, 'missing'), array_column($summary['profile'], 'state'));
        $this->assertSame('paid', $summary['payment']['state']);
        $this->assertSame('locked', $summary['access']['state']);
        $this->assertSame(['state' => 'required', 'document' => ConsentDocument::for('psychotest')->toPublicArray()], $summary['consents']['psychotest']);
    }

    public function test_without_charge_catalog_is_label_only_and_branch_fallback_is_own(): void
    {
        DB::table('assessment_entitlements')->delete();
        DB::table('assessment_bill_items')->delete();
        DB::table('assessment_charges')->delete();
        DB::table('assessment_participants')->update(['assessment_status' => 'PROVISIONED', 'funding_mode' => null]);
        DB::table('branches')->update(['display_name' => ' ', 'name' => 'Own fallback']);
        $summary = $this->read();
        $this->assertSame('catalog', $summary['packageSource']);
        $this->assertSame('Own fallback', $summary['branchName']);
        $this->assertNull($summary['payment']['amountIdr']);
        $this->assertNull($summary['payment']['consultationRequested']);
        $this->assertSame('unselected', $summary['payment']['state']);
        $this->assertSame('locked', $summary['access']['state']);
        $this->assertArrayNotHasKey('organizationName', $summary['payment']);
    }

    public function test_changed_catalog_cannot_replace_snapshot_and_mandatory_dass_is_independent(): void
    {
        $charge = AssessmentCharge::findOrFail($this->fixture['charge']);
        $snapshot = $charge->price_snapshot;
        $charge->update(['price_snapshot' => $snapshot]);
        DB::table('packages')->update(['name' => 'Changed catalogue', 'amount' => 999]);
        DB::table('package_items')->where('test_type', 'ist')->update(['test_type' => 'papi']);
        DB::table('consent_records')->where('consent_type', 'dass')->update(['status' => 'declined']);
        $summary = $this->read();
        $this->assertSame('Synthetic', $summary['packageName']);
        $this->assertSame('charge_snapshot', $summary['packageSource']);
        $this->assertSame(100, $summary['payment']['amountIdr']);
        $this->assertSame('partial', $summary['access']['state']);
        $this->assertSame([['testType' => 'dass21', 'state' => 'locked'], ['testType' => 'ist', 'state' => 'ready']], $summary['access']['tests']);
        $this->assertSame('required', $summary['consents']['dass']['state']);
    }

    #[DataProvider('unaccepted')]
    public function test_absent_old_hash_withdrawn_or_future_consent_is_required(array $values): void
    {
        DB::table('consent_records')->where('consent_type', 'psychotest')->update($values);
        $summary = $this->read();
        $this->assertSame('required', $summary['consents']['psychotest']['state']);
        $this->assertSame(['version', 'title', 'text'], array_keys($summary['consents']['psychotest']['document']));
        $this->assertSame('locked', $summary['access']['state']);
        $this->assertSame('paid', $summary['payment']['state']);
    }

    public static function unaccepted(): iterable
    {
        yield [['document_version' => 'old']];
        yield [['document_hash' => str_repeat('0', 64)]];
        yield [['withdrawn_at' => '2026-09-04 09:59:59']];
        yield [['consented_at' => '2026-09-04 10:00:01']];
        yield [['consented_at' => null]];
    }

    public function test_title_change_keeps_acceptance_but_same_version_text_change_requires_it(): void
    {
        config()->set('consent.documents.psychotest.title', 'Changed title');
        $this->assertSame('accepted', $this->read()['consents']['psychotest']['state']);
        config()->set('consent.documents.psychotest.text', 'Changed text');
        $this->assertSame('required', $this->read()['consents']['psychotest']['state']);
        config()->set('consent.documents.dass', null);
        $this->expectExceptionMessage('CHECKOUT_SUMMARY_UNAVAILABLE');
        $this->read();
    }

    public function test_free_without_identity_remains_free_but_locked(): void
    {
        DB::table('assessment_bill_items')->delete();
        $charge = AssessmentCharge::findOrFail($this->fixture['charge']);
        $snapshot = $charge->price_snapshot;
        $snapshot['amount'] = $snapshot['baseAmount'] = 0;
        $charge->update(['amount' => 0, 'base_amount' => 0, 'price_snapshot' => $snapshot, 'free_settled_at' => $this->asOf]);
        DB::table('identity_verifications')->delete();
        $summary = $this->read();
        $this->assertSame('free', $summary['payment']['state']);
        $this->assertSame(0, $summary['payment']['amountIdr']);
        $this->assertSame('locked', $summary['access']['state']);
    }

    public function test_self_payment_does_not_expose_organization_payment_label(): void
    {
        $item = (array) DB::table('assessment_bill_items')->first();
        DB::table('assessment_bill_items')->delete();
        DB::table('assessment_participants')->update(['funding_mode' => 'COMMERCIAL_SELF_PAY']);
        DB::table('assessment_charges')->update(['payer_type' => 'self']);
        DB::table('assessment_bills')->update(['payer_type' => 'self', 'payer_participant_id' => $this->fixture['participant']]);
        DB::table('assessment_bill_items')->insert(array_replace($item, ['payer_type' => 'self', 'payer_participant_id' => $this->fixture['participant']]));
        $summary = $this->read();
        $this->assertSame('self', $summary['payment']['payer']);
        $this->assertSame('paid', $summary['payment']['state']);
        $this->assertArrayNotHasKey('organizationName', $summary['payment']);
    }

    public function test_unknown_payment_stays_recovery_required_and_cannot_start(): void
    {
        DB::table('assessment_bills')->update(['status' => 'unknown', 'paid_at' => null]);
        DB::table('assessment_bill_items')->update(['settled_at' => null]);
        $summary = $this->read();
        $this->assertSame('recovery_required', $summary['payment']['state']);
        $this->assertFalse($summary['payment']['actionAvailable']);
        $this->assertSame('locked', $summary['access']['state']);
        $this->assertFalse($summary['access']['startAvailable']);
    }

    public function test_enabled_fresh_self_action_is_exact_server_priced_and_invariant_bound(): void
    {
        config()->set('assessment_integration.checkout_session.http.payment', [
            'enabled' => true, 'writer_enabled' => true, 'max_body_bytes' => 256,
        ]);
        DB::table('assessment_entitlements')->delete();
        DB::table('assessment_bill_items')->delete();
        DB::table('assessment_charges')->delete();
        DB::table('assessment_participants')->update(['assessment_status' => 'PROVISIONED',
            'funding_mode' => 'COMMERCIAL_SELF_PAY',
            'metadata' => '{"checkout_contract_version":"checkout-v2","checkout_initial_funding_mode":"COMMERCIAL_SELF_PAY"}']);
        DB::table('branches')->update(['allowed_payer_types' => '["self"]']);
        DB::table('integration_sources')->update(['allowed_payer_types' => '["self"]', 'locked_payer_type' => 'self']);
        DB::table('packages')->update(['consultation_amount' => 50_000]);

        $summary = $this->read();

        $this->assertTrue($summary['payment']['actionAvailable']);
        $this->assertSame($summary['payment']['actionAvailable'], $summary['payment']['action'] !== null);
        $this->assertSame('/checkout/payment', $summary['payment']['action']['path']);
        $this->assertSame('select', $summary['payment']['action']['mode']);
        $this->assertSame('IDR', $summary['payment']['action']['currency']);
        $this->assertSame([false, true], array_column($summary['payment']['action']['choices'], 'consultationRequested'));
        $this->assertSame([100, 100], array_column($summary['payment']['action']['choices'], 'baseAmountIdr'));
    }

    public function test_missing_mandatory_dass_fails_closed_in_v2(): void
    {
        DB::table('assessment_entitlements')->delete();
        DB::table('package_items')->where('test_type', 'dass21')->delete();
        DB::table('assessment_bill_items')->delete();
        DB::table('assessment_charges')->delete();

        $this->expectExceptionMessage('CHECKOUT_SUMMARY_UNAVAILABLE');
        $this->read();
    }

    public function test_positive_organization_catalog_never_exposes_action_or_choice_pricing(): void
    {
        config()->set('assessment_integration.checkout_session.http.payment', [
            'enabled' => true, 'writer_enabled' => true, 'max_body_bytes' => 256,
        ]);
        DB::table('assessment_entitlements')->delete();
        DB::table('assessment_bill_items')->delete();
        DB::table('assessment_charges')->delete();
        DB::table('assessment_participants')->update(['assessment_status' => 'PROVISIONED',
            'funding_mode' => 'INVOICED_TO_ORGANIZATION',
            'metadata' => '{"checkout_contract_version":"checkout-v2","checkout_initial_funding_mode":"INVOICED_TO_ORGANIZATION"}']);
        DB::table('branches')->update(['allowed_payer_types' => '["organization"]']);
        DB::table('integration_sources')->update(['allowed_payer_types' => '["organization"]',
            'locked_payer_type' => 'organization']);

        $payment = $this->read()['payment'];

        $this->assertSame('organization', $payment['payer']);
        $this->assertNull($payment['amountIdr']);
        $this->assertFalse($payment['actionAvailable']);
        $this->assertNull($payment['action']);
        $this->assertArrayNotHasKey('choices', $payment);
    }

    public function test_zero_organization_action_requires_both_current_mandatory_consents(): void
    {
        config()->set('assessment_integration.checkout_session.http.payment', [
            'enabled' => true, 'writer_enabled' => true, 'max_body_bytes' => 256,
        ]);
        DB::table('assessment_entitlements')->delete();
        DB::table('assessment_bill_items')->delete();
        DB::table('assessment_charges')->delete();
        DB::table('packages')->update(['amount' => 0, 'consultation_amount' => 50_000]);
        DB::table('assessment_participants')->update(['assessment_status' => 'PROVISIONED',
            'funding_mode' => 'INVOICED_TO_ORGANIZATION',
            'metadata' => '{"checkout_contract_version":"checkout-v2","checkout_initial_funding_mode":"INVOICED_TO_ORGANIZATION"}']);
        DB::table('branches')->update(['allowed_payer_types' => '["organization"]']);
        DB::table('integration_sources')->update(['allowed_payer_types' => '["organization"]',
            'locked_payer_type' => 'organization']);

        $payment = $this->read()['payment'];
        $this->assertTrue($payment['actionAvailable']);
        $this->assertSame([['consultationRequested' => false, 'baseAmountIdr' => 0,
            'consultationAmountIdr' => 0, 'amountIdr' => 0]], $payment['action']['choices']);

        DB::table('consent_records')->where('consent_type', 'dass')->update(['status' => 'declined']);
        $payment = $this->read()['payment'];
        $this->assertFalse($payment['actionAvailable']);
        $this->assertNull($payment['action']);
    }

    public function test_noncanonical_empty_payment_evidence_never_becomes_a_fresh_action(): void
    {
        config()->set('assessment_integration.checkout_session.http.payment', [
            'enabled' => true, 'writer_enabled' => true, 'max_body_bytes' => 256,
        ]);
        DB::table('assessment_entitlements')->delete();
        DB::table('assessment_bill_items')->delete();
        DB::table('assessment_charges')->delete();
        DB::table('assessment_participants')->update(['assessment_status' => 'PROVISIONED',
            'funding_mode' => 'COMMERCIAL_SELF_PAY',
            'metadata' => '{"checkout_contract_version":"checkout-v2","checkout_initial_funding_mode":"COMMERCIAL_SELF_PAY"}']);
        DB::table('branches')->update(['allowed_payer_types' => '["self"]']);
        DB::table('integration_sources')->update(['allowed_payer_types' => '["self"]', 'locked_payer_type' => 'self']);
        DB::table('packages')->update(['consultation_amount' => 50_000]);

        $payment = $this->read(false)['payment'];

        $this->assertFalse($payment['actionAvailable']);
        $this->assertNull($payment['action']);
        $this->assertArrayNotHasKey('choices', $payment);
    }

    #[DataProvider('invalidConfig')]
    public function test_summary_only_presentation_requirements_reject_invalid_config(string $key, mixed $value): void
    {
        config()->set($key, $value);
        $this->expectExceptionMessage('CHECKOUT_SUMMARY_UNAVAILABLE');
        $this->read();
    }

    public static function invalidConfig(): iterable
    {
        foreach (['version', 'title', 'text'] as $field) {
            yield ['consent.documents.psychotest.'.$field, ' '];
        }
        yield ['consent.documents.psychotest', null];
        foreach ([null, 'false', 0, []] as $value) {
            yield ['consent.legal_review_pending', $value];
        }
    }

    public function test_one_frame_survives_clock_document_and_flag_drift_during_consent_query(): void
    {
        $connection = DB::connection();
        $original = $connection->getEventDispatcher();
        $events = clone $original;
        $connection->setEventDispatcher($events);
        $hit = false;
        $events->listen(QueryExecuted::class, function (QueryExecuted $event) use (&$hit): void {
            if (! $hit && str_contains($event->sql, 'from "consent_records"')) {
                $hit = true;
                $this->travelTo($this->asOf->subDay());
                config()->set('consent.documents', null);
                config()->set('consent.legal_review_pending', false);
            }
        });
        try {
            $summary = $this->read();
            $this->assertTrue($hit);
            $this->assertSame('accepted', $summary['consents']['psychotest']['state']);
            $this->assertSame('paid', $summary['payment']['state']);
            $this->assertSame('ready', $summary['access']['state']);
            $this->assertTrue($summary['consents']['legalReviewPending']);
            $this->assertNull(config('consent.documents'));
        } finally {
            $connection->setEventDispatcher($original);
            $this->travelTo($this->asOf);
        }
    }

    private function read(bool $paymentEvidenceCanonical = true): array
    {
        return app(RlsContextRunner::class)->runAsService(function () use ($paymentEvidenceCanonical): array {
            $attempt = AssessmentParticipant::with('package.items')->findOrFail($this->fixture['attempt']);
            $organization = Branch::findOrFail($this->fixture['organization']);
            $client = IntegrationClient::findOrFail($attempt->integration_client_id);
            $source = IntegrationSource::where('integration_client_id', $client->id)
                ->where('source_system', $attempt->source_system)->sole();
            $package = $attempt->getRelation('package');
            $charge = AssessmentCharge::where('assessment_participant_id', $attempt->id)->first();
            $item = $charge instanceof AssessmentCharge
                ? AssessmentBillItem::where('charge_id', $charge->id)->first() : null;
            $bill = $item instanceof AssessmentBillItem ? AssessmentBill::find($item->bill_id) : null;
            $paymentMethod = $bill instanceof AssessmentBill ? PaymentMethod::find($bill->payment_method_id) : null;
            $principal = new CheckoutSessionPrincipal(
                'CS_'.str_repeat('A', 26), 'CH_'.str_repeat('B', 26), $attempt->id,
                $attempt->assessment_attempt_id, $organization->id, $attempt->participant_id, $attempt->package_id,
                $client->id, $source->id, $source->source_system, $attempt->assessment_status,
                $attempt->funding_mode, $this->asOf, $this->asOf, $this->asOf->addHour(), $this->asOf->addHours(2),
            );
            $dto = app(CheckoutSummaryComposer::class)->compose(new CheckoutSummaryEvidence(
                $principal, $organization, $client, $source, $package, $attempt,
                Participant::findOrFail($this->fixture['participant']), $charge, $item, $bill, $paymentMethod,
                $paymentEvidenceCanonical, $this->asOf,
            ));
            $data = $dto->toArray();
            $this->assertSame($data, json_decode(json_encode($dto, JSON_THROW_ON_ERROR), true));

            return $data;
        });
    }

    private function rows(): array
    {
        $rows = [];
        foreach (['participants', 'assessment_participants', 'assessment_charges', 'assessment_bill_items', 'assessment_bills',
            'assessment_entitlements', 'consent_records', 'identity_verifications', 'identity_evidence', 'audit_logs', 'outbox_messages'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $rows;
    }
}
