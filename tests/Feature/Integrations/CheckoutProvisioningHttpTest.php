<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Actions\Integrations\ProvisionCheckoutParticipant;
use App\Http\Controllers\CheckoutParticipantProvisioningController;
use App\Http\Requests\ProvisionCheckoutParticipantRequest;
use App\Models\AssessmentParticipant;
use App\Models\Branch;
use App\Models\IntegrationClient;
use App\Models\IntegrationSource;
use App\Models\TestPackage;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;

final class CheckoutProvisioningHttpTest extends OrganizationPaymentTestCase
{
    use RefreshDatabase;

    private const string PATH = '/api/_test/checkout-provisioning';

    private const string SECRET = 'synthetic-checkout-http-secret-at-least-32-bytes';

    private IntegrationClient $client;

    private int $transactionLevel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transactionLevel = DB::transactionLevel();
        $org = Branch::create(['code' => 'HTTP', 'ref_code' => 'HTTP', 'name' => 'HTTP Test',
            'organization_code' => 'HTTP', 'display_name' => 'HTTP Test', 'allowed_payer_types' => ['self', 'organization']]);
        $this->client = IntegrationClient::create(['organization_id' => $org->id,
            'client_id' => 'http-client', 'credential_reference' => 'http-test', 'enabled' => true])->refresh();
        IntegrationSource::create(['integration_client_id' => $this->client->id,
            'source_system' => 'HTTP_SOURCE', 'contract_version' => 'checkout-v2', 'status' => 'ACTIVE',
            'allowed_assessment_packages' => ['HTTP_PACKAGE'], 'allowed_funding_modes' => ['SPONSORED'],
            'allowed_payer_types' => ['self', 'organization']]);
        $package = TestPackage::create(['code' => 'HTTP_PACKAGE', 'name' => 'HTTP Test', 'amount' => 1000,
            'currency' => 'IDR', 'is_active' => true]);
        $package->items()->create(['test_type' => 'ist', 'sort_order' => 1]);
        config()->set('assessment_integration.credentials.http-test', self::SECRET);
        config()->set('assessment_integration.checkout.enabled', true);
        // This route exists only in this test; use the installed alias and real HMAC middleware.
        Route::post(self::PATH, CheckoutParticipantProvisioningController::class)->middleware('integration.client');
    }

    protected function tearDown(): void
    {
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame($this->transactionLevel, DB::transactionLevel());
        parent::tearDown();
    }

    public function test_create_and_replay_return_only_minimal_private_projection_without_rights(): void
    {
        $created = $this->signed()->assertCreated();
        $attempt = AssessmentParticipant::sole();
        $expected = ['data' => ['participantId' => (string) $attempt->participant_id,
            'assessmentAttemptId' => $attempt->assessment_attempt_id, 'assessmentStatus' => 'PROVISIONED']];
        $created->assertExactJson($expected);
        $this->assertPrivate($created);
        $replayed = $this->signed()->assertOk()->assertExactJson($expected);
        $this->assertPrivate($replayed);
        $this->assertDatabaseCount('participants', 1);
        $this->assertDatabaseCount('assessment_participants', 1);
        $this->assertNull($attempt->participant->test_number);
        $this->assertNull($attempt->participant->registration_token);
        $this->assertNull($attempt->funding_mode);
        $this->assertSame('KAIGO', $attempt->participant->intended_field);
        foreach (['entitlements', 'assessment_entitlements', 'assessment_charges', 'assessment_bills',
            'assessment_bill_items', 'orders', 'outbox_messages', 'consent_records', 'identity_verifications'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    #[DataProvider('badAuthentication')]
    public function test_bad_authentication_never_reaches_provisioning(array $headers, string $code): void
    {
        $response = $this->signed(headers: $headers)->assertUnauthorized()->assertJsonPath('error.code', $code);
        $this->assertGenericError($response);
        $this->assertDatabaseCount('participants', 0);
        $this->assertDatabaseCount('assessment_participants', 0);
    }

    public static function badAuthentication(): iterable
    {
        yield 'missing client' => [['HTTP_X_CLIENT_ID' => ''], 'INVALID_SIGNATURE'];
        yield 'missing signature' => [['HTTP_X_SIGNATURE' => ''], 'INVALID_SIGNATURE'];
        yield 'malformed signature' => [['HTTP_X_SIGNATURE' => 'not-a-signature'], 'INVALID_SIGNATURE'];
        yield 'wrong signature' => [['HTTP_X_SIGNATURE' => str_repeat('0', 64)], 'INVALID_SIGNATURE'];
        yield 'malformed timestamp' => [['HTTP_X_TIMESTAMP' => 'yesterday'], 'INVALID_SIGNATURE'];
    }

    public function test_stale_correct_signature_is_rejected(): void
    {
        $this->signed(timestamp: (string) now()->subMinutes(10)->timestamp)
            ->assertUnauthorized()->assertJsonPath('error.code', 'STALE_REQUEST');
        $this->assertDatabaseCount('participants', 0);
    }

    public function test_signature_is_bound_to_exact_body(): void
    {
        $timestamp = (string) now()->timestamp;
        $body = json_encode($this->payload(), JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $timestamp."\n".hash('sha256', $body), self::SECRET);
        $this->signed([...$this->payload(), 'externalCandidateId' => 'TAMPERED'], ['HTTP_X_SIGNATURE' => $signature], $timestamp)
            ->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_SIGNATURE');
        $this->assertDatabaseCount('participants', 0);
    }

    public function test_disabled_client_cannot_replay_existing_attempt(): void
    {
        $this->signed()->assertCreated();
        $this->client->update(['enabled' => false]);
        $this->signed()->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_SIGNATURE');
        $this->assertDatabaseCount('assessment_participants', 1);
    }

    public function test_opt_in_off_is_generic_service_unavailable_with_no_store(): void
    {
        config()->set('assessment_integration.checkout.enabled', false);
        $response = $this->signed()->assertStatus(503)->assertJsonPath('error.code', 'CHECKOUT_NOT_ENABLED');
        $this->assertGenericError($response);
        $this->assertPrivate($response);
        $this->assertDatabaseCount('participants', 0);
    }

    #[DataProvider('invalidScope')]
    public function test_scope_mismatch_is_rejected_without_echoing_input(array $override, string $code): void
    {
        $response = $this->signed([...$this->payload(), ...$override])->assertForbidden()->assertJsonPath('error.code', $code);
        $this->assertGenericError($response);
        $this->assertPrivate($response);
        $this->assertDatabaseCount('participants', 0);
    }

    public static function invalidScope(): iterable
    {
        yield [['organizationCode' => 'FOREIGN'], 'INTEGRATION_CONTEXT_INVALID'];
        yield [['sourceSystem' => 'FOREIGN'], 'SOURCE_NOT_ALLOWED'];
        yield [['assessmentPackageCode' => 'FOREIGN'], 'PACKAGE_NOT_ALLOWED'];
    }

    public function test_missing_key_and_conflicting_key_have_minimal_private_errors(): void
    {
        $missing = $this->signed(headers: ['HTTP_IDEMPOTENCY_KEY' => ''])->assertUnprocessable()
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED');
        $this->assertPrivate($missing);
        $this->assertGenericError($missing);
        $this->signed()->assertCreated();
        $conflict = $this->signed([...$this->payload(), 'externalCandidateId' => 'CHANGED'])
            ->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $this->assertPrivate($conflict);
        $this->assertGenericError($conflict);
        $this->assertDatabaseCount('assessment_participants', 1);
    }

    public function test_existing_form_request_rejects_untrusted_metadata_before_service_writer(): void
    {
        $response = $this->signed([...$this->payload(), 'metadata' => ['paid' => true, 'checkout_initial_funding_mode' => null]])
            ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->assertGenericError($response);
        $this->assertDatabaseCount('participants', 0);
    }

    public function test_controller_cannot_create_service_context_without_authenticated_client(): void
    {
        $request = ProvisionCheckoutParticipantRequest::create('/_internal', 'POST', $this->payload());
        $response = app(CheckoutParticipantProvisioningController::class)(
            $request, app(ProvisionCheckoutParticipant::class), app(RlsContextRunner::class));
        $this->assertSame(401, $response->getStatusCode());
        $this->assertDatabaseCount('participants', 0);
    }

    public function test_controller_does_not_elevate_existing_admin_or_participant_context(): void
    {
        foreach (['super_admin', 'participant'] as $role) {
            app(RlsContextRunner::class)->run(new RlsContext($role, $this->client->organization_id, 1), function () use ($role): void {
                $request = ProvisionCheckoutParticipantRequest::create('/_internal', 'POST', $this->payload());
                $request->attributes->set('integration_client', $this->client);
                $response = app(CheckoutParticipantProvisioningController::class)(
                    $request, app(ProvisionCheckoutParticipant::class), app(RlsContextRunner::class));
                $this->assertSame(403, $response->getStatusCode());
                $this->assertSame($role, app(RlsContextRunner::class)->current()->role);
            });
        }
        $this->assertDatabaseCount('participants', 0);
    }

    public function test_unexpected_failure_rolls_back_and_restores_context_without_exposing_exception(): void
    {
        $once = true;
        DB::listen(function (QueryExecuted $query) use (&$once): void {
            if ($once && str_starts_with($query->sql, 'insert') && str_contains($query->sql, 'assessment_participants')) {
                $once = false;
                throw new RuntimeException('synthetic failure private@example.test');
            }
        });
        // Framework error renderer is exercised with APP_DEBUG=false from the isolated config.
        $response = $this->signed()->assertStatus(500);
        $this->assertStringNotContainsString('private@example.test', $response->getContent());
        $this->assertStringNotContainsString('synthetic failure', $response->getContent());
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertDatabaseCount('participants', 0);
        $this->assertDatabaseCount('assessment_participants', 0);
        $this->signed()->assertCreated();
    }

    public function test_replay_after_lifecycle_choice_returns_current_status_without_profile_or_snapshot(): void
    {
        $first = $this->signed()->assertCreated()->json('data');
        AssessmentParticipant::sole()->update(['funding_mode' => 'COMMERCIAL_SELF_PAY', 'assessment_status' => 'IN_PROGRESS']);
        $response = $this->signed()->assertOk()->assertExactJson(['data' => [...$first, 'assessmentStatus' => 'IN_PROGRESS']]);
        $this->assertPrivate($response);
        $this->assertDatabaseCount('assessment_participants', 1);
        $this->assertNull(AssessmentParticipant::sole()->metadata['checkout_initial_funding_mode']);
    }

    private function assertPrivate(TestResponse $response): void
    {
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
    }

    private function assertGenericError(TestResponse $response): void
    {
        $data = $response->json();
        $this->assertSame(['error'], array_keys($data));
        $this->assertSame(['code', 'message'], array_keys($data['error']));
        foreach (['Private Person', 'private@example.test', '628123456789', self::SECRET, 'FOREIGN'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
    }

    private function payload(): array
    {
        return ['contractVersion' => 'checkout-v2', 'sourceSystem' => 'HTTP_SOURCE', 'organizationCode' => 'HTTP',
            'externalCandidateId' => 'CANDIDATE-1', 'assessmentPackageCode' => 'HTTP_PACKAGE',
            'profile' => ['fullName' => 'Private Person', 'email' => 'private@example.test', 'phone' => '628123456789', 'intendedField' => 'KAIGO']];
    }

    private function signed(?array $input = null, array $headers = [], ?string $timestamp = null): TestResponse
    {
        $body = json_encode($input ?? $this->payload(), JSON_THROW_ON_ERROR);
        $timestamp ??= (string) now()->timestamp;

        return $this->call('POST', self::PATH, [], [], [], [...[
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CLIENT_ID' => $this->client->client_id, 'HTTP_X_TIMESTAMP' => $timestamp,
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $timestamp."\n".hash('sha256', $body), self::SECRET),
            'HTTP_IDEMPOTENCY_KEY' => 'http-provision-key',
        ], ...$headers], $body);
    }
}
