<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Actions\Integrations\IssueCheckoutHandoff;
use App\Data\Integrations\CheckoutHandoffIssueInput;
use App\Enums\CheckoutHandoffIntent;
use App\Http\Controllers\CheckoutSessionController;
use App\Http\Middleware\AuthenticateCheckoutSession;
use App\Http\Middleware\ProtectCheckoutSessionHttpBoundary;
use App\Http\Middleware\VerifyCheckoutSessionMutation;
use App\Models\CheckoutSession;
use App\Models\IntegrationClient;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Integrations\CheckoutSessionHttpContract;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\OrganizationPaymentTestCase;

final class CheckoutSessionHttpTest extends OrganizationPaymentTestCase
{
    private bool $routesWereAbsent;

    protected function setUp(): void
    {
        parent::setUp();
        $command = $this->artisan('migrate', ['--force' => true]);
        if (is_int($command)) {
            $this->fail('Migration wrapper unavailable.');
        }
        $command->assertExitCode(0);
        config()->set('app.url', 'https://oncam.id');
        URL::forceRootUrl('https://oncam.id');
        URL::forceScheme('https');
        config()->set('assessment_integration.checkout_handoff.enabled', true);
        config()->set('assessment_integration.checkout_handoff.ttl_seconds', 600);
        config()->set('assessment_integration.checkout_session', [
            'enabled' => true, 'idle_minutes' => 30, 'absolute_minutes' => 120,
            'terminal_retention_days' => 30,
            'http' => [
                'destination_origin' => 'https://oncam.id',
                'trusted_exchange_origins' => [
                    'https://seleksi.beasiswajepang.id', 'https://seleksi.serbaindo.com',
                ],
                'exchange_per_minute' => 10, 'hydrate_per_minute' => 60, 'mutation_per_minute' => 10,
            ],
        ]);
        $this->routesWereAbsent = collect(Route::getRoutes()->getRoutes())
            ->every(fn ($route): bool => ! in_array($route->uri(), [
                'checkout/session', 'checkout', 'checkout/logout', 'checkout/unavailable',
            ], true));
        Route::post('/checkout/session', [CheckoutSessionController::class, 'exchange'])
            ->middleware([ProtectCheckoutSessionHttpBoundary::class])->name('test.checkout.exchange');
        Route::get('/checkout', [CheckoutSessionController::class, 'show'])
            ->middleware([ProtectCheckoutSessionHttpBoundary::class, AuthenticateCheckoutSession::class])
            ->name('test.checkout.show');
        Route::post('/checkout/logout', [CheckoutSessionController::class, 'logout'])
            ->middleware([ProtectCheckoutSessionHttpBoundary::class, AuthenticateCheckoutSession::class,
                VerifyCheckoutSessionMutation::class])->name('test.checkout.logout');
        Route::get('/checkout/unavailable', [CheckoutSessionController::class, 'unavailable'])
            ->middleware([ProtectCheckoutSessionHttpBoundary::class])->name('test.checkout.unavailable');
        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();
    }

    public function test_routes_are_test_only_and_middleware_stack_excludes_global_web_authority(): void
    {
        $this->assertTrue($this->routesWereAbsent, 'Checkout routes were already discoverable before test registration.');
        $this->assertTrue(app(CheckoutSessionHttpContract::class)->enabled());
        $probe = Request::create('/checkout/session', 'POST', server: $this->server());
        $this->assertSame('https://oncam.id', $probe->getSchemeAndHttpHost());
        $excluded = [
            'web', StartSession::class, EncryptCookies::class, AddQueuedCookiesToResponse::class,
            ValidateCsrfToken::class, 'auth', 'auth:admin', 'participant.jwt',
        ];
        foreach (['test.checkout.exchange', 'test.checkout.show', 'test.checkout.logout'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route);
            $middleware = $route->gatherMiddleware();
            foreach ($excluded as $forbidden) {
                $this->assertNotContains($forbidden, $middleware, $name);
            }
            $this->assertSame(ProtectCheckoutSessionHttpBoundary::class, $middleware[0]);
        }
    }

    /** @return iterable<string,array{string}> */
    public static function trustedOrigins(): iterable
    {
        yield 'beasiswa' => ['https://seleksi.beasiswajepang.id'];
        yield 'serbaindo' => ['https://seleksi.serbaindo.com'];
    }

