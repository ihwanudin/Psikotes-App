<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Data\Integrations\CheckoutSessionPrincipal;
use App\Models\AssessmentBill;
use App\Models\AssessmentBillItem;
use App\Models\AssessmentCharge;
use App\Models\AssessmentParticipant;
use App\Models\Branch;
use App\Models\IntegrationClient;
use App\Models\IntegrationSource;
use App\Models\PackageItem;
use App\Models\PaymentMethod;
use App\Models\TestPackage;
use App\Services\Integrations\CheckoutPaymentActionProjector;
use App\Services\Payments\AssessmentPriceSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CheckoutPaymentActionProjectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('assessment_integration.checkout_session.http.payment', [
            'enabled' => true, 'writer_enabled' => true, 'max_body_bytes' => 256,
        ]);
    }

    public function test_fresh_self_payment_projects_two_server_priced_choices_in_fixed_order(): void
    {
        $fixture = $this->fixture('COMMERCIAL_SELF_PAY', 99_000, 50_000);

        DB::enableQueryLog();
        $action = app(CheckoutPaymentActionProjector::class)->project(...$fixture);

        $this->assertSame([
            'path' => '/checkout/payment', 'mode' => 'select', 'currency' => 'IDR',
            'choices' => [
                ['consultationRequested' => false, 'baseAmountIdr' => 99_000,
                    'consultationAmountIdr' => 0, 'amountIdr' => 99_000],
                ['consultationRequested' => true, 'baseAmountIdr' => 99_000,
                    'consultationAmountIdr' => 50_000, 'amountIdr' => 149_000],
            ],
        ], $action?->toArray());
        $this->assertSame([], DB::getQueryLog());

        $partialEvidence = $fixture;
        $partialEvidence[6] = $this->model(new AssessmentCharge, ['id' => 53]);
        $this->assertNull(app(CheckoutPaymentActionProjector::class)->project(...$partialEvidence));
        $this->assertSame([], DB::getQueryLog());

        $collapsedCorruptEvidence = $fixture;
        $collapsedCorruptEvidence[10] = false;
        $this->assertNull(app(CheckoutPaymentActionProjector::class)->project(...$collapsedCorruptEvidence));
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_disabled_or_malformed_switches_and_invalid_consultation_fail_closed(): void
    {
        $fixture = $this->fixture('COMMERCIAL_SELF_PAY', 99_000, 50_000);
        foreach ([
            ['enabled' => false, 'writer_enabled' => true, 'max_body_bytes' => 256],
            ['enabled' => true, 'writer_enabled' => 'true', 'max_body_bytes' => 256],
        ] as $config) {
            config()->set('assessment_integration.checkout_session.http.payment', $config);
            $this->assertNull(app(CheckoutPaymentActionProjector::class)->project(...$fixture));
        }

        config()->set('assessment_integration.checkout_session.http.payment', [
            'enabled' => true, 'writer_enabled' => true, 'max_body_bytes' => 256,
        ]);
        $fixture = $this->fixture('COMMERCIAL_SELF_PAY', 99_000, null);
        $this->assertNull(app(CheckoutPaymentActionProjector::class)->project(...$fixture));

        $unsafeInteger = $this->fixture('COMMERCIAL_SELF_PAY', 9_007_199_254_740_992, 1);
        $this->assertNull(app(CheckoutPaymentActionProjector::class)->project(...$unsafeInteger));
    }

    public function test_organization_positive_is_hidden_but_exact_zero_requires_both_current_consents(): void
    {
        $positive = $this->fixture('INVOICED_TO_ORGANIZATION', 99_000, 50_000);
        $this->assertNull(app(CheckoutPaymentActionProjector::class)->project(...$positive));

        $zeroWithoutConsent = $this->fixture('INVOICED_TO_ORGANIZATION', 0, 50_000, false, true);
        $this->assertNull(app(CheckoutPaymentActionProjector::class)->project(...$zeroWithoutConsent));

        $zero = $this->fixture('INVOICED_TO_ORGANIZATION', 0, 50_000, true, true);
        $this->assertSame([
            'path' => '/checkout/payment', 'mode' => 'select', 'currency' => 'IDR',
            'choices' => [['consultationRequested' => false, 'baseAmountIdr' => 0,
                'consultationAmountIdr' => 0, 'amountIdr' => 0]],
        ], app(CheckoutPaymentActionProjector::class)->project(...$zero)?->toArray());
    }

    public function test_existing_canonical_pending_self_bill_projects_one_immutable_continue_choice(): void
    {
        $fixture = $this->fixture('COMMERCIAL_SELF_PAY', 99_000, 50_000);
        [$principal, $organization, $client, $source, $package, $attempt] = $fixture;
        $snapshot = app(AssessmentPriceSnapshot::class)->capture($package, true);
        $policy = ['organizationId' => 11, 'integrationClientId' => 13, 'sourceId' => 17,
            'contractVersion' => 'checkout-v2', 'allowedPayerTypes' => ['self'], 'payerType' => 'self',
            'lockedPayerType' => 'self'];
        $charge = $this->model(new AssessmentCharge, ['id' => 23, 'assessment_participant_id' => 19,
            'organization_id' => 11, 'participant_id' => 21, 'package_id' => 29, 'payer_type' => 'self',
            'base_amount' => 99_000, 'consultation_amount' => 50_000, 'consultation_requested' => true,
            'amount' => 149_000, 'currency' => 'IDR', 'price_snapshot' => $snapshot,
            'policy_snapshot' => $policy, 'free_settled_at' => null]);
        $bill = $this->model(new AssessmentBill, ['id' => 31, 'organization_id' => 11, 'payer_type' => 'self',
            'payer_participant_id' => 21, 'amount' => 149_000, 'currency' => 'IDR', 'item_count' => 1,
            'payment_method_id' => 47,
            'status' => 'pending', 'invoice_url' => 'https://checkout.example/invoice', 'gateway_ref' => 'gateway-1',
            'public_reference' => 'AB_'.str_repeat('0', 26), 'selection_hash' => str_repeat('a', 64),
            'request_hash' => str_repeat('b', 64), 'idempotency_key' => 'checkout-self-v1:attempt-1',
            'expires_at' => CarbonImmutable::parse('2026-09-05T01:00:00Z'), 'paid_at' => null,
            'proof_object_key' => null, 'proof_checksum_sha256' => null, 'proof_mime_type' => null,
            'proof_size_bytes' => null, 'proof_uploaded_at' => null, 'verified_at' => null,
            'verified_by_admin_id' => null, 'rejection_reason' => null]);
        $item = $this->model(new AssessmentBillItem, ['id' => 37, 'bill_id' => 31, 'charge_id' => 23,
            'organization_id' => 11, 'participant_id' => 21, 'payer_type' => 'self',
            'payer_participant_id' => 21, 'amount' => 149_000, 'currency' => 'IDR', 'settled_at' => null]);
        $method = $this->model(new PaymentMethod, ['id' => 47, 'code' => 'xendit', 'is_active' => false]);

        $action = app(CheckoutPaymentActionProjector::class)->project($principal, $organization, $client,
            $source, $package, $attempt, $charge, $item, $bill, $method,
            true, CarbonImmutable::parse('2026-09-05T00:00:00Z'), true, true);

        $this->assertSame('continue', $action->mode);
        $this->assertSame([['consultationRequested' => true, 'baseAmountIdr' => 99_000,
            'consultationAmountIdr' => 50_000, 'amountIdr' => 149_000]], $action->choices);

        $this->assertNull(app(CheckoutPaymentActionProjector::class)->project($principal, $organization, $client,
            $source, $package, $attempt, $charge, $item, $bill, null,
            true, CarbonImmutable::parse('2026-09-05T00:00:00Z'), true, true));
        $wrongMethod = $this->model(new PaymentMethod, ['id' => 48, 'code' => 'xendit', 'is_active' => true]);
        $this->assertNull(app(CheckoutPaymentActionProjector::class)->project($principal, $organization, $client,
            $source, $package, $attempt, $charge, $item, $bill, $wrongMethod,
            true, CarbonImmutable::parse('2026-09-05T00:00:00Z'), true, true));
        $method->code = 'manual_transfer';
        $this->assertNull(app(CheckoutPaymentActionProjector::class)->project($principal, $organization, $client,
            $source, $package, $attempt, $charge, $item, $bill, $method,
            true, CarbonImmutable::parse('2026-09-05T00:00:00Z'), true, true));
        $method->syncOriginal();
        $this->assertNull(app(CheckoutPaymentActionProjector::class)->project($principal, $organization, $client,
            $source, $package, $attempt, $charge, $item, $bill, $method,
            true, CarbonImmutable::parse('2026-09-05T00:00:00Z'), true, true));
        $method->code = 'xendit';
        $method->syncOriginal();

        $package->amount = 100_000;
        $this->assertNull(app(CheckoutPaymentActionProjector::class)->project($principal, $organization, $client,
            $source, $package, $attempt, $charge, $item, $bill, $method,
            true, CarbonImmutable::parse('2026-09-05T00:00:00Z'), true, true));
        $package->amount = 99_000;
        $bill->status = 'unknown';
        $this->assertNull(app(CheckoutPaymentActionProjector::class)->project($principal, $organization, $client,
            $source, $package, $attempt, $charge, $item, $bill, $method,
            true, CarbonImmutable::parse('2026-09-05T00:00:00Z'), true, true));
    }

    /** @return array<int, mixed> */
    private function fixture(string $funding, ?int $base, ?int $consultation,
        bool $psychotestConsent = true, bool $dassConsent = true): array
    {
        $at = CarbonImmutable::parse('2026-09-05T00:00:00Z');
        $principal = new CheckoutSessionPrincipal('CS_'.str_repeat('A', 26), 'CH_'.str_repeat('B', 26),
            19, 'attempt-1', 11, 21, 29, 13, 17, 'source', 'PROVISIONED', $funding,
            $at, $at, $at->addHour(), $at->addHours(2));
        $organization = $this->model(new Branch, ['id' => 11, 'status' => 'ACTIVE', 'is_active' => true,
            'allowed_payer_types' => [$funding === 'COMMERCIAL_SELF_PAY' ? 'self' : 'organization']]);
        $client = $this->model(new IntegrationClient, ['id' => 13, 'organization_id' => 11, 'enabled' => true,
            'effective_from' => $at->subDay(), 'effective_until' => $at->addDay()]);
        $source = $this->model(new IntegrationSource, ['id' => 17, 'integration_client_id' => 13,
            'source_system' => 'source', 'contract_version' => 'checkout-v2', 'status' => 'ACTIVE',
            'effective_from' => $at->subDay(), 'effective_until' => $at->addDay(),
            'allowed_assessment_packages' => ['PKG'],
            'allowed_payer_types' => [$funding === 'COMMERCIAL_SELF_PAY' ? 'self' : 'organization'],
            'locked_payer_type' => $funding === 'COMMERCIAL_SELF_PAY' ? 'self' : 'organization']);
        $package = $this->model(new TestPackage, ['id' => 29, 'code' => 'PKG', 'name' => 'Package',
            'amount' => $base, 'consultation_amount' => $consultation, 'currency' => 'IDR', 'is_active' => true]);
        $package->setRelation('items', new Collection([
            $this->model(new PackageItem, ['id' => 41, 'package_id' => 29, 'test_type' => 'dass21']),
            $this->model(new PackageItem, ['id' => 43, 'package_id' => 29, 'test_type' => 'ist']),
        ]));
        $attempt = $this->model(new AssessmentParticipant, ['id' => 19, 'organization_id' => 11,
            'participant_id' => 21, 'package_id' => 29, 'integration_client_id' => 13,
            'source_system' => 'source', 'assessment_attempt_id' => 'attempt-1', 'assessment_status' => 'PROVISIONED',
            'funding_mode' => $funding, 'metadata' => ['checkout_contract_version' => 'checkout-v2',
                'checkout_initial_funding_mode' => $funding]]);

        return [$principal, $organization, $client, $source, $package, $attempt, null, null, null, null, true, $at,
            $psychotestConsent, $dassConsent];
    }

    /**
     * @template T of Model
     *
     * @param  T  $model
     * @param  array<string, mixed>  $attributes
     * @return T
     */
    private function model(Model $model, array $attributes): Model
    {
        $model->forceFill($attributes);
        $model->exists = true;
        $model->syncOriginal();

        return $model;
    }
}
