<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Actions\Integrations\CheckoutSessionLifecycle;
use App\Actions\Integrations\EstablishCheckoutSession;
use App\Actions\Integrations\ExecuteCheckoutPayment;
use App\Actions\Integrations\IssueCheckoutHandoff;
use App\Contracts\PaymentProvider;
use App\Data\Integrations\CheckoutHandoffIssueInput;
use App\Data\Integrations\CheckoutSessionExchangeInput;
use App\Data\Integrations\CheckoutSessionMutationCredentials;
use App\Enums\CheckoutHandoffIntent;
use App\Http\Controllers\CheckoutPaymentController;
use App\Http\Middleware\AuthenticateCheckoutSession;
use App\Http\Middleware\PreventCheckoutResponseCaching;
use App\Http\Middleware\ProtectCheckoutSessionHttpBoundary;
use App\Http\Middleware\VerifyCheckoutPaymentJsonMutation;
use App\Http\Requests\CheckoutPaymentRequest;
use App\Models\IntegrationClient;
use App\Registration\ConsentDocument;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Integrations\CheckoutSessionHttpContract;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Tests\OrganizationPaymentTestCase;

final class CheckoutPaymentHttpTest extends OrganizationPaymentTestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.debug', false);
        config()->set('app.url', 'https://psikotes.oncam.id');
        URL::forceRootUrl('https://psikotes.oncam.id');
        URL::forceScheme('https');
        config()->set('assessment_integration.checkout_handoff.enabled', true);
        config()->set('assessment_integration.checkout_handoff.ttl_seconds', 600);
        config()->set('assessment_integration.checkout_session.enabled', true);
        config()->set('assessment_integration.checkout.enabled', true);
        config()->set('assessment_integration.checkout_session.http.payment.enabled', true);
        config()->set('assessment_integration.checkout_session.http.payment.writer_enabled', true);
        config()->set('assessment_integration.checkout_session.http.payment.max_body_bytes', 256);
        config()->set('consent.legal_review_pending', false);
        config()->set('consent.documents.dass', [
            'version' => 'draft-2026-09-08',
            'title' => 'Persetujuan skrining DASS-21',
            'text' => 'DASS-21 diproses terpisah dan bukan diagnosis.',
        ]);
        DB::table('payment_methods')->insert([
            'code' => 'xendit', 'display_name' => 'Synthetic Xendit', 'is_active' => true,
        ]);
        RateLimiter::clear('checkout-http:payment:127.0.0.51');
    }

    protected function tearDown(): void
    {
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame(0, DB::transactionLevel());
        parent::tearDown();
    }

    public function test_positive_self_payment_returns_only_persisted_pending_capability_and_replays(): void
    {
        $fixture = $this->established();

        $first = $this->payment($fixture, false)->assertOk();
        $this->assertPrivate($first);
        $payload = $first->json();
        $this->assertSame(['data'], array_keys($payload));
        $this->assertSame(['paymentState', 'paymentUrl'], array_keys($payload['data']));
        $this->assertSame('pending', $payload['data']['paymentState']);
        $this->assertIsString($payload['data']['paymentUrl']);
        $this->assertStringStartsWith('https://payments.example.test/invoices/', $payload['data']['paymentUrl']);

        $this->assertPrivate($this->payment($fixture, false)->assertOk()->assertExactJson($payload));
        $this->assertDatabaseCount('assessment_bills', 1);
        $this->assertDatabaseCount('assessment_charges', 1);
        $this->assertDatabaseCount('assessment_bill_items', 1);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.invoice_issued')->count());
    }

    public function test_zero_payment_falls_back_to_settlement_and_returns_paid_without_provider_or_bill(): void
    {
        $fixture = $this->established(amount: 0);
        $this->acceptCurrentConsents($fixture['participant']);
        $before = \Carbon\CarbonImmutable::instance(now())->utc()->startOfSecond();
        $provider = $this->createMock(PaymentProvider::class);
        foreach (['createInvoice', 'lookupInvoice', 'checkStatus', 'normalizeWebhook', 'expireInvoice'] as $method) {
            $provider->expects($this->never())->method($method);
        }
        app()->instance(PaymentProvider::class, $provider);

        $response = $this->payment($fixture, false)->assertOk()->assertExactJson([
            'data' => ['paymentState' => 'paid', 'paymentUrl' => null],
        ]);

        $this->assertPrivate($response);
        $this->assertDatabaseCount('assessment_bills', 0);
        $charge = DB::table('assessment_charges')->where('assessment_participant_id', $fixture['attempt'])->sole();
        $this->assertSame(0, $charge->amount);
        $this->assertNotNull($charge->free_settled_at);
        $settledAt = \Carbon\CarbonImmutable::parse((string) $charge->free_settled_at)->utc();
        $after = \Carbon\CarbonImmutable::instance(now())->utc()->addSecond()->startOfSecond();
        $this->assertTrue($settledAt->betweenIncluded($before, $after));
    }

    public function test_positive_organization_payment_is_one_generic_conflict_without_payment_writes(): void
    {
        $fixture = $this->established('INVOICED_TO_ORGANIZATION');

        $response = $this->payment($fixture, false)->assertConflict()->assertExactJson([
            'error' => [
                'code' => 'CHECKOUT_PAYMENT_UNAVAILABLE',
                'message' => 'Pembayaran checkout tidak dapat diproses.',
            ],
        ]);

        $this->assertPrivate($response);
        $this->assertDatabaseCount('assessment_bills', 0);
        $this->assertDatabaseCount('assessment_charges', 0);
        $this->assertDatabaseCount('assessment_bill_items', 0);
    }

    public function test_dispatcher_rejects_ambient_context_or_transaction_and_controller_maps_logic_to_503(): void
    {
        $fixture = $this->established();
        $credentials = new CheckoutSessionMutationCredentials($fixture['selector'], $fixture['csrf']);
        foreach ([
            fn () => app(RlsContextRunner::class)->runAsService(
                fn () => app(ExecuteCheckoutPayment::class)->execute($credentials, false),
            ),
            fn () => DB::transaction(fn () => app(ExecuteCheckoutPayment::class)->execute($credentials, false)),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Ambient checkout payment command was accepted.');
            } catch (LogicException $exception) {
                $this->assertSame('Checkout payment command owns an empty context and transaction.', $exception->getMessage());
            }
        }

        $principal = app(CheckoutSessionLifecycle::class)->hydrateWithCsrfDelivery($credentials);
        $request = CheckoutPaymentRequest::create('/checkout/payment', 'POST', [
            'consultationRequested' => false,
        ], [
            CheckoutSessionHttpContract::SELECTOR_COOKIE => $fixture['selector'],
            CheckoutSessionHttpContract::CSRF_COOKIE => $fixture['csrf'],
        ], server: ['HTTP_X_CHECKOUT_CSRF' => $fixture['csrf']]);
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->attributes->set(CheckoutSessionHttpContract::PRINCIPAL_ATTRIBUTE, $principal);
        $request->validateResolved();
        $response = DB::transaction(fn (): Response => app(CheckoutPaymentController::class)(
            $request,
            app(ExecuteCheckoutPayment::class),
        ));
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame([
            'error' => [
                'code' => 'CHECKOUT_PAYMENT_UNAVAILABLE',
                'message' => 'Pembayaran checkout tidak tersedia.',
            ],
        ], json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR));
        $this->assertDatabaseCount('assessment_bills', 0);
    }

    public function test_default_off_production_route_is_generic_private_503_before_session_authentication(): void
    {
        $route = Route::getRoutes()->getByName('checkout.payment');
        $this->assertInstanceOf(RoutingRoute::class, $route);
        $this->assertContains('web', $route->excludedMiddleware());
        $this->assertSame([
            PreventCheckoutResponseCaching::class,
            ProtectCheckoutSessionHttpBoundary::class,
            'throttle:'.CheckoutSessionHttpContract::LIMITER,
            VerifyCheckoutPaymentJsonMutation::class,
            AuthenticateCheckoutSession::class,
        ], array_values(array_filter(
            $route->gatherMiddleware(),
            static fn (string $item): bool => $item !== 'web',
        )));
        config()->set('assessment_integration.checkout_session.http.payment.enabled', false);
        $credentials = [
            'selector' => 'ocs1_'.str_repeat('a', 64),
            'csrf' => 'ocsrf1_'.str_repeat('b', 64),
        ];

        $response = $this->payment($credentials, false)->assertStatus(503);

        $this->assertSame('Service Unavailable', $response->getContent());
        $this->assertPrivate($response);
    }

    public function test_dispatcher_collapses_both_domain_failures_to_one_public_domain_result(): void
    {
        $fixture = $this->established('INVOICED_TO_ORGANIZATION');

        try {
            app(ExecuteCheckoutPayment::class)->execute(
                new CheckoutSessionMutationCredentials($fixture['selector'], $fixture['csrf']),
                false,
            );
            $this->fail('Unavailable payment command succeeded.');
        } catch (DomainException $exception) {
            $this->assertSame('CHECKOUT_PAYMENT_UNAVAILABLE', $exception->getMessage());
        }

        $this->assertDatabaseCount('assessment_charges', 0);
        $this->assertDatabaseCount('assessment_bills', 0);
    }

    /** @param array<string, int|string> $credentials
     * @return TestResponse<Response>
     */
    private function payment(array $credentials, bool $consultation): TestResponse
    {
        $body = json_encode(['consultationRequested' => $consultation], JSON_THROW_ON_ERROR);

        return $this->call('POST', '/checkout/payment', [], [
            CheckoutSessionHttpContract::SELECTOR_COOKIE => (string) $credentials['selector'],
            CheckoutSessionHttpContract::CSRF_COOKIE => (string) $credentials['csrf'],
        ], [], [
            'HTTPS' => 'on', 'HTTP_HOST' => 'psikotes.oncam.id', 'SERVER_NAME' => 'psikotes.oncam.id',
            'SERVER_PORT' => '443', 'REMOTE_ADDR' => '127.0.0.51',
            'HTTP_ORIGIN' => 'https://psikotes.oncam.id',
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CHECKOUT_CSRF' => (string) $credentials['csrf'],
            'HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_SEC_FETCH_MODE' => 'cors',
            'HTTP_SEC_FETCH_DEST' => 'empty',
        ], $body);
    }

    private function acceptCurrentConsents(int $participant): void
    {
        foreach (['psychotest', 'dass'] as $type) {
            $document = ConsentDocument::for($type);
            DB::table('consent_records')->insert([
                'participant_id' => $participant, 'consent_type' => $type, 'status' => 'accepted',
                'document_version' => $document->version, 'document_hash' => $document->hash,
                'consented_at' => now(), 'withdrawn_at' => null,
            ]);
        }
    }

    /** @return array{participant:int,attempt:int,selector:string,csrf:string} */
    private function established(string $funding = 'COMMERCIAL_SELF_PAY', int $amount = 100): array
    {
        $key = (string) Str::ulid();
        $sourceSystem = 'PAYHTTP_'.$key;
        $packageCode = 'PH'.$key;
        $payer = $funding === 'COMMERCIAL_SELF_PAY' ? 'self' : 'organization';
        $organization = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => 'Synthetic', 'organization_code' => $key,
            'display_name' => 'Synthetic', 'status' => 'ACTIVE', 'is_active' => true,
            'allowed_payer_types' => json_encode([$payer], JSON_THROW_ON_ERROR),
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $organization, 'referral_branch_id' => $organization,
            'referral_source' => 'manual', 'source_system' => $sourceSystem,
            'full_name' => 'Synthetic Person', 'birth_date' => '2000-01-02', 'gender' => 'female',
            'education_level' => 'SMA_SMK', 'intended_field' => 'KAIGO', 'phone' => '620000000000',
        ]);
        $client = DB::table('integration_clients')->insertGetId([
            'organization_id' => $organization, 'client_id' => $key,
            'credential_reference' => 'synthetic-only', 'enabled' => true,
        ]);
        DB::table('integration_sources')->insert([
            'integration_client_id' => $client, 'source_system' => $sourceSystem,
            'contract_version' => 'checkout-v2',
            'allowed_assessment_packages' => json_encode([$packageCode], JSON_THROW_ON_ERROR),
            'allowed_funding_modes' => '[]', 'allowed_payer_types' => json_encode([$payer], JSON_THROW_ON_ERROR),
            'status' => 'ACTIVE',
        ]);
        $package = DB::table('packages')->insertGetId([
            'code' => $packageCode, 'name' => 'Synthetic', 'amount' => $amount,
            'consultation_amount' => 30, 'currency' => 'IDR', 'is_active' => true,
        ]);
        DB::table('participants')->where('id', $participant)->update(['package_id' => $package]);
        DB::table('package_items')->insert([
            ['package_id' => $package, 'test_type' => 'ist', 'sort_order' => 1],
            ['package_id' => $package, 'test_type' => 'dass21', 'sort_order' => 2],
        ]);
        $attemptPublicId = (string) Str::ulid();
        $case = DB::table('assessment_cases')->insertGetId([
            'public_id' => $attemptPublicId,
            'participant_id' => $participant,
            'organization_id' => $organization,
            'package_id' => $package,
            'origin' => 'INTEGRATED',
            'intended_field_snapshot' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $attempt = DB::table('assessment_participants')->insertGetId([
            'organization_id' => $organization, 'integration_client_id' => $client,
            'participant_id' => $participant, 'package_id' => $package,
            'assessment_case_id' => $case, 'assessment_attempt_id' => $attemptPublicId, 'source_system' => $sourceSystem,
            'external_candidate_id' => $key, 'funding_mode' => $funding,
            'assessment_status' => 'PROVISIONED', 'idempotency_key' => $key,
            'request_hash' => hash('sha256', $key), 'logical_assessment_key' => hash('sha256', 'logical'.$key),
            'metadata' => json_encode([
                'checkout_contract_version' => 'checkout-v2', 'checkout_initial_funding_mode' => $funding,
            ], JSON_THROW_ON_ERROR),
        ]);
        $issued = app(RlsContextRunner::class)->run(new RlsContext('service'), fn () => app(IssueCheckoutHandoff::class)
            ->execute(new CheckoutHandoffIssueInput(IntegrationClient::query()->findOrFail($client),
                $attemptPublicId, $sourceSystem, 'ih1_'.bin2hex(random_bytes(16)), CheckoutHandoffIntent::Issue)));
        $session = app(EstablishCheckoutSession::class)
            ->execute(new CheckoutSessionExchangeInput($issued->rawToken()));

        return [
            'participant' => $participant,
            'attempt' => $attempt,
            'selector' => $session->rawSelector(),
            'csrf' => $session->rawCsrfToken(),
        ];
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
