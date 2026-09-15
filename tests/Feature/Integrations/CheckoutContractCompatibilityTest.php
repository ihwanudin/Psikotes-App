<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Actions\Integrations\IntegrationContractViolation;
use App\Actions\Integrations\ProvisionSelectionParticipant;
use App\Actions\Integrations\SelectionIntegrationUnavailable;
use App\Data\Payments\PayerDecision;
use App\Enums\PayerType;
use App\Http\Middleware\AuthenticateIntegrationClient;
use App\Http\Requests\ProvisionCheckoutParticipantRequest;
use App\Models\Branch;
use App\Models\IntegrationClient;
use App\Models\IntegrationSource;
use App\Models\TestPackage;
use App\Services\Integrations\CheckoutContractAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\OrganizationPaymentTestCase;

final class CheckoutContractCompatibilityTest extends OrganizationPaymentTestCase
{
    use RefreshDatabase;

    private IntegrationClient $client;

    private IntegrationSource $source;

    private TestPackage $package;

    protected function setUp(): void
    {
        parent::setUp();
        $organization = Branch::create([
            'code' => 'P5', 'ref_code' => 'P5', 'name' => 'Lembaga Uji',
            'organization_code' => 'P5', 'display_name' => 'Lembaga Uji',
            'allowed_payer_types' => ['self', 'organization'], 'allowed_funding_modes' => ['SPONSORED'],
        ]);
        $this->client = IntegrationClient::create([
            'organization_id' => $organization->id, 'client_id' => 'p5-client',
            'credential_reference' => 'synthetic-only', 'enabled' => true,
        ])->refresh();
        $this->source = IntegrationSource::create([
            'integration_client_id' => $this->client->id, 'source_system' => 'P5_SOURCE',
            'contract_version' => 'checkout-v2', 'status' => 'ACTIVE',
            'allowed_assessment_packages' => ['P5_PACKAGE'],
            'allowed_funding_modes' => ['SPONSORED'],
            'allowed_payer_types' => ['self', 'organization'],
        ])->refresh();
        $this->package = TestPackage::create([
            'code' => 'P5_PACKAGE', 'name' => 'Paket Uji', 'amount' => 123000,
            'currency' => 'IDR', 'is_active' => true,
        ]);
        $this->package->items()->createMany([
            ['test_type' => 'ist', 'sort_order' => 1],
            ['test_type' => 'dass21', 'sort_order' => 2],
        ]);
        // Test-only boundary; no route is exposed by the application in P5.
        Route::post('/_test/p5', function (ProvisionCheckoutParticipantRequest $request) {
            return response()->json(['valid' => true, 'key' => $request->idempotencyKey()]);
        })->middleware(AuthenticateIntegrationClient::class);
        config()->set('assessment_integration.credentials.synthetic-only', str_repeat('test', 10));
    }

    public function test_checkout_is_disabled_by_default(): void
    {
        $this->expectException(IntegrationContractViolation::class);
        $this->expectExceptionMessage('CHECKOUT_NOT_ENABLED');
        $this->resolve($this->payload());
    }

