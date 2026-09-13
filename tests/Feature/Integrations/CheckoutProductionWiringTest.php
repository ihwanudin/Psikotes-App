<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Actions\Integrations\IssueCheckoutHandoff;
use App\Data\Integrations\CheckoutHandoffIssueInput;
use App\Enums\CheckoutHandoffIntent;
use App\Http\Middleware\AuthenticateCheckoutSession;
use App\Http\Middleware\ProtectCheckoutSessionHttpBoundary;
use App\Http\Middleware\VerifyCheckoutSessionJsonMutation;
use App\Http\Middleware\VerifyCheckoutSessionMutation;
use App\Models\IntegrationClient;
use App\Models\User;
use App\Registration\ConsentDocument;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Integrations\CheckoutSessionHttpContract;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\OrganizationPaymentTestCase;

final class CheckoutProductionWiringTest extends OrganizationPaymentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config()->set('app.url', 'https://psikotes.oncam.id');
        URL::forceRootUrl('https://psikotes.oncam.id');
        URL::forceScheme('https');
        config()->set('consent.documents.dass', [
            'version' => 'draft-2026-09-08',
            'title' => 'Persetujuan skrining DASS-21',
            'text' => 'DASS-21 diproses terpisah dan bukan diagnosis.',
        ]);
    }

    protected function tearDown(): void
    {
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame(0, DB::transactionLevel());
        parent::tearDown();
    }

    public function test_canonical_routes_are_registered_with_dedicated_middleware_and_default_off(): void
    {
        $expected = [
            'checkout.session.exchange' => [ProtectCheckoutSessionHttpBoundary::class,
                'throttle:'.CheckoutSessionHttpContract::LIMITER],
            'checkout.summary' => [ProtectCheckoutSessionHttpBoundary::class,
                'throttle:'.CheckoutSessionHttpContract::LIMITER],
            'checkout.logout' => [ProtectCheckoutSessionHttpBoundary::class,
                'throttle:'.CheckoutSessionHttpContract::LIMITER, AuthenticateCheckoutSession::class,
                VerifyCheckoutSessionMutation::class],
            'checkout.unavailable' => [ProtectCheckoutSessionHttpBoundary::class,
                'throttle:'.CheckoutSessionHttpContract::LIMITER],
            'checkout.confirm' => [ProtectCheckoutSessionHttpBoundary::class,
                'throttle:'.CheckoutSessionHttpContract::LIMITER, AuthenticateCheckoutSession::class,
                VerifyCheckoutSessionJsonMutation::class],
        ];
        foreach ($expected as $name => $middleware) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertInstanceOf(RoutingRoute::class, $route, $name);
            $this->assertContains('web', $route->excludedMiddleware(), $name);
            $this->assertSame($middleware, array_values(array_filter(
                $route->gatherMiddleware(), static fn (string $item): bool => $item !== 'web',
            )), $name);
        }
        $this->assertFalse(config('assessment_integration.checkout_session.enabled'));
        $this->assertFalse(config('assessment_integration.checkout_session.http.confirmation.enabled'));
        $this->assertFalse(config('assessment_integration.checkout_session.http.confirmation.writer_enabled'));
        $this->assertSame(4096, config('assessment_integration.checkout_session.http.confirmation.max_body_bytes'));

        foreach ([['POST', '/checkout/session'], ['GET', '/checkout'], ['POST', '/checkout/logout'],
            ['GET', '/checkout/unavailable'], ['POST', '/checkout/confirm']] as [$method, $path]) {
            $response = $this->call($method, $path, [], [], [], $this->server())->assertNotFound();
            $this->assertPrivate($response);
        }
    }

    public function test_real_exchange_summary_confirmation_replay_and_logout_use_the_production_routes(): void
    {
        $this->enableCheckout();
        $fixture = $this->issued();
        $exchange = $this->exchange($fixture['raw'])->assertStatus(303)->assertRedirect('/checkout');
        $this->assertPrivate($exchange);
        $credentials = $this->credentials($exchange);

        $summary = $this->getWithCookies('/checkout', $credentials)->assertOk()
            ->assertViewHas('confirmationForm', function (mixed $form): bool {
                return is_array($form) && $form['action'] === '/checkout/confirm'
                    && array_keys($form['profile']) === ['fullName']
                    && array_keys($form['consents']) === ['psychotest', 'dass'];
            });
        $this->assertPrivate($summary);

        $confirmed = $this->confirm($credentials)->assertOk()->assertExactJson([
            'data' => ['confirmed' => true, 'replayed' => false],
        ]);
        $this->assertPrivate($confirmed);
        $this->assertPrivate($this->confirm($credentials)->assertOk()->assertExactJson([
            'data' => ['confirmed' => true, 'replayed' => true],
        ]));
        $this->getWithCookies('/checkout', $credentials)->assertOk()->assertViewHas('confirmationForm', null);
        $this->assertDatabaseCount('assessment_bills', 0);
        $this->assertDatabaseCount('assessment_entitlements', 0);
        $this->assertDatabaseCount('outbox_messages', 0);

        $logout = $this->call('POST', '/checkout/logout', ['_checkout_csrf' => $credentials['csrf']],
            $this->cookieMap($credentials), [], [...$this->server('null'),
                'HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_SEC_FETCH_MODE' => 'navigate',
                'HTTP_SEC_FETCH_DEST' => 'document'], '_checkout_csrf='.$credentials['csrf'])
            ->assertStatus(303)->assertRedirect('/checkout/unavailable');
        $this->assertPrivate($logout);
    }

    public function test_partial_consent_presents_only_the_still_required_dass_consent(): void
    {
        $this->enableCheckout();
        $fixture = $this->issued();
        $document = ConsentDocument::for('psychotest');
        DB::table('consent_records')->insert([
            'participant_id' => $fixture['participant'], 'consent_type' => 'psychotest',
            'status' => 'accepted', 'document_version' => $document->version,
            'document_hash' => $document->hash, 'consented_at' => now(),
        ]);
        $credentials = $this->credentials($this->exchange($fixture['raw'])->assertStatus(303));

        $response = $this->getWithCookies('/checkout', $credentials)->assertOk()
            ->assertViewHas('confirmationForm', function (mixed $form): bool {
                return is_array($form)
                    && array_keys($form['profile']) === ['fullName']
                    && array_keys($form['consents']) === ['dass'];
            });

        $this->assertPrivate($response);
        $xpath = $this->dom($response);
        $this->assertSame(1, $xpath->query('//form[@data-checkout-confirmation]//input[@name="consents[dass][accepted]"]')->length);
        $this->assertSame(0, $xpath->query('//form[@data-checkout-confirmation]//*[@name="consents[psychotest][accepted]"]')->length);
        $this->assertDatabaseCount('consent_records', 1);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'checkout.confirmed')->count());
    }

    public function test_presented_profile_controls_and_options_are_server_allowlisted_without_authority_facts(): void
    {
        $this->enableCheckout();
        $fixture = $this->issued(allRequiredProfileMissing: true);
        $credentials = $this->credentials($this->exchange($fixture['raw'])->assertStatus(303));

        $response = $this->getWithCookies('/checkout', $credentials)->assertOk();
        $form = $response->viewData('confirmationForm');

        $this->assertIsArray($form);
        $this->assertSame(['action', 'profile', 'consents'], array_keys($form));
        $this->assertSame(['fullName', 'birthDate', 'gender', 'educationLevel', 'intendedField', 'phone'],
            array_keys($form['profile']));
        $this->assertSame(['FEMALE', 'MALE'], array_column($form['profile']['gender']['options'], 'value'));
        $this->assertSame(['KAIGO', 'KENSETSU', 'NOUGYOU', 'SEIZOU', 'GAISHOKU', 'UMUM'],
            array_column($form['profile']['intendedField']['options'], 'value'));
        $encoded = json_encode($form, JSON_THROW_ON_ERROR);
        foreach (['email', 'organization', 'participant', 'attempt', 'payer', 'amount', 'payment', 'identity',
            'batch', 'clinical', 'entitlement'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
        $this->assertPrivate($response);
    }

    public function test_wrong_origin_query_role_and_session_fail_closed_with_private_headers(): void
    {
        $this->enableCheckout();
        $fixture = $this->issued();
        $this->assertPrivate($this->exchange($fixture['raw'], 'https://evil.example')->assertForbidden());
        $credentials = $this->credentials($this->exchange($fixture['raw'])->assertStatus(303));
        $this->assertPrivate($this->getWithCookies('/checkout?attempt=foreign', $credentials)
            ->assertStatus(303)->assertRedirect('/checkout/unavailable'));

        $this->actingAs(User::factory()->create());
        $this->assertPrivate($this->call('GET', '/checkout', [], [], [], $this->server(origin: null))
            ->assertStatus(303)->assertRedirect('/checkout/unavailable'));
        $wrong = $credentials;
        $wrong['selector'] = 'ocs1_'.str_repeat('0', 64);
        $this->assertPrivate($this->getWithCookies('/checkout', $wrong)
            ->assertStatus(303)->assertRedirect('/checkout/unavailable'));
        $this->assertPrivate($this->confirm($credentials, 'https://evil.example')->assertStatus(419));
        $this->assertDatabaseCount('consent_records', 0);
    }

    private function enableCheckout(): void
    {
        config()->set('assessment_integration.checkout_handoff.enabled', true);
        config()->set('assessment_integration.checkout_handoff.ttl_seconds', 600);
        config()->set('assessment_integration.checkout_session.enabled', true);
        config()->set('assessment_integration.checkout_session.http.confirmation.enabled', true);
        config()->set('assessment_integration.checkout_session.http.confirmation.writer_enabled', true);
        config()->set('consent.legal_review_pending', false);
        RateLimiter::clear('checkout-http:exchange:127.0.0.31');
        RateLimiter::clear('checkout-http:hydrate:127.0.0.31');
        RateLimiter::clear('checkout-http:mutation:127.0.0.31');
    }

    /** @return array{participant:int,raw:string} */
    private function issued(bool $allRequiredProfileMissing = false): array
    {
        $key = (string) Str::ulid();
        $sourceSystem = 'WIRE_'.$key;
        $packageCode = 'WIRE'.$key;
        $organization = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => 'Synthetic', 'organization_code' => $key,
            'display_name' => 'Synthetic', 'status' => 'ACTIVE', 'is_active' => true,
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $organization, 'referral_branch_id' => $organization,
            'referral_source' => 'manual', 'source_system' => $sourceSystem,
            'full_name' => null, 'birth_date' => $allRequiredProfileMissing ? null : '2000-01-02',
            'gender' => $allRequiredProfileMissing ? null : 'female',
            'education_level' => $allRequiredProfileMissing ? null : 'SMA_SMK',
            'intended_field' => $allRequiredProfileMissing ? null : 'KAIGO',
            'phone' => $allRequiredProfileMissing ? null : '620000000000',
        ]);
        $client = DB::table('integration_clients')->insertGetId([
            'organization_id' => $organization, 'client_id' => $key,
            'credential_reference' => 'synthetic-only', 'enabled' => true,
        ]);
        DB::table('integration_sources')->insert([
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
        DB::table('assessment_participants')->insert([
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
        $issued = app(RlsContextRunner::class)->run(new RlsContext('service'), fn () => app(IssueCheckoutHandoff::class)
            ->execute(new CheckoutHandoffIssueInput(IntegrationClient::findOrFail($client), $attemptPublicId,
                $sourceSystem, 'ih1_'.bin2hex(random_bytes(16)), CheckoutHandoffIntent::Issue)));

        return ['participant' => $participant, 'raw' => $issued->rawToken()];
    }

    private function exchange(string $raw, string $origin = 'https://seleksi.beasiswajepang.id'): TestResponse
    {
        return $this->call('POST', '/checkout/session', ['handoffToken' => $raw], [], [],
            $this->server($origin), 'handoffToken='.$raw);
    }

    /** @return array{selector:string,csrf:string} */
    private function credentials(TestResponse $response): array
    {
        $cookies = collect($response->headers->getCookies())->keyBy(fn ($cookie): string => $cookie->getName());

        return ['selector' => (string) $cookies->get(CheckoutSessionHttpContract::SELECTOR_COOKIE)?->getValue(),
            'csrf' => (string) $cookies->get(CheckoutSessionHttpContract::CSRF_COOKIE)?->getValue()];
    }

    /** @param array{selector:string,csrf:string} $credentials */
    private function getWithCookies(string $path, array $credentials): TestResponse
    {
        return $this->call('GET', $path, [], $this->cookieMap($credentials), [], $this->server(origin: null));
    }

    private function dom(TestResponse $response): \DOMXPath
    {
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($response->getContent());

            return new \DOMXPath($document);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @param array{selector:string,csrf:string} $credentials */
    private function confirm(array $credentials, string $origin = 'https://psikotes.oncam.id'): TestResponse
    {
        $psychotest = ConsentDocument::for('psychotest');
        $dass = ConsentDocument::for('dass');
        $body = json_encode(['profile' => ['fullName' => 'Synthetic Person'], 'consents' => [
            'psychotest' => ['accepted' => true, 'documentVersion' => $psychotest->version,
                'documentHash' => $psychotest->hash],
            'dass' => ['accepted' => true, 'documentVersion' => $dass->version, 'documentHash' => $dass->hash],
        ]], JSON_THROW_ON_ERROR);

        return $this->call('POST', '/checkout/confirm', [], $this->cookieMap($credentials), [], [
            ...$this->server($origin), 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CHECKOUT_CSRF' => $credentials['csrf'], 'HTTP_SEC_FETCH_SITE' => 'same-origin',
            'HTTP_SEC_FETCH_MODE' => 'cors', 'HTTP_SEC_FETCH_DEST' => 'empty',
        ], $body);
    }

    /** @param array{selector:string,csrf:string} $credentials
     * @return array<string,string>
     */
    private function cookieMap(array $credentials): array
    {
        return [CheckoutSessionHttpContract::SELECTOR_COOKIE => $credentials['selector'],
            CheckoutSessionHttpContract::CSRF_COOKIE => $credentials['csrf']];
    }

    /** @return array<string,string> */
    private function server(?string $origin = 'https://seleksi.beasiswajepang.id'): array
    {
        $server = ['HTTPS' => 'on', 'HTTP_HOST' => 'psikotes.oncam.id', 'SERVER_NAME' => 'psikotes.oncam.id',
            'SERVER_PORT' => '443', 'REMOTE_ADDR' => '127.0.0.31',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded'];
        if ($origin !== null) {
            $server['HTTP_ORIGIN'] = $origin;
        }

        return $server;
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
