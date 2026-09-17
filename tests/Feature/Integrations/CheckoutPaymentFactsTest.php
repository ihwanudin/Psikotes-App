<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Data\Integrations\CheckoutPaymentFacts;
use App\Enums\PayerType;
use App\Models\AssessmentCharge;
use App\Models\AssessmentParticipant;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Integrations\CheckoutPaymentFactsReader;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentAccessFixture;

final class CheckoutPaymentFactsTest extends OrganizationPaymentTestCase
{
    use RefreshDatabase;

    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->fixture = AssessmentAccessFixture::create();
        $this->funding('INVOICED_TO_ORGANIZATION');
    }

    public function test_collective_ten_projects_only_own_frozen_amount_without_business_writes(): void
    {
        for ($i = 1; $i < 10; $i++) {
            $other = AssessmentAccessFixture::create(identity: ['organization' => $this->fixture['organization']]);
            DB::table('assessment_bill_items')->where('id', $other['item'])->update(['bill_id' => $this->fixture['bill']]);
        }
        DB::table('assessment_bills')->where('id', $this->fixture['bill'])->update([
            'amount' => 1000, 'item_count' => 10, 'invoice_url' => 'https://synthetic.invalid/PRIVATE_INVOICE',
            'gateway_ref' => 'PRIVATE_GATEWAY', 'proof_object_key' => 'PRIVATE_PROOF',
        ]);
        $before = $this->businessRows();
        $facts = $this->read();
        $this->assertSame(['payment' => ['payer' => 'organization', 'state' => 'paid',
            'amountIdr' => 100, 'amountSource' => 'charge_snapshot', 'consultationRequested' => false,
            'actionAvailable' => false]], $facts->toArray());
        $this->assertSame($facts->toArray(), json_decode(json_encode($facts, JSON_THROW_ON_ERROR), true));
        $this->assertSame($before, $this->businessRows());
        $this->assertSame('paid', $facts->state);
    }

    public function test_catalog_policy_and_prerequisites_do_not_rewrite_historical_payment(): void
    {
        DB::table('packages')->where('id', $this->fixture['package'])->update([
            'name' => 'CHANGED_CATALOG', 'amount' => 999, 'consultation_amount' => 700,
        ]);
        DB::table('branches')->update(['allowed_payer_types' => '[]']);
        DB::table('integration_clients')->update(['enabled' => false]);
        DB::table('consent_records')->delete();
        DB::table('identity_verifications')->delete();
        DB::table('assessment_entitlements')->update(['status' => 'locked', 'ready_at' => null]);
        DB::table('participants')->update(['full_name' => null]);
        $before = $this->businessRows();
        $facts = $this->read();
        $this->assertSame('paid', $facts->state);
        $this->assertSame(100, $facts->amountIdr);
        $this->assertSame($before, $this->businessRows());
    }

    #[DataProvider('billStates')]
    public function test_bill_states_never_authorize_reinvoice(string $status, string $expected): void
    {
        DB::table('assessment_bill_items')->update(['settled_at' => null]);
        DB::table('assessment_bills')->update(['status' => $status, 'paid_at' => null]);
        $facts = $this->read();
        $this->assertSame($expected, $facts->state);
        $this->assertSame(100, $facts->amountIdr);
        $this->assertFalse($facts->actionAvailable);
    }

    public static function billStates(): iterable
    {
        yield ['reserved', 'preparing'];
        yield ['issuing', 'preparing'];
        yield ['pending', 'pending'];
        yield ['unknown', 'recovery_required'];
        yield ['expired', 'expired'];
        yield ['rejected', 'rejected'];
    }

    public function test_absent_charge_is_unavailable_amount_not_a_catalog_price_or_consultation_choice(): void
    {
        DB::table('assessment_entitlements')->delete();
        DB::table('assessment_bill_items')->delete();
        DB::table('assessment_charges')->delete();
        DB::table('assessment_participants')->update(['assessment_status' => 'PROVISIONED']);
        foreach ([null => 'unselected', 'COMMERCIAL_SELF_PAY' => 'unpaid', 'INVOICED_TO_ORGANIZATION' => 'unbilled'] as $funding => $state) {
            $this->funding($funding === '' ? null : $funding, $funding === '' ? null : $funding);
            $facts = $this->read();
            $this->assertSame($state, $facts->state);
            $this->assertNull($facts->amountIdr);
            $this->assertNull($facts->consultationRequested);
            $this->assertSame('unavailable', $facts->amountSource);
            $this->assertFalse($facts->actionAvailable);
        }
    }

    public function test_zero_without_marker_is_unsettled_but_valid_free_marker_is_free(): void
    {
        DB::table('assessment_bill_items')->delete();
        $this->amount(0);
        $this->assertSame('unbilled', $this->read()->state);
        AssessmentCharge::findOrFail($this->fixture['charge'])->update(['free_settled_at' => now()]);
        $facts = $this->read();
        $this->assertSame('free', $facts->state);
        $this->assertSame(0, $facts->amountIdr);
        $this->assertSame(PayerType::Organization, $facts->payer);
    }

    public function test_self_payer_uses_own_allocation_and_initial_null_allows_persisted_selection(): void
    {
        DB::table('assessment_bill_items')->delete();
        $this->funding('COMMERCIAL_SELF_PAY', null);
        DB::table('assessment_charges')->update(['payer_type' => 'self']);
        DB::table('assessment_bills')->update(['payer_type' => 'self', 'payer_participant_id' => $this->fixture['participant']]);
        DB::table('assessment_bill_items')->insert([
            'bill_id' => $this->fixture['bill'], 'charge_id' => $this->fixture['charge'],
            'organization_id' => $this->fixture['organization'], 'participant_id' => $this->fixture['participant'],
            'payer_type' => 'self', 'payer_participant_id' => $this->fixture['participant'],
            'amount' => 100, 'currency' => 'IDR', 'settled_at' => now(),
        ]);
        $this->assertSame(PayerType::SelfPay, $this->read()->payer);
        $this->assertSame('paid', $this->read()->state);
        DB::table('assessment_bill_items')->delete();
        $this->assertSame('unpaid', $this->read()->state);
    }

    #[DataProvider('corruptEvidence')]
    public function test_corrupt_or_future_evidence_fails_closed(string $table, array $values): void
    {
        DB::table($table)->update($values);
        $before = $this->businessRows();
        try {
            $this->read();
            $this->fail('Corrupt payment evidence projected.');
        } catch (DomainException $exception) {
            $this->assertSame('CHECKOUT_PAYMENT_UNAVAILABLE', $exception->getMessage());
        }
        $this->assertSame($before, $this->businessRows());
    }

    public static function corruptEvidence(): iterable
    {
        yield 'missing initial' => ['assessment_participants', ['metadata' => '{"checkout_contract_version":"checkout-v2"}']];
        yield 'wrong version' => ['assessment_participants', ['metadata' => '{"checkout_contract_version":"v1","checkout_initial_funding_mode":null}']];
        yield 'initial mismatch' => ['assessment_participants', ['metadata' => '{"checkout_contract_version":"checkout-v2","checkout_initial_funding_mode":"COMMERCIAL_SELF_PAY"}']];
        yield 'funding mismatch' => ['assessment_participants', ['funding_mode' => 'COMMERCIAL_SELF_PAY']];
        yield 'snapshot unknown' => ['assessment_charges', ['price_snapshot' => '{}']];
        yield 'snapshot mismatch' => ['assessment_charges', ['base_amount' => 101]];
        yield 'snapshot floating amount' => ['assessment_charges', ['price_snapshot' => '{"amount":100.5}']];
        yield 'paid future' => ['assessment_bills', ['paid_at' => '2099-01-01']];
        yield 'allocation future' => ['assessment_bill_items', ['settled_at' => '2099-01-01']];
        yield 'allocation missing' => ['assessment_bill_items', ['settled_at' => null]];
        yield 'total mismatch' => ['assessment_bills', ['amount' => 101]];
        yield 'count mismatch' => ['assessment_bills', ['item_count' => 2]];
        yield 'unknown with paid time' => ['assessment_bills', ['status' => 'unknown']];
        yield 'unrecognized state' => ['assessment_bills', ['status' => 'INVALID']];
        yield 'positive free marker' => ['assessment_charges', ['free_settled_at' => '2020-01-01']];
    }

    public function test_free_future_is_not_free(): void
    {
        DB::table('assessment_bill_items')->delete();
        $this->amount(0);
        AssessmentCharge::findOrFail($this->fixture['charge'])->update(['free_settled_at' => now()->addDay()]);
        $this->expectExceptionMessage('CHECKOUT_PAYMENT_UNAVAILABLE');
        $this->read();
    }

    public function test_consultation_is_taken_only_from_valid_snapshot(): void
    {
        DB::table('assessment_bill_items')->delete();
        $charge = AssessmentCharge::findOrFail($this->fixture['charge']);
        $snapshot = $charge->price_snapshot;
        $snapshot['consultationRequested'] = true;
        $snapshot['consultationAmount'] = 35;
        $snapshot['amount'] = 135;
        $charge->update(['consultation_requested' => true, 'consultation_amount' => 35, 'amount' => 135, 'price_snapshot' => $snapshot]);
        $facts = $this->read();
        $this->assertTrue($facts->consultationRequested);
        $this->assertSame(135, $facts->amountIdr);
    }

    public function test_foreign_or_same_person_other_attempt_charge_is_never_a_fallback(): void
    {
        $other = AssessmentAccessFixture::create(identity: ['organization' => $this->fixture['organization'],
            'participant' => $this->fixture['participant']]);
        DB::table('assessment_entitlements')
            ->where('assessment_participant_id', $this->fixture['attempt'])->delete();
        DB::table('assessment_bill_items')->where('id', $this->fixture['item'])->delete();
        DB::table('assessment_charges')->where('id', $this->fixture['charge'])->delete();
        $facts = $this->read();
        $this->assertSame('unbilled', $facts->state);
        $this->assertNull($facts->amountIdr);
        $this->assertDatabaseHas('assessment_charges', ['id' => $other['charge'], 'amount' => 100]);
    }

    public function test_supplied_graph_scope_mismatch_is_rejected_not_hidden_as_missing_charge(): void
    {
        $other = AssessmentAccessFixture::create();
        foreach (['organization_id' => $other['organization'], 'participant_id' => $other['participant'],
            'package_id' => $other['package']] as $key => $value) {
            app(RlsContextRunner::class)->runAsService(function () use ($key, $value): void {
                $attempt = AssessmentParticipant::findOrFail($this->fixture['attempt']);
                $attempt->setAttribute($key, $value);
                try {
                    app(CheckoutPaymentFactsReader::class)->project($attempt);
                    $this->fail('Mismatched internal graph projected.');
                } catch (DomainException $exception) {
                    $this->assertSame('CHECKOUT_PAYMENT_UNAVAILABLE', $exception->getMessage());
                }
            });
        }
    }

    public function test_mismatched_self_allocation_payer_is_rejected_even_when_parent_matches_it(): void
    {
        $other = AssessmentAccessFixture::create(identity: ['organization' => $this->fixture['organization']]);
        DB::table('assessment_bill_items')->where('id', $this->fixture['item'])->delete();
        $this->funding('COMMERCIAL_SELF_PAY', null);
        DB::table('assessment_charges')->where('id', $this->fixture['charge'])->update(['payer_type' => 'self']);
        DB::table('assessment_bills')->where('id', $this->fixture['bill'])->update([
            'payer_type' => 'self', 'payer_participant_id' => $other['participant']]);
        DB::table('assessment_bill_items')->insert([
            'bill_id' => $this->fixture['bill'], 'charge_id' => $this->fixture['charge'],
            'organization_id' => $this->fixture['organization'], 'participant_id' => $this->fixture['participant'],
            'payer_type' => 'self', 'payer_participant_id' => $other['participant'], 'amount' => 100,
            'currency' => 'IDR', 'settled_at' => now(),
        ]);
        $this->expectExceptionMessage('CHECKOUT_PAYMENT_UNAVAILABLE');
        $this->read();
    }

    public function test_zero_with_allocation_is_not_free(): void
    {
        DB::table('assessment_bill_items')->delete();
        $this->amount(0);
        AssessmentCharge::findOrFail($this->fixture['charge'])->update(['free_settled_at' => now()]);
        DB::table('assessment_bill_items')->insert([
            'bill_id' => $this->fixture['bill'], 'charge_id' => $this->fixture['charge'],
            'organization_id' => $this->fixture['organization'], 'participant_id' => $this->fixture['participant'],
            'payer_type' => 'organization', 'amount' => 0, 'currency' => 'IDR',
        ]);
        $this->expectExceptionMessage('CHECKOUT_PAYMENT_UNAVAILABLE');
        $this->read();
    }

    public function test_transport_range_rejects_overflow_without_rounding_and_accepts_exact_limit(): void
    {
        DB::table('assessment_bill_items')->delete();
        $this->amount(9007199254740991);
        $this->assertSame(9007199254740991, $this->read()->amountIdr);
        $this->amount(9007199254740992);
        $this->expectExceptionMessage('CHECKOUT_PAYMENT_UNAVAILABLE');
        $this->read();
    }

    public function test_reader_does_not_elevate_missing_or_other_role_context(): void
    {
        foreach ([null, new RlsContext('super_admin'), new RlsContext('participant', $this->fixture['organization'], $this->fixture['participant'])] as $role) {
            $call = fn () => app(CheckoutPaymentFactsReader::class)->project(AssessmentParticipant::findOrFail($this->fixture['attempt']));
            try {
                $role === null ? $call() : app(RlsContextRunner::class)->run($role, $call);
                $this->fail('Reader elevated an unauthorized context.');
            } catch (LogicException $exception) {
                $this->assertSame('Checkout payment projection requires its validated service transaction.', $exception->getMessage());
            }
        }
    }

    private function read(): CheckoutPaymentFacts
    {
        return app(RlsContextRunner::class)->runAsService(fn () => app(CheckoutPaymentFactsReader::class)
            ->project(AssessmentParticipant::findOrFail($this->fixture['attempt'])));
    }

    private function funding(?string $funding, ?string $initial = 'INVOICED_TO_ORGANIZATION'): void
    {
        DB::table('assessment_participants')->where('id', $this->fixture['attempt'])->update([
            'funding_mode' => $funding, 'metadata' => json_encode(['checkout_contract_version' => 'checkout-v2',
                'checkout_initial_funding_mode' => $funding === null ? null : $initial], JSON_THROW_ON_ERROR),
        ]);
    }

    private function amount(int $amount): void
    {
        $charge = AssessmentCharge::findOrFail($this->fixture['charge']);
        $snapshot = $charge->price_snapshot;
        $snapshot['baseAmount'] = $snapshot['amount'] = $amount;
        $charge->update(['amount' => $amount, 'base_amount' => $amount, 'price_snapshot' => $snapshot]);
    }

    private function businessRows(): array
    {
        $rows = [];
        foreach (['participants', 'assessment_participants', 'assessment_charges', 'assessment_bills',
            'assessment_bill_items', 'assessment_entitlements', 'consent_records', 'outbox_messages', 'audit_logs'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $rows;
    }
}