    public function test_explicit_payer_uses_policy_without_creating_access_or_invoice(): void
    {
        config()->set('assessment_integration.checkout.enabled', true);
        $decision = $this->resolve([...$this->payload(), 'payerType' => 'organization']);
        $this->assertSame(PayerType::Organization, $decision->selectedPayerType);
        $this->assertSame($this->client->organization_id, $decision->payerOrganizationId);
        foreach (['participants', 'assessment_participants', 'orders', 'entitlements', 'outbox_messages'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_omitted_payer_requires_choice_when_both_are_allowed(): void
    {
        config()->set('assessment_integration.checkout.enabled', true);
        $this->assertTrue($this->resolve($this->payload())->requiresSelection());
    }

    #[DataProvider('legacyModes')]
    public function test_legacy_mapping_requires_explicit_flag(string $mode, PayerType $payer): void
    {
        config()->set('assessment_integration.checkout.enabled', true);
        $input = [...$this->payload(), 'fundingMode' => $mode];
        try {
            $this->resolve($input);
            $this->fail('Implicit mapping accepted.');
        } catch (IntegrationContractViolation $exception) {
            $this->assertSame('LEGACY_FUNDING_MAPPING_DISABLED', $exception->errorCode);
        }
        config()->set('assessment_integration.checkout.allow_legacy_funding_mapping', true);
        $this->assertSame($payer, $this->resolve($input)->selectedPayerType);
    }

    public static function legacyModes(): iterable
    {
        yield ['COMMERCIAL_SELF_PAY', PayerType::SelfPay];
        yield ['INVOICED_TO_ORGANIZATION', PayerType::Organization];
    }

    #[DataProvider('invalidContractInputs')]
    public function test_invalid_contract_input_is_rejected(array $override, string $code): void
    {
        config()->set('assessment_integration.checkout.enabled', true);
        config()->set('assessment_integration.checkout.allow_legacy_funding_mapping', true);
        $this->expectException(IntegrationContractViolation::class);
        $this->expectExceptionMessage($code);
        $this->resolve([...$this->payload(), ...$override]);
    }

    public static function invalidContractInputs(): iterable
    {
        yield [['contractVersion' => 'v1'], 'CHECKOUT_CONTRACT_REQUIRED'];
        yield [['sourceSystem' => 'OTHER'], 'INTEGRATION_CONTEXT_INVALID'];
        yield [['organizationCode' => 'OTHER'], 'INTEGRATION_CONTEXT_INVALID'];
        yield [['assessmentPackageCode' => 'OTHER'], 'INTEGRATION_CONTEXT_INVALID'];
        yield [['payerType' => 'SPONSORED'], 'INVALID_PAYER_TYPE'];
        foreach (['SPONSORED', 'INTERNAL', 'WAIVED'] as $mode) {
            yield [['fundingMode' => $mode], 'LEGACY_FUNDING_NOT_SUPPORTED'];
        }
        yield [['payerType' => null, 'fundingMode' => 'COMMERCIAL_SELF_PAY'], 'AMBIGUOUS_PAYER_INPUT'];
    }

    public function test_partial_profile_is_valid_but_unknown_fields_are_rejected(): void
    {
        $this->request($this->payload())->assertOk()->assertJsonPath('key', 'p5:test');
        foreach ([['paid' => true], ['amount' => 0], ['branchId' => 1], ['profile' => ['paid' => true]], ['metadata' => ['consent' => true]], ['profile' => ['birthDate' => 'tomorrow']], ['payerType' => 'WAIVED']] as $override) {
            $this->request([...$this->payload(), ...$override])->assertUnprocessable();
        }
    }

    #[DataProvider('cutoverStatuses')]
    public function test_cutover_blocks_legacy_creation_and_replay_even_with_v1_row(string $status): void
    {
        $this->source->update(['contract_version' => 'v1']);
        $input = $this->legacyPayload();
        $path = '/api/integrations/v1/assessments/participants';
        $this->request($input, $path)->assertCreated()->assertJsonPath('data.assessmentStatus', 'READY');
        $this->request($input, $path)->assertOk();
        $marker = $this->source->replicate();
        $marker->contract_version = 'checkout-v2';
        $marker->status = $status;
        $marker->save();
        $this->request($input, $path)->assertForbidden()->assertJsonPath('error.code', 'CHECKOUT_CONTRACT_REQUIRED');
        $this->request([...$input, 'externalCandidateId' => 'NEW'], $path, 'p5:new')->assertForbidden();
        $this->assertDatabaseCount('participants', 1);
        $this->assertDatabaseCount('assessment_participants', 1);
        $this->assertDatabaseCount('entitlements', 2);
        $this->assertDatabaseCount('orders', 0);
    }

    public static function cutoverStatuses(): iterable
    {
        foreach (['ACTIVE', 'DRAFT', 'SUSPENDED', 'RETIRED'] as $status) {
            yield [$status];
        }
    }

    public function test_dedicated_selection_legacy_cannot_bypass_cutover(): void
    {
        config()->set('selection_integration.branch_ref', 'P5');
        config()->set('selection_integration.test_types', ['ist']);
        $input = ['externalCandidateId' => 'P5_CANDIDATE', 'selectionRoundId' => 'ROUND',
            'registrationId' => 'REG', 'fullName' => 'Peserta Uji', 'birthDate' => '2000-01-01',
            'gender' => 'male', 'educationLevel' => 'SMA', 'email' => 'p5@example.test', 'phone' => '628123456789'];
        $action = app(ProvisionSelectionParticipant::class);
        $action->handle($input, 'dedicated-client', 'p5:selection');
        $this->source->update(['source_system' => 'SELEKSI_BEASISWA_JEPANG']);
        foreach (['p5:selection', 'p5:selection:new'] as $key) {
            try {
                $action->handle($input, 'dedicated-client', $key);
                $this->fail('Dedicated legacy bypassed cutover.');
            } catch (SelectionIntegrationUnavailable) {
                $this->assertDatabaseCount('selection_participants', 1);
                $this->assertDatabaseCount('participants', 1);
            }
        }
    }

    private function legacyPayload(): array
    {
        $input = $this->payload();
        unset($input['contractVersion']);

        return [...$input, 'fundingMode' => 'SPONSORED', 'profile' => [
            'fullName' => 'Peserta Uji', 'birthDate' => '2000-01-01', 'gender' => 'MALE',
            'educationLevel' => 'SMA', 'phone' => '628123456789',
        ]];
    }

    public function test_cutover_marker_is_scoped_to_organization_and_source_not_client(): void
    {
        $legacy = $this->source->replicate();
        $legacy->contract_version = 'v1';
        $legacy->save();
        $otherClient = $this->client->replicate();
        $otherClient->client_id = 'p5-second-client';
        $otherClient->save();
        $this->source->update(['integration_client_id' => $otherClient->id]);
        $this->request($this->legacyPayload(), '/api/integrations/v1/assessments/participants')->assertForbidden();
        $other = Branch::create(['code' => 'OTHER', 'ref_code' => 'OTHER', 'name' => 'Other', 'organization_code' => 'OTHER', 'display_name' => 'Other']);
        $otherClient->update(['organization_id' => $other->id]);
        $this->request($this->legacyPayload(), '/api/integrations/v1/assessments/participants')->assertCreated();
    }

    public function test_request_rejects_unauthenticated_and_ambiguous_input(): void
    {
        $this->postJson('/_test/p5', $this->payload())->assertUnauthorized();
        $this->request([...$this->payload(), 'payerType' => null, 'fundingMode' => 'COMMERCIAL_SELF_PAY'])->assertUnprocessable();
        $this->request([...$this->payload(), 'profile.fullName' => 'Injected'])->assertUnprocessable();
    }

    public function test_partial_profile_accepts_only_valid_supplied_values(): void
    {
        $this->request([...$this->payload(), 'profile' => ['fullName' => 'Peserta Uji', 'phone' => null]])->assertOk();
        foreach ([null, ['fullName' => ['nested']], ['email' => 'invalid'], ['gender' => 'invalid'], ['phone' => 'abcd']] as $profile) {
            $this->request([...$this->payload(), 'profile' => $profile])->assertUnprocessable();
        }
    }

    public function test_adapter_preserves_source_lock_and_rejects_payer_switch(): void
    {
        config()->set('assessment_integration.checkout.enabled', true);
        $this->source->update(['locked_payer_type' => 'organization']);
        $this->assertSame(PayerType::Organization, $this->resolve($this->payload())->selectedPayerType);
        $this->expectExceptionMessage('PAYER_LOCKED');
        $this->resolve([...$this->payload(), 'payerType' => 'self']);
    }

    public function test_legacy_guard_rejects_missing_service_context(): void
    {
        $this->expectException(\LogicException::class);
        app(CheckoutContractAdapter::class)->assertLegacyAllowed($this->client->organization_id, 'UNMAPPED');
    }

    private function resolve(array $input): PayerDecision
    {
        return app(CheckoutContractAdapter::class)->resolve($this->client, $this->source, $this->package, $input);
    }

    private function payload(): array
    {
        return ['contractVersion' => 'checkout-v2', 'sourceSystem' => 'P5_SOURCE',
            'organizationCode' => 'P5', 'externalCandidateId' => 'CANDIDATE-1',
            'assessmentPackageCode' => 'P5_PACKAGE', 'profile' => []];
    }

    private function request(array $payload, string $path = '/_test/p5', string $key = 'p5:test'): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;

        return $this->call('POST', $path, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CLIENT_ID' => $this->client->client_id, 'HTTP_X_TIMESTAMP' => $timestamp,
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $timestamp."\n".hash('sha256', $body), str_repeat('test', 10)),
            'HTTP_IDEMPOTENCY_KEY' => $key,
        ], $body);
    }
}