    #[DataProvider('trustedOrigins')]
    public function test_cross_site_exchange_preserves_login_cookie_and_sets_two_exact_private_cookies(string $origin): void
    {
        $fixture = $this->issued();
        $loginName = (string) config('session.cookie');
        $response = $this->exchange($fixture['raw'], $origin, [$loginName => 'login-byte-exact'])
            ->assertStatus(303)->assertRedirect('/checkout');
        $this->assertPrivate($response);
        $cookies = $response->headers->getCookies();
        $this->assertCount(2, $cookies);
        $byName = collect($cookies)->keyBy(fn ($cookie): string => $cookie->getName());
        foreach ([CheckoutSessionHttpContract::SELECTOR_COOKIE, CheckoutSessionHttpContract::CSRF_COOKIE] as $name) {
            $cookie = $byName->get($name);
            $this->assertNotNull($cookie);
            $this->assertSame('/checkout', $cookie->getPath());
            $this->assertNull($cookie->getDomain());
            $this->assertTrue($cookie->isSecure());
            $this->assertTrue($cookie->isHttpOnly());
            $this->assertSame('lax', $cookie->getSameSite());
        }
        $this->assertFalse($byName->has($loginName));
        $this->assertStringNotContainsString($fixture['raw'], $this->content($response));
        $this->assertStringNotContainsString($fixture['raw'], (string) $response->headers->get('Location'));
        $this->assertDatabaseCount('checkout_sessions', 1);
    }

