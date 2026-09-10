<?php

declare(strict_types=1);

namespace Tests\Feature\Registration;

use App\Actions\Integrations\EstablishCheckoutSession;
use App\Actions\Integrations\IssueCheckoutHandoff;
use App\Contracts\Notifier;
use App\Data\Integrations\CheckoutHandoffIssueInput;
use App\Data\Integrations\CheckoutSessionExchangeInput;
use App\Enums\CheckoutHandoffIntent;
use App\Http\Controllers\IntegratedCheckoutConfirmationController;
use App\Http\Middleware\AuthenticateCheckoutSession;
use App\Http\Middleware\ProtectCheckoutSessionHttpBoundary;
use App\Http\Middleware\VerifyCheckoutSessionJsonMutation;
use App\Models\IntegrationClient;
use App\Models\TestPackage;
use App\Registration\ConsentDocument;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Integrations\CheckoutSessionHttpContract;
use App\Services\Notifications\FakeNotifier;
use App\Services\Payments\AssessmentPriceSnapshot;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentBillingFixture;

final class IntegratedCheckoutConfirmationHttpTest extends OrganizationPaymentTestCase
{
    private const string PATH = '/checkout/confirm';

    private int $transactionLevel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->transactionLevel = DB::transactionLevel();
        config()->set('app.url', 'https://psikotes.oncam.id');
        URL::forceRootUrl('https://psikotes.oncam.id');
        URL::forceScheme('https');
        config()->set('assessment_integration.checkout_handoff.enabled', true);
        config()->set('assessment_integration.checkout_handoff.ttl_seconds', 600);
        config()->set('assessment_integration.checkout_session', [
            'enabled' => true, 'idle_minutes' => 30, 'absolute_minutes' => 120,
            'terminal_retention_days' => 30,
            'http' => [
                'destination_origin' => 'https://psikotes.oncam.id',
                'trusted_exchange_origins' => [
                    'https://seleksi.beasiswajepang.id', 'https://seleksi.serbaindo.com',
                ],
                'exchange_per_minute' => 10, 'hydrate_per_minute' => 60, 'mutation_per_minute' => 10,
                'confirmation' => ['enabled' => true, 'max_body_bytes' => 4096, 'writer_enabled' => true],
            ],
        ]);
        config()->set('consent.documents.dass', [
            'version' => 'draft-2026-09-08',
            'title' => 'Persetujuan skrining DASS-21',
            'text' => 'DASS-21 diproses terpisah dan bukan diagnosis.',
        ]);
        RateLimiter::for(CheckoutSessionHttpContract::LIMITER,
            fn (Request $request) => app(CheckoutSessionHttpContract::class)->rateLimit($request));

