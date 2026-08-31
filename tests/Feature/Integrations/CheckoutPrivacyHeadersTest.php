<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Http\Controllers\CheckoutParticipantProvisioningController;
use App\Http\Middleware\PreventCheckoutResponseCaching;
use App\Models\AssessmentParticipant;
use App\Models\Branch;
use App\Models\IntegrationClient;
use App\Models\IntegrationSource;
use App\Models\TestPackage;
use App\Security\RlsContextRunner;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;

final class CheckoutPrivacyHeadersTest extends OrganizationPaymentTestCase
{
    use RefreshDatabase;

    private const string PATH = '/api/_test/private-checkout';

    private const string CONTROL = '/api/_test/control-checkout';

    private const string SECRET = 'synthetic-private-checkout-hmac-secret-32-bytes';

    private IntegrationClient $client;

    private int $transactionLevel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transactionLevel = DB::transactionLevel();
        $org = Branch::create(['code' => 'PRIVATE', 'ref_code' => 'PRIVATE', 'name' => 'Synthetic Privacy',
            'organization_code' => 'PRIVATE', 'display_name' => 'Synthetic Privacy',
            'allowed_payer_types' => ['self', 'organization'], 'allowed_funding_modes' => ['SPONSORED']]);
        $this->client = IntegrationClient::create(['organization_id' => $org->id,
            'client_id' => 'private-client', 'credential_reference' => 'privacy-test', 'enabled' => true])->refresh();
        IntegrationSource::create(['integration_client_id' => $this->client->id,
            'source_system' => 'PRIVATE_SOURCE', 'contract_version' => 'checkout-v2', 'status' => 'ACTIVE',
            'allowed_assessment_packages' => ['PRIVATE_PACKAGE'], 'allowed_funding_modes' => ['SPONSORED'],
            'allowed_payer_types' => ['self', 'organization']]);
        $package = TestPackage::create(['code' => 'PRIVATE_PACKAGE', 'name' => 'Synthetic Privacy',
            'amount' => 1000, 'currency' => 'IDR', 'is_active' => true]);
        $package->items()->create(['test_type' => 'ist', 'sort_order' => 1]);
        config()->set('assessment_integration.credentials.privacy-test', self::SECRET);
        config()->set('assessment_integration.checkout.enabled', true);
        // Explicit test-only order is part of the boundary contract. Neither route is registered in production.
        Route::post(self::PATH, CheckoutParticipantProvisioningController::class)
            ->middleware([PreventCheckoutResponseCaching::class, 'integration.client']);
        Route::post(self::CONTROL, CheckoutParticipantProvisioningController::class)->middleware('integration.client');
    }

    protected function tearDown(): void
    {
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame($this->transactionLevel, DB::transactionLevel());
        parent::tearDown();
    }

    public function test_create_and_replay_remain_minimal_and_private(): void
    {
        $created = $this->signed()->assertCreated();
        $attempt = AssessmentParticipant::sole();
        $body = ['data' => ['participantId' => (string) $attempt->participant_id,
            'assessmentAttemptId' => $attempt->assessment_attempt_id, 'assessmentStatus' => 'PROVISIONED']];
        $created->assertExactJson($body);
        $this->assertPrivate($created);
        $this->assertPrivate($this->signed()->assertOk()->assertExactJson($body));
        foreach (['assessment_entitlements', 'entitlements', 'assessment_charges', 'assessment_bills',
            'orders', 'outbox_messages', 'consent_records', 'identity_verifications'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    #[DataProvider('earlyRejections')]
    public function test_early_rejection_body_and_status_are_preserved_with_private_headers(string $scenario, int $status, string $code): void
    {
        $payload = $this->payload();
        $headers = [];
        if ($scenario === 'malformed') {
            $headers['HTTP_X_SIGNATURE'] = 'invalid';
        } elseif ($scenario === 'disabled') {
            $this->client->update(['enabled' => false]);
        } elseif ($scenario === 'validation') {
            $payload['metadata'] = ['private@example.test' => self::SECRET];
        } elseif ($scenario === 'contract') {
            config()->set('assessment_integration.checkout.enabled', false);
        }
        $before = $this->signed($payload, $headers, self::CONTROL)->assertStatus($status)->assertJsonPath('error.code', $code);
        $after = $this->signed($payload, $headers)->assertStatus($status)->assertExactJson($before->json());
        $this->assertPrivate($after);
        foreach (['private@example.test', self::SECRET, 'Synthetic Person', '628123456789'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $after->getContent());
        }
        $this->assertDatabaseCount('participants', 0);
        $this->assertDatabaseCount('assessment_participants', 0);
    }

    public static function earlyRejections(): iterable
    {
        yield ['malformed', 401, 'INVALID_SIGNATURE'];
        yield ['disabled', 401, 'INVALID_SIGNATURE'];
        yield ['validation', 422, 'VALIDATION_FAILED'];
        yield ['contract', 503, 'CHECKOUT_NOT_ENABLED'];
    }

    public function test_throttle_response_is_unchanged_and_private(): void
    {
        $this->client->update(['rate_limit_policy' => ['requestsPerMinute' => 1]]);
        $this->signed()->assertCreated();
        $before = $this->signed(path: self::CONTROL)->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
        $after = $this->signed()->assertStatus(429)->assertExactJson($before->json());
        $this->assertPrivate($after);
        $this->assertDatabaseCount('participants', 1);
        $this->assertDatabaseCount('assessment_participants', 1);
    }

    public function test_conflict_response_remains_409_and_private(): void
    {
        $this->signed()->assertCreated();
        $payload = [...$this->payload(), 'externalCandidateId' => 'OTHER'];
        $before = $this->signed($payload, path: self::CONTROL)->assertConflict();
        $after = $this->signed($payload)->assertConflict()->assertExactJson($before->json());
        $this->assertPrivate($after);
        $this->assertDatabaseCount('assessment_participants', 1);
    }

    public function test_500_uses_real_framework_reporting_and_rendering_with_rollback_and_private_headers(): void
    {
        $this->assertFalse(config('app.debug'));
        $handler = app(ExceptionHandler::class);
        $this->assertInstanceOf(Handler::class, $handler);
        $reported = [];
        $handler->reportable(function (RuntimeException $exception) use (&$reported): void {
            $reported[] = $exception;
        });
        $failure = new RuntimeException('Synthetic Person private@example.test SELECT * FROM participants; '.self::SECRET);
        $once = true;
        DB::listen(function (QueryExecuted $query) use (&$once, $failure): void {
            if ($once && str_starts_with($query->sql, 'insert') && str_contains($query->sql, 'assessment_participants')) {
                $once = false;
                throw $failure;
            }
        });

        $response = $this->signed()->assertStatus(500)->assertExactJson(['message' => 'Server Error']);
        $this->assertPrivate($response);
        $this->assertSame([$failure], $reported, 'The real handler must report the original exception once.');
        foreach (['Synthetic Person', 'private@example.test', self::SECRET, 'SELECT', 'participants', 'trace', 'profile'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $response->getContent());
        }
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame($this->transactionLevel, DB::transactionLevel());
        $this->assertDatabaseCount('participants', 0);
        $this->assertDatabaseCount('assessment_participants', 0);
        $this->assertPrivate($this->signed()->assertCreated());
    }

    public function test_existing_v1_route_is_not_wrapped_and_keeps_legacy_behavior(): void
    {
        IntegrationSource::create(['integration_client_id' => $this->client->id,
            'source_system' => 'LEGACY_SOURCE', 'contract_version' => 'v1', 'status' => 'ACTIVE',
            'allowed_assessment_packages' => ['PRIVATE_PACKAGE'], 'allowed_funding_modes' => ['SPONSORED']]);
        $payload = $this->payload();
        unset($payload['contractVersion']);
        $payload['sourceSystem'] = 'LEGACY_SOURCE';
        $payload['fundingMode'] = 'SPONSORED';
        $payload['profile'] = ['fullName' => 'Synthetic Legacy', 'birthDate' => '2000-01-01',
            'gender' => 'MALE', 'educationLevel' => 'SMA', 'phone' => '628123456789'];
        $path = '/api/integrations/v1/assessments/participants';
        $legacy = $this->signed($payload, path: $path)->assertCreated()->assertJsonPath('data.assessmentStatus', 'READY');
        $this->assertFalse($legacy->headers->hasCacheControlDirective('no-store'));
        $this->signed($payload, path: $path)->assertOk()->assertExactJson($legacy->json());
        $denied = $this->signed($payload, ['HTTP_X_SIGNATURE' => 'invalid'], $path)->assertUnauthorized()
            ->assertJsonPath('error.code', 'INVALID_SIGNATURE');
        $this->assertFalse($denied->headers->hasCacheControlDirective('no-store'));
    }

    private function assertPrivate(TestResponse $response): void
    {
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertFalse($response->headers->hasCacheControlDirective('public'));
    }

    private function payload(): array
    {
        return ['contractVersion' => 'checkout-v2', 'sourceSystem' => 'PRIVATE_SOURCE', 'organizationCode' => 'PRIVATE',
            'externalCandidateId' => 'CANDIDATE-1', 'assessmentPackageCode' => 'PRIVATE_PACKAGE',
            'profile' => ['fullName' => 'Synthetic Person', 'email' => 'private@example.test', 'phone' => '628123456789']];
    }

    private function signed(?array $payload = null, array $headers = [], string $path = self::PATH): TestResponse
    {
        $body = json_encode($payload ?? $this->payload(), JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;

        return $this->call('POST', $path, [], [], [], [...[
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CLIENT_ID' => $this->client->client_id, 'HTTP_X_TIMESTAMP' => $timestamp,
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $timestamp."\n".hash('sha256', $body), self::SECRET),
            'HTTP_IDEMPOTENCY_KEY' => 'privacy-key',
        ], ...$headers], $body);
    }
}