    public function test_exchange_transport_origin_fixation_and_replay_are_fail_closed(): void
    {
        config()->set('assessment_integration.checkout_session.http.exchange_per_minute', 60);
        $fixture = $this->issued();
        $invalidOrigins = ['', 'null', 'http://seleksi.beasiswajepang.id',
            'https://evil.example', 'https://seleksi.beasiswajepang.id.evil.example'];
        foreach ($invalidOrigins as $origin) {
            $response = $this->exchange($fixture['raw'], $origin)->assertForbidden();
            $this->assertPrivate($response);
            $this->assertStringNotContainsString($fixture['raw'], $this->content($response));
        }
        $this->call('POST', '/checkout/session?handoffToken='.$fixture['raw'], [], [], [], $this->server())
            ->assertStatus(422);
        $this->call('POST', '/checkout/session', ['handoffToken' => $fixture['raw'], 'extra' => 'x'], [], [],
            $this->server())->assertStatus(422);
        $this->call('POST', '/checkout/session', [], [CheckoutSessionHttpContract::SELECTOR_COOKIE => $fixture['raw']], [],
            $this->server())->assertStatus(422);
        $this->call('POST', '/checkout/session', [], [], [], [...$this->server(),
            'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$fixture['raw']],
            json_encode(['handoffToken' => $fixture['raw']], JSON_THROW_ON_ERROR))->assertStatus(422);
        $this->assertDatabaseCount('checkout_sessions', 0);

        $fixedSelector = 'ocs1_'.str_repeat('a', 64);
        $fixedCsrf = 'ocsrf1_'.str_repeat('b', 64);
        $created = $this->exchange($fixture['raw'], cookies: [
            CheckoutSessionHttpContract::SELECTOR_COOKIE => $fixedSelector,
            CheckoutSessionHttpContract::CSRF_COOKIE => $fixedCsrf,
        ])->assertStatus(303);
        $values = collect($created->headers->getCookies())->mapWithKeys(
            fn ($cookie): array => [$cookie->getName() => $cookie->getValue()],
        );
        $this->assertNotSame($fixedSelector, $values[CheckoutSessionHttpContract::SELECTOR_COOKIE]);
        $this->assertNotSame($fixedCsrf, $values[CheckoutSessionHttpContract::CSRF_COOKIE]);
        $replay = $this->exchange($fixture['raw'])->assertStatus(303)->assertRedirect('/checkout/unavailable');
        $this->assertCount(0, $replay->headers->getCookies());
        $this->assertDatabaseCount('checkout_sessions', 1);
    }

    public function test_hydrate_requires_both_exact_cookies_and_projects_csrf_without_selector_or_pii(): void
    {
        $fixture = $this->issued();
        $credentials = $this->credentials($this->exchange($fixture['raw'])->assertStatus(303));
        $page = $this->getWithCookies('/checkout', $credentials)->assertOk();
        $this->assertPrivate($page);
        $page->assertSee('name="checkout-csrf-token"', false)
            ->assertSee('name="_checkout_csrf"', false)
            ->assertSee($credentials['csrf'], false);
        foreach ([$credentials['selector'], $fixture['raw'], 'Synthetic Person', '620000000000',
            'synthetic-only', 'invoice', 'gateway', 'batch', 'total'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $this->content($page));
        }

        $wrong = $credentials;
        $wrong['csrf'] = 'ocsrf1_'.str_repeat('0', 64);
        $denied = $this->getWithCookies('/checkout', $wrong)->assertStatus(303)
            ->assertRedirect('/checkout/unavailable');
        $this->assertClearsCredentials($denied);
        $idor = $this->getWithCookies('/checkout?assessmentAttemptId='.Str::ulid(), $credentials)
            ->assertStatus(303)->assertRedirect('/checkout/unavailable');
        $this->assertClearsCredentials($idor);
    }

    public function test_logout_form_and_header_are_exclusive_csrf_channels_with_exact_origin(): void
    {
        foreach (['form', 'header'] as $channel) {
            $fixture = $this->issued();
            $credentials = $this->credentials($this->exchange($fixture['raw'])->assertStatus(303));
            $server = $this->server(origin: 'https://oncam.id');
            if ($channel === 'form') {
                $response = $this->call('POST', '/checkout/logout', ['_checkout_csrf' => $credentials['csrf']],
                    $this->cookieMap($credentials), [], $server);
            } else {
                $response = $this->call('POST', '/checkout/logout', [], $this->cookieMap($credentials), [],
                    [...$server, 'HTTP_X_CHECKOUT_CSRF' => $credentials['csrf'], 'CONTENT_TYPE' => 'application/json'], '');
            }
            $response->assertStatus(303)->assertRedirect('/checkout/unavailable');
            $this->assertClearsCredentials($response);
            $this->getWithCookies('/checkout', $credentials)->assertStatus(303);
            $this->assertSame(1, DB::table('audit_logs')->where('action', 'checkout_session.revoked')
                ->where('subject_id', (string) $fixture['attempt'])->count());
        }

        $fixture = $this->issued();
        $credentials = $this->credentials($this->exchange($fixture['raw'])->assertStatus(303));
        $invalid = [
            $this->call('POST', '/checkout/logout', [], $this->cookieMap($credentials), [], $this->server(origin: 'https://oncam.id')),
            $this->call('POST', '/checkout/logout', ['_checkout_csrf' => $credentials['csrf']], $this->cookieMap($credentials), [],
                [...$this->server(origin: 'https://oncam.id'), 'HTTP_X_CHECKOUT_CSRF' => $credentials['csrf']]),
            $this->call('POST', '/checkout/logout', ['_checkout_csrf' => 'ocsrf1_'.str_repeat('0', 64)],
                $this->cookieMap($credentials), [], $this->server(origin: 'https://oncam.id')),
            $this->call('POST', '/checkout/logout', ['_checkout_csrf' => $credentials['csrf']],
                $this->cookieMap($credentials), [], $this->server(origin: 'https://evil.example')),
        ];
        foreach ($invalid as $response) {
            $response->assertStatus(419);
            $this->assertPrivate($response);
            $this->assertCount(0, $response->headers->getCookies());
        }
        $this->assertDatabaseHas('checkout_sessions', ['status' => 'ACTIVE', 'active_marker' => true]);
    }

    public function test_expiry_scope_revocation_and_logout_clear_both_cookies_without_duplicate_audit(): void
    {
        $fixture = $this->issued();
        $credentials = $this->credentials($this->exchange($fixture['raw'])->assertStatus(303));
        $session = CheckoutSession::query()->sole();
        DB::table('checkout_handoffs')->where('id', $session->checkout_handoff_id)->update([
            'issued_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '-21 minutes')"),
            'consumed_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '-20 minutes')"),
            'expires_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '-11 minutes')"),
        ]);
        DB::table('checkout_sessions')->where('id', $session->id)->update([
            'established_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '-20 minutes')"),
            'last_seen_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '-10 minutes')"),
            'idle_expires_at' => DB::raw('CURRENT_TIMESTAMP'),
            'absolute_expires_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '+20 minutes')"),
        ]);
        $expired = $this->getWithCookies('/checkout', $credentials)->assertStatus(303);
        $this->assertClearsCredentials($expired);
        $this->assertDatabaseHas('checkout_sessions', ['id' => $session->id, 'status' => 'EXPIRED']);

        $second = $this->issued();
        $secondCredentials = $this->credentials($this->exchange($second['raw'])->assertStatus(303));
        DB::table('integration_clients')->where('id', $second['client'])->update(['enabled' => false]);
        $revoked = $this->getWithCookies('/checkout', $secondCredentials)->assertStatus(303);
        $this->assertClearsCredentials($revoked);
        $this->assertDatabaseHas('checkout_sessions', [
            'assessment_participant_id' => $second['attempt'], 'status' => 'REVOKED',
            'revocation_reason' => 'SCOPE_REVOKED',
        ]);
    }

    public function test_off_validation_throttle_and_unexpected_error_are_private_and_generic(): void
    {
        config()->set('assessment_integration.checkout_session.enabled', false);
        $off = $this->post('/checkout/session')->assertNotFound();
        $this->assertPrivate($off);
        config()->set('assessment_integration.checkout_session.enabled', true);

        $validation = $this->call('POST', '/checkout/session', [], [], [], $this->server())->assertStatus(422);
        $this->assertPrivate($validation);
        config()->set('assessment_integration.checkout_session.http.exchange_per_minute', 1);
        RateLimiter::clear('checkout-http:exchange:127.0.0.9');
        $fixture = $this->issued();
        $this->exchange($fixture['raw'], ip: '127.0.0.9')->assertStatus(303);
        $throttled = $this->exchange($fixture['raw'], ip: '127.0.0.9')->assertStatus(429);
        $this->assertPrivate($throttled);

        config()->set('assessment_integration.checkout_session.http.exchange_per_minute', 10);
        $failureFixture = $this->issued();
        DB::unprepared("CREATE TRIGGER checkout_http_session_failure BEFORE INSERT ON checkout_sessions
            BEGIN SELECT RAISE(ABORT, 'synthetic selector secret pii@example.test SELECT'); END");
        try {
            $failed = $this->exchange($failureFixture['raw'], ip: '127.0.0.10')->assertStatus(500);
            $this->assertPrivate($failed);
            foreach ([$failureFixture['raw'], 'synthetic selector secret', 'pii@example.test', 'SELECT', 'checkout_sessions'] as $secret) {
                $this->assertStringNotContainsString($secret, $this->content($failed));
            }
        } finally {
            DB::unprepared('DROP TRIGGER checkout_http_session_failure');
        }
    }

    public function test_http_adapter_has_no_unrelated_side_effects(): void
    {
        $before = collect(['assessment_charges', 'assessment_bills', 'assessment_bill_items',
            'assessment_entitlements', 'orders', 'entitlements', 'outbox_messages',
            'consent_records', 'identity_verifications', 'sessions'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()]);
        $fixture = $this->issued();
        $credentials = $this->credentials($this->exchange($fixture['raw'])->assertStatus(303));
        $this->getWithCookies('/checkout', $credentials)->assertOk();
        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table);
        }
    }