        $this->assertTrue(collect(Route::getRoutes())->contains(
            fn ($route): bool => $route->uri() === ltrim(self::PATH, '/') && in_array('POST', $route->methods(), true),
        ), 'The default-off confirmation route must be present in production routing.');
        Route::post(self::PATH, IntegratedCheckoutConfirmationController::class)
            ->name('test.checkout.confirm')
            ->middleware([
                ProtectCheckoutSessionHttpBoundary::class,
                AuthenticateCheckoutSession::class,
                VerifyCheckoutSessionJsonMutation::class,
                'throttle:'.CheckoutSessionHttpContract::LIMITER,
            ]);
    }

    protected function tearDown(): void
    {
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame($this->transactionLevel, DB::transactionLevel());
        parent::tearDown();
    }

    public function test_test_only_pipeline_confirms_and_exact_replay_is_minimal_private_and_idempotent(): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($route): bool => $route->uri() === ltrim(self::PATH, '/') && in_array('POST', $route->methods(), true),
        );
        $this->assertNotNull($route);
        $this->assertSame([
            ProtectCheckoutSessionHttpBoundary::class,
            AuthenticateCheckoutSession::class,
            VerifyCheckoutSessionJsonMutation::class,
            'throttle:'.CheckoutSessionHttpContract::LIMITER,
        ], $route->gatherMiddleware());
        $fixture = $this->established();

        $created = $this->mutation($fixture)->assertOk()->assertExactJson([
            'data' => ['confirmed' => true, 'replayed' => false],
        ]);
        $this->assertPrivate($created);
        $replay = $this->mutation($fixture)->assertOk()->assertExactJson([
            'data' => ['confirmed' => true, 'replayed' => true],
        ]);
        $this->assertPrivate($replay);
        $this->assertDatabaseCount('consent_records', 2);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'checkout.confirmed')->count());
        foreach (['assessment_bills', 'assessment_bill_items', 'assessment_entitlements', 'outbox_messages'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_settled_identity_complete_attempt_activates_without_creating_an_invoice_or_sending_notifications(): void
    {
        $fixture = $this->established(settled: true, identity: true);
        $billCount = DB::table('assessment_bills')->count();

        $this->assertPrivate($this->mutation($fixture)->assertOk());

        $this->assertSame($billCount, DB::table('assessment_bills')->count());
        $this->assertSame(2, DB::table('assessment_entitlements')
            ->where('assessment_participant_id', $fixture['attempt'])->where('status', 'ready')->count());
        $this->assertSame(1, DB::table('outbox_messages')->where('topic', 'assessment.activation')->count());
        $notifier = app(Notifier::class);
        $this->assertInstanceOf(FakeNotifier::class, $notifier);
        $this->assertSame([], $notifier->delivered());
    }

    public function test_http_accepts_dass_only_when_psychotest_is_current_and_profile_only_when_all_consents_are_current(): void
    {
        $dassOnly = $this->established();
        $this->acceptCurrentConsents($dassOnly['participant'], ['psychotest']);
        $this->assertPrivate($this->mutation($dassOnly, $this->payload(['dass']))
            ->assertOk()->assertExactJson(['data' => ['confirmed' => true, 'replayed' => false]]));
        $this->assertSame(2, DB::table('consent_records')
            ->where('participant_id', $dassOnly['participant'])->count());

        $profileOnly = $this->established();
        $this->acceptCurrentConsents($profileOnly['participant'], ['psychotest', 'dass']);
        $this->assertPrivate($this->mutation($profileOnly, $this->payload([]))
            ->assertOk()->assertExactJson(['data' => ['confirmed' => true, 'replayed' => false]]));
        $this->assertSame(2, DB::table('consent_records')
            ->where('participant_id', $profileOnly['participant'])->count());
    }

    public function test_validation_conflict_and_disabled_writer_are_generic_private_and_leave_no_partial_write(): void
    {
        $validationFixture = $this->established();
        $invalid = json_decode($this->payload(), true, flags: JSON_THROW_ON_ERROR);
        $invalid['profile']['email'] = 'private@example.test';
        $validation = $this->mutation($validationFixture, json_encode($invalid, JSON_THROW_ON_ERROR))
            ->assertUnprocessable();
        $this->assertPrivate($validation);
        $this->assertStringNotContainsString('private@example.test', $validation->getContent());

        $conflictFixture = $this->established();
        $conflictPayload = json_decode($this->payload(), true, flags: JSON_THROW_ON_ERROR);
        $conflictPayload['consents']['dass']['documentHash'] = str_repeat('0', 64);
        $conflict = $this->mutation($conflictFixture, json_encode($conflictPayload, JSON_THROW_ON_ERROR))
            ->assertConflict()->assertExactJson(['error' => [
                'code' => 'CHECKOUT_CONFIRMATION_CONFLICT',
                'message' => 'Konfirmasi checkout tidak dapat diproses.',
            ]]);
        $this->assertPrivate($conflict);

        $disabledFixture = $this->established();
        config()->set('assessment_integration.checkout_session.http.confirmation.writer_enabled', false);
        $disabled = $this->mutation($disabledFixture)->assertStatus(503)->assertExactJson(['error' => [
            'code' => 'CHECKOUT_CONFIRMATION_UNAVAILABLE',
            'message' => 'Konfirmasi checkout tidak tersedia.',
        ]]);
        $this->assertPrivate($disabled);
        $this->assertSame(0, DB::table('consent_records')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'checkout.confirmed')->count());
    }

    public function test_auth_transport_and_throttle_rejections_are_private_and_do_not_reach_the_writer(): void
    {
        $fixture = $this->established();
        $cookies = [
            CheckoutSessionHttpContract::SELECTOR_COOKIE => 'ocs1_'.str_repeat('0', 64),
            CheckoutSessionHttpContract::CSRF_COOKIE => $fixture['csrf'],
        ];
        $auth = $this->mutation($fixture, cookies: $cookies)->assertStatus(303)
            ->assertRedirect('/checkout/unavailable');
        $this->assertPrivate($auth);
        $csrf = $this->mutation($fixture, server: ['HTTP_X_CHECKOUT_CSRF' => 'ocsrf1_'.str_repeat('0', 64)])
            ->assertStatus(419);
        $this->assertPrivate($csrf);
        $malformed = $this->mutation($fixture, '{"profile":{},"profile":{}}')->assertUnprocessable();
        $this->assertPrivate($malformed);

        config()->set('assessment_integration.checkout_session.http.mutation_per_minute', 1);
        RateLimiter::clear('checkout-http:mutation:127.0.0.19');
        $this->mutation($fixture, ip: '127.0.0.19')->assertOk();
        $throttled = $this->mutation($fixture, ip: '127.0.0.19')->assertStatus(429);
        $this->assertPrivate($throttled);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'checkout.confirmed')->count());
    }

    public function test_unexpected_failure_uses_framework_reporting_and_generic_private_500_with_full_rollback(): void
    {
        $this->assertFalse(config('app.debug'));
        $handler = app(ExceptionHandler::class);
        $this->assertInstanceOf(Handler::class, $handler);
        $reported = [];
        $handler->reportable(function (QueryException $exception) use (&$reported): void {
            $reported[] = $exception;
        });
        DB::unprepared("CREATE TRIGGER p16_confirmation_failure BEFORE INSERT ON audit_logs
            WHEN NEW.action = 'checkout.confirmed'
            BEGIN SELECT RAISE(ABORT, 'Synthetic Person private@example.test SELECT secret credential'); END");
        $fixture = $this->established();

        try {
            $response = $this->mutation($fixture)->assertStatus(500)->assertExactJson(['message' => 'Server Error']);
        } finally {
            DB::unprepared('DROP TRIGGER p16_confirmation_failure');
        }

        $this->assertPrivate($response);
        $this->assertCount(1, $reported);
        foreach (['Synthetic Person', 'private@example.test', 'SELECT', 'credential', 'audit_logs', 'trace'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->assertDatabaseHas('participants', ['id' => $fixture['participant'], 'full_name' => null]);
        $this->assertDatabaseCount('consent_records', 0);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'checkout.confirmed')->count());
    }

    /** @return array{participant:int,attempt:int,selector:string,csrf:string} */
    private function established(bool $settled = false, bool $identity = false): array
    {
        $key = (string) Str::ulid();
        $sourceSystem = 'P16_'.$key;
        $packageCode = 'P16'.$key;
        $organization = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => 'Synthetic', 'organization_code' => $key,
            'display_name' => 'Synthetic', 'status' => 'ACTIVE', 'is_active' => true,
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $organization, 'referral_branch_id' => $organization,
            'referral_source' => 'manual', 'source_system' => $sourceSystem,
            'full_name' => null, 'phone' => '620000000000',
        ]);
        $client = DB::table('integration_clients')->insertGetId([
            'organization_id' => $organization, 'client_id' => $key,
            'credential_reference' => 'synthetic-only', 'enabled' => true,
        ]);
        $source = DB::table('integration_sources')->insertGetId([
            'integration_client_id' => $client, 'source_system' => $sourceSystem,
            'contract_version' => 'checkout-v2', 'allowed_assessment_packages' => json_encode([$packageCode]),
            'allowed_funding_modes' => json_encode(['COMMERCIAL_SELF_PAY']), 'status' => 'ACTIVE',
        ]);
        $package = DB::table('packages')->insertGetId([
            'code' => $packageCode, 'name' => 'Synthetic', 'amount' => 100,
            'currency' => 'IDR', 'is_active' => true,
        ]);
        DB::table('participants')->where('id', $participant)->update(['package_id' => $package]);
        DB::table('package_items')->insert([
            ['package_id' => $package, 'test_type' => 'ist', 'sort_order' => 1],
            ['package_id' => $package, 'test_type' => 'dass21', 'sort_order' => 2],
        ]);
        $attemptPublicId = (string) Str::ulid();
        $case = AssessmentBillingFixture::createExactIntegratedCase(
            $participant, $organization, $package, $attemptPublicId,
        );
        $attempt = DB::table('assessment_participants')->insertGetId([
            'organization_id' => $organization, 'integration_client_id' => $client,
            'participant_id' => $participant, 'package_id' => $package,
            'assessment_case_id' => $case,
            'assessment_attempt_id' => $attemptPublicId, 'source_system' => $sourceSystem,
            'external_candidate_id' => $key, 'funding_mode' => 'COMMERCIAL_SELF_PAY',
            'assessment_status' => 'PROVISIONED', 'idempotency_key' => $key,
            'request_hash' => hash('sha256', $key), 'logical_assessment_key' => hash('sha256', 'logical'.$key),
            'metadata' => json_encode(['checkout_contract_version' => 'checkout-v2',
                'checkout_initial_funding_mode' => 'COMMERCIAL_SELF_PAY']),
        ]);
        if ($settled) {
            $method = DB::table('payment_methods')->insertGetId([
                'code' => strtolower($key), 'display_name' => 'Synthetic', 'is_active' => true,
            ]);
            $snapshot = app(AssessmentPriceSnapshot::class)
                ->capture(TestPackage::with('items')->findOrFail($package), false);
            $charge = DB::table('assessment_charges')->insertGetId([
                'assessment_participant_id' => $attempt, 'organization_id' => $organization,
                'participant_id' => $participant, 'package_id' => $package, 'payer_type' => 'self',
                'base_amount' => 100, 'consultation_amount' => 0, 'amount' => 100, 'currency' => 'IDR',
                'price_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'policy_snapshot' => '{}',
            ]);
            $bill = DB::table('assessment_bills')->insertGetId([
                'organization_id' => $organization, 'payer_type' => 'self', 'payer_participant_id' => $participant,
                'public_reference' => 'AB_'.$key, 'status' => 'paid', 'amount' => 100, 'currency' => 'IDR',
                'item_count' => 1, 'selection_hash' => hash('sha256', 'selection'.$key),
                'idempotency_key' => $key, 'request_hash' => hash('sha256', 'bill'.$key),
                'payment_method_id' => $method, 'paid_at' => now(),
            ]);
            DB::table('assessment_bill_items')->insert([
                'bill_id' => $bill, 'charge_id' => $charge, 'organization_id' => $organization,
                'participant_id' => $participant, 'payer_type' => 'self', 'payer_participant_id' => $participant,
                'amount' => 100, 'currency' => 'IDR', 'settled_at' => now(),
            ]);
        }
        if ($identity) {
            foreach (['identity_document', 'initial_selfie'] as $type) {
                $publicId = (string) Str::ulid();
                DB::table('identity_evidence')->insert([
                    'public_id' => $publicId, 'participant_id' => $participant, 'type' => $type,
                    'disk' => 'local', 'object_key' => 'synthetic/'.$publicId, 'mime_type' => 'image/jpeg',
                    'size_bytes' => 100, 'width' => 10, 'height' => 10,
                    'checksum_sha256' => hash('sha256', $publicId), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('identity_verifications')->insert([
                'participant_id' => $participant, 'matcher' => 'synthetic', 'outcome' => 'match',
                'manual_status' => 'pending', 'checked_at' => now(),
            ]);
        }
        $issued = app(RlsContextRunner::class)->run(new RlsContext('service'), fn () => app(IssueCheckoutHandoff::class)
            ->execute(new CheckoutHandoffIssueInput(IntegrationClient::findOrFail($client), $attemptPublicId,
                $sourceSystem, 'ih1_'.bin2hex(random_bytes(16)), CheckoutHandoffIntent::Issue)));
        $established = app(EstablishCheckoutSession::class)
            ->execute(new CheckoutSessionExchangeInput($issued->rawToken()));

        return compact('participant', 'attempt')
            + ['selector' => $established->rawSelector(), 'csrf' => $established->rawCsrfToken()];
    }

    /** @param list<string> $consentTypes */
    private function payload(array $consentTypes = ['psychotest', 'dass']): string
    {
        $psychotest = ConsentDocument::for('psychotest');
        $dass = ConsentDocument::for('dass');

        $consents = [
            'psychotest' => ['accepted' => true, 'documentVersion' => $psychotest->version,
                'documentHash' => $psychotest->hash],
            'dass' => ['accepted' => true, 'documentVersion' => $dass->version,
                'documentHash' => $dass->hash],
        ];

        return json_encode([
            'profile' => [
                'fullName' => 'Synthetic Person', 'birthDate' => '2000-01-02', 'gender' => 'FEMALE',
                'educationLevel' => 'SMA_SMK', 'intendedField' => 'KAIGO',
            ],
            'consents' => array_intersect_key($consents, array_flip($consentTypes)),
        ], JSON_THROW_ON_ERROR);
    }

    /** @param list<string> $types */
    private function acceptCurrentConsents(int $participantId, array $types): void
    {
        foreach ($types as $type) {
            $document = ConsentDocument::for($type);
            DB::table('consent_records')->insert([
                'participant_id' => $participantId, 'consent_type' => $type, 'status' => 'accepted',
                'document_version' => $document->version, 'document_hash' => $document->hash,
                'consented_at' => now(), 'withdrawn_at' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** @param array<string, string>|null $cookies
     * @param  array<string, string>  $server
     */
    private function mutation(array $fixture, ?string $body = null, ?array $cookies = null,
        array $server = [], string $ip = '127.0.0.18'): TestResponse
    {
        $cookies ??= [
            CheckoutSessionHttpContract::SELECTOR_COOKIE => $fixture['selector'],
            CheckoutSessionHttpContract::CSRF_COOKIE => $fixture['csrf'],
        ];
        $defaults = [
            'HTTPS' => 'on', 'HTTP_HOST' => 'psikotes.oncam.id', 'SERVER_NAME' => 'psikotes.oncam.id',
            'SERVER_PORT' => '443', 'REMOTE_ADDR' => $ip, 'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json', 'HTTP_ORIGIN' => 'https://psikotes.oncam.id',
            'HTTP_X_CHECKOUT_CSRF' => $fixture['csrf'], 'HTTP_SEC_FETCH_SITE' => 'same-origin',
            'HTTP_SEC_FETCH_MODE' => 'cors', 'HTTP_SEC_FETCH_DEST' => 'empty',
        ];

        return $this->call('POST', self::PATH, [], $cookies, [], array_replace($defaults, $server),
            $body ?? $this->payload());
    }

    /** @param TestResponse<Response> $response */
    private function assertPrivate(TestResponse $response): void
    {
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        foreach (['Pragma' => 'no-cache', 'Referrer-Policy' => 'no-referrer', 'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'self'; base-uri 'none'; frame-ancestors 'none'",
        ] as $header => $value) {
            $this->assertSame($value, $response->headers->get($header));
        }
    }
}
