<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Data\Payments\PayerDecision;
use App\Enums\PayerType;
use App\Models\Branch;
use App\Models\IntegrationClient;
use App\Models\IntegrationSource;
use App\Models\TestPackage;
use App\Services\Payments\ResolvePayerPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\OrganizationPaymentTestCase;

final class PayerPolicyIntegrationTest extends OrganizationPaymentTestCase
{
    use RefreshDatabase;

    private Branch $organization;

    private IntegrationClient $client;

    private IntegrationSource $source;

    private TestPackage $package;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Branch::create([
            'code' => 'POLICY', 'ref_code' => 'POLICY', 'name' => 'Synthetic policy',
            'organization_code' => 'POLICY', 'display_name' => 'Synthetic policy',
            'is_active' => true, 'allowed_payer_types' => ['self', 'organization'],
        ])->refresh();
        $this->client = IntegrationClient::create([
            'organization_id' => $this->organization->id, 'client_id' => 'synthetic-policy',
            'credential_reference' => 'synthetic-not-a-secret', 'enabled' => true,
        ])->refresh();
        $this->source = IntegrationSource::create([
            'integration_client_id' => $this->client->id, 'source_system' => 'SYNTHETIC',
            'allowed_assessment_packages' => ['SYNTHETIC'], 'allowed_funding_modes' => ['SPONSORED'],
            'allowed_payer_types' => ['self', 'organization'],
        ])->refresh();
        $this->package = TestPackage::create([
            'code' => 'SYNTHETIC', 'name' => 'Synthetic package', 'is_active' => true, 'amount' => 1,
        ])->refresh();
    }

    public function test_persisted_policy_produces_a_decision_without_queries_or_model_mutation(): void
    {
        $before = $this->source->getAttributes();
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $decision = $this->resolve('organization');
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
        $this->assertSame($before, $this->source->getAttributes());
        $this->assertSame(PayerType::Organization, $decision->selectedPayerType);
        $this->assertSame($this->client->organization_id, $decision->payerOrganizationId);
        $this->assertNull($decision->rejectionReason);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('entitlements', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public function test_legacy_funding_label_never_substitutes_for_new_payer_configuration(): void
    {
        $this->source->update(['allowed_payer_types' => null]);
        $this->assertSame('PAYER_POLICY_UNCONFIGURED', $this->resolve('organization')->rejectionReason);
        $this->source->update(['allowed_payer_types' => []]);
        $this->assertSame('PAYER_NOT_ALLOWED', $this->resolve('organization')->rejectionReason);
        $this->assertSame(['SPONSORED'], $this->source->refresh()->allowed_funding_modes);
        $this->assertSame('v1', $this->source->contract_version);
        $this->assertDatabaseCount('entitlements', 0);
    }

    public function test_policy_off_rejects_new_decisions_without_changing_paid_pending_or_locked_access(): void
    {
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $this->organization->id, 'referral_branch_id' => $this->organization->id,
            'referral_source' => 'default', 'full_name' => 'Synthetic payer test',
            'gender' => 'male', 'birth_date' => '2000-01-01', 'education_level' => 'SMA_SMK',
            'intended_field' => 'KAIGO', 'phone' => '620000000000', 'source_system' => 'PAYER_POLICY_TEST',
        ]);
        $method = DB::table('payment_methods')->insertGetId([
            'code' => 'synthetic', 'display_name' => 'Synthetic', 'is_active' => false,
        ]);
        foreach (['pending', 'paid'] as $status) {
            DB::table('orders')->insert([
                'public_id' => (string) Str::ulid(), 'participant_id' => $participant,
                'payment_method_id' => $method, 'amount' => 1, 'currency' => 'IDR', 'status' => $status,
            ]);
        }
        DB::table('entitlements')->insert(['participant_id' => $participant, 'test_type' => 'ist', 'status' => 'locked']);
        $ordersBefore = DB::table('orders')->orderBy('id')->get()->toArray();
        $entitlementsBefore = DB::table('entitlements')->get()->toArray();

        $approved = $this->resolve('organization');
        $this->assertNull($approved->rejectionReason);
        $this->organization->update(['allowed_payer_types' => []]);
        $this->organization->refresh();
        $this->assertSame('PAYER_NOT_ALLOWED', $this->resolve('organization')->rejectionReason);
        $this->assertSame(PayerType::Organization, $approved->selectedPayerType);
        $this->assertEquals($ordersBefore, DB::table('orders')->orderBy('id')->get()->toArray());
        $this->assertEquals($entitlementsBefore, DB::table('entitlements')->get()->toArray());
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    private function resolve(?string $requested): PayerDecision
    {
        return app(ResolvePayerPolicy::class)->resolve(
            $this->organization, $this->client, $this->source, $this->package,
            CarbonImmutable::parse('2026-08-31 12:00:00', 'UTC'), $requested,
        );
    }
}