    /** @return array{organization:int,participant:int,client:int,source:int,package:int,attempt:int,raw:string} */
    private function issued(): array
    {
        $key = (string) Str::ulid();
        $sourceSystem = 'HTTP_SESSION_'.$key;
        $packageCode = 'H'.$key;
        $organization = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => 'Synthetic', 'organization_code' => $key,
            'display_name' => 'Synthetic', 'status' => 'ACTIVE', 'is_active' => true,
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $organization, 'referral_branch_id' => $organization,
            'referral_source' => 'manual', 'source_system' => $sourceSystem,
            'full_name' => 'Synthetic Person', 'phone' => '620000000000',
        ]);
        $client = DB::table('integration_clients')->insertGetId([
            'organization_id' => $organization, 'client_id' => $key,
            'credential_reference' => 'synthetic-only', 'enabled' => true,
        ]);
        $source = DB::table('integration_sources')->insertGetId([
            'integration_client_id' => $client, 'source_system' => $sourceSystem,
            'contract_version' => 'checkout-v2', 'allowed_assessment_packages' => json_encode([$packageCode]),
            'allowed_funding_modes' => '[]', 'status' => 'ACTIVE',
        ]);
        $package = DB::table('packages')->insertGetId([
            'code' => $packageCode, 'name' => 'Synthetic', 'amount' => 100,
            'currency' => 'IDR', 'is_active' => true,
        ]);
        DB::table('package_items')->insert(['package_id' => $package, 'test_type' => 'ist', 'sort_order' => 1]);
        $attemptPublicId = (string) Str::ulid();
        $attempt = DB::table('assessment_participants')->insertGetId([
            'organization_id' => $organization, 'integration_client_id' => $client,
            'participant_id' => $participant, 'package_id' => $package,
            'assessment_attempt_id' => $attemptPublicId, 'source_system' => $sourceSystem,
            'external_candidate_id' => $key, 'funding_mode' => 'COMMERCIAL_SELF_PAY',
            'assessment_status' => 'PROVISIONED', 'idempotency_key' => $key,
            'request_hash' => hash('sha256', $key), 'logical_assessment_key' => hash('sha256', 'logical'.$key),
            'metadata' => json_encode(['checkout_contract_version' => 'checkout-v2',
                'checkout_initial_funding_mode' => 'COMMERCIAL_SELF_PAY']),
        ]);
        $issued = app(RlsContextRunner::class)->run(new RlsContext('service'), fn () => app(IssueCheckoutHandoff::class)
            ->execute(new CheckoutHandoffIssueInput(IntegrationClient::query()->findOrFail($client),
                $attemptPublicId, $sourceSystem, 'ih1_'.bin2hex(random_bytes(16)), CheckoutHandoffIntent::Issue)));
        $raw = $issued->rawToken();
        if (! is_string($raw)) {
            throw new RuntimeException('Synthetic handoff bearer unavailable.');
        }

        return compact('organization', 'participant', 'client', 'source', 'package', 'attempt', 'raw');
    }

    /**
     * @param  array<string,string>  $cookies
     * @return TestResponse<Response>
     */
    private function exchange(string $raw, string $origin = 'https://seleksi.beasiswajepang.id',
        array $cookies = [], string $ip = '127.0.0.1'): TestResponse
    {
        return $this->call('POST', '/checkout/session', ['handoffToken' => $raw], $cookies, [],
            $this->server($origin, $ip));
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return array{selector:string,csrf:string}
     */
    private function credentials(TestResponse $response): array
    {
        $cookies = collect($response->headers->getCookies())->keyBy(fn ($cookie): string => $cookie->getName());

        return [
            'selector' => (string) $cookies->get(CheckoutSessionHttpContract::SELECTOR_COOKIE)?->getValue(),
            'csrf' => (string) $cookies->get(CheckoutSessionHttpContract::CSRF_COOKIE)?->getValue(),
        ];
    }

    /**
     * @param  array{selector:string,csrf:string}  $credentials
     * @return TestResponse<Response>
     */
    private function getWithCookies(string $path, array $credentials): TestResponse
    {
        return $this->call('GET', $path, [], $this->cookieMap($credentials), [], $this->server(origin: null));
    }

    /** @param array{selector:string,csrf:string} $credentials
     * @return array<string,string>
     */
    private function cookieMap(array $credentials): array
    {
        return [
            CheckoutSessionHttpContract::SELECTOR_COOKIE => $credentials['selector'],
            CheckoutSessionHttpContract::CSRF_COOKIE => $credentials['csrf'],
        ];
    }

    /** @return array<string,string> */
    private function server(?string $origin = 'https://seleksi.beasiswajepang.id', string $ip = '127.0.0.1'): array
    {
        $server = [
            'HTTPS' => 'on', 'HTTP_HOST' => 'oncam.id', 'SERVER_NAME' => 'oncam.id',
            'SERVER_PORT' => '443', 'REMOTE_ADDR' => $ip,
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ];
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
        $this->assertSame('no-cache', $response->headers->get('Pragma'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame("default-src 'self'; base-uri 'none'; frame-ancestors 'none'",
            $response->headers->get('Content-Security-Policy'));
    }

    /** @param TestResponse<Response> $response */
    private function assertClearsCredentials(TestResponse $response): void
    {
        $cookies = collect($response->headers->getCookies())->keyBy(fn ($cookie): string => $cookie->getName());
        foreach ([CheckoutSessionHttpContract::SELECTOR_COOKIE, CheckoutSessionHttpContract::CSRF_COOKIE] as $name) {
            $cookie = $cookies->get($name);
            $this->assertNotNull($cookie);
            $this->assertSame('', $cookie->getValue());
            $this->assertSame('/checkout', $cookie->getPath());
            $this->assertNull($cookie->getDomain());
            $this->assertTrue($cookie->isSecure());
            $this->assertTrue($cookie->isHttpOnly());
            $this->assertSame('lax', $cookie->getSameSite());
            $this->assertLessThan(time(), $cookie->getExpiresTime());
        }
    }

    /** @param TestResponse<Response> $response */
    private function content(TestResponse $response): string
    {
        $content = $response->getContent();
        $this->assertIsString($content);

        return $content;
    }
}
