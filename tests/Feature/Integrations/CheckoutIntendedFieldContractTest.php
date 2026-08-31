<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Actions\Integrations\IntegrationContractViolation;
use App\Http\Middleware\AuthenticateIntegrationClient;
use App\Http\Requests\ProvisionAssessmentParticipantRequest;
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

final class CheckoutIntendedFieldContractTest extends OrganizationPaymentTestCase
{
    use RefreshDatabase;

    private IntegrationClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $organization = Branch::create([
            'code' => 'FIELD', 'ref_code' => 'FIELD', 'name' => 'Field Contract',
            'organization_code' => 'FIELD', 'display_name' => 'Field Contract',
            'allowed_payer_types' => ['self', 'organization'],
        ]);
        $this->client = IntegrationClient::create([
            'organization_id' => $organization->id, 'client_id' => 'field-client',
            'credential_reference' => 'field-test', 'enabled' => true,
        ])->refresh();
        config()->set('assessment_integration.credentials.field-test', str_repeat('test', 10));
        // Validation-only test routes: no production route or writer is activated.
        Route::post('/_test/field-v2', fn (ProvisionCheckoutParticipantRequest $request) => response()->json($request->validated()))
            ->middleware(AuthenticateIntegrationClient::class);
        Route::post('/_test/field-v1', fn (ProvisionAssessmentParticipantRequest $request) => response()->json($request->validated()))
            ->middleware(AuthenticateIntegrationClient::class);
    }

    #[DataProvider('validFields')]
    public function test_each_supported_field_is_preserved(string $field): void
    {
        $this->request($this->payload(['intendedField' => $field]))
            ->assertOk()->assertJsonPath('profile.intendedField', $field);
    }

    public static function validFields(): iterable
    {
        foreach (['KAIGO', 'KENSETSU', 'NOUGYOU', 'SEIZOU', 'GAISHOKU', 'UMUM'] as $field) {
            yield $field => [$field];
        }
    }

    public function test_missing_and_null_field_do_not_default_to_umum(): void
    {
        $this->request($this->payload())->assertOk()->assertExactJson($this->payload());
        $input = $this->payload(['intendedField' => null]);
        $this->request($input)->assertOk()->assertExactJson($input);
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_field_types_and_values_are_rejected(mixed $field): void
    {
        $this->request($this->payload(['intendedField' => $field]))
            ->assertUnprocessable()->assertJsonValidationErrors('profile.intendedField');
    }

    public static function invalidFields(): iterable
    {
        yield 'unknown' => ['UNKNOWN'];
        yield 'lowercase' => ['kaigo'];
        yield 'integer' => [1];
        yield 'float' => [1.5];
        yield 'boolean true' => [true];
        yield 'boolean false' => [false];
        yield 'list' => [['KAIGO']];
        yield 'object' => [(object) ['value' => 'KAIGO']];
    }

    public function test_allowed_field_does_not_allow_other_profile_keys(): void
    {
        foreach (['unknown', 'paid', 'identityVerified', 'consent'] as $key) {
            $this->request($this->payload(['intendedField' => 'KAIGO', $key => true]))
                ->assertUnprocessable()->assertJsonValidationErrors('profile');
        }
    }

    public function test_field_cannot_bypass_authentication_or_persisted_client_disable(): void
    {
        $input = $this->payload(['intendedField' => 'KAIGO']);
        $this->postJson('/_test/field-v2', $input)->assertUnauthorized();
        $this->request($input, signature: str_repeat('0', 64))->assertUnauthorized();
        $this->client->update(['enabled' => false]);
        $this->request($input)->assertUnauthorized();
    }

    public function test_field_does_not_relax_payer_validation(): void
    {
        $input = $this->payload(['intendedField' => 'KAIGO']);
        $this->request([...$input, 'payerType' => 'WAIVED'])
            ->assertUnprocessable()->assertJsonValidationErrors('payerType');
        $this->request([...$input, 'payerType' => null, 'fundingMode' => 'COMMERCIAL_SELF_PAY'])
            ->assertUnprocessable()->assertJsonValidationErrors('payerType');
    }

    #[DataProvider('policyCases')]
    public function test_validated_field_cannot_bypass_checkout_or_payer_gate(bool $enabled, string $expected): void
    {
        $source = IntegrationSource::create([
            'integration_client_id' => $this->client->id, 'source_system' => 'FIELD_SOURCE',
            'contract_version' => 'checkout-v2', 'status' => 'ACTIVE',
            'allowed_assessment_packages' => ['FIELD_PACKAGE'],
            'allowed_funding_modes' => ['SPONSORED'],
            'allowed_payer_types' => ['organization'], 'locked_payer_type' => 'organization',
        ])->refresh();
        $package = TestPackage::create([
            'code' => 'FIELD_PACKAGE', 'name' => 'Field Package',
            'amount' => 123000, 'currency' => 'IDR', 'is_active' => true,
        ]);
        $package->items()->create(['test_type' => 'ist', 'sort_order' => 1]);
        $input = $this->request([...$this->payload(['intendedField' => 'KAIGO']), 'payerType' => 'self'])
            ->assertOk()->json();
        config()->set('assessment_integration.checkout.enabled', $enabled);

        $this->expectException(IntegrationContractViolation::class);
        $this->expectExceptionMessage($expected);
        app(CheckoutContractAdapter::class)->resolve($this->client->fresh(), $source, $package, $input);
    }

    public static function policyCases(): iterable
    {
        yield 'checkout disabled' => [false, 'CHECKOUT_NOT_ENABLED'];
        yield 'payer locked' => [true, 'PAYER_LOCKED'];
    }

    public function test_v1_keeps_complete_profile_contract_and_rejects_intended_field(): void
    {
        $input = $this->payload([
            'fullName' => 'Peserta Uji', 'birthDate' => '2000-01-01', 'gender' => 'MALE',
            'educationLevel' => 'SMA', 'phone' => '628123456789',
        ]);
        unset($input['contractVersion']);
        $input['fundingMode'] = 'SPONSORED';
        $this->request($input, '/_test/field-v1')->assertOk()->assertExactJson($input);
        foreach (['KAIGO', null] as $field) {
            $withField = [...$input, 'profile' => [...$input['profile'], 'intendedField' => $field]];
            $this->request($withField, '/_test/field-v1')
                ->assertUnprocessable()->assertJsonValidationErrors('_schema');
        }
        $this->request([...$input, 'profile' => []], '/_test/field-v1')->assertUnprocessable()
            ->assertJsonValidationErrors(['profile.fullName', 'profile.birthDate', 'profile.gender', 'profile.educationLevel', 'profile.phone']);
    }

    private function payload(array $profile = []): array
    {
        return ['contractVersion' => 'checkout-v2', 'sourceSystem' => 'FIELD_SOURCE',
            'organizationCode' => 'FIELD', 'externalCandidateId' => 'CANDIDATE-1',
            'assessmentPackageCode' => 'FIELD_PACKAGE', 'profile' => $profile];
    }

    private function request(array $payload, string $path = '/_test/field-v2', ?string $signature = null): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;

        return $this->call('POST', $path, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CLIENT_ID' => $this->client->client_id, 'HTTP_X_TIMESTAMP' => $timestamp,
            'HTTP_X_SIGNATURE' => $signature ?? hash_hmac('sha256', $timestamp."\n".hash('sha256', $body), str_repeat('test', 10)),
        ], $body);
    }
}
