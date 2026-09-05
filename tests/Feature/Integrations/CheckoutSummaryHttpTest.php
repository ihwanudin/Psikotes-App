<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Actions\Integrations\CheckoutSessionLifecycle;
use App\Actions\Integrations\IssueCheckoutHandoff;
use App\Data\Integrations\CheckoutHandoffIssueInput;
use App\Data\Integrations\CheckoutSessionMutationCredentials;
use App\Enums\CheckoutHandoffIntent;
use App\Http\Controllers\CheckoutSessionController;
use App\Http\Middleware\AuthenticateCheckoutSession;
use App\Http\Middleware\ProtectCheckoutSessionHttpBoundary;
use App\Http\Middleware\VerifyCheckoutSessionMutation;
use App\Models\AssessmentCharge;
use App\Models\AssessmentParticipant;
use App\Models\IntegrationClient;
use App\Models\User;
use App\Security\RlsContextRunner;
use App\Services\Integrations\CheckoutSessionHttpContract as Contract;
use DOMDocument;
use DOMXPath;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentAccessFixture as Fixture;
use Throwable;

final class CheckoutSummaryHttpTest extends OrganizationPaymentTestCase
{
    private array $fixture;

    private array $cookies;

    protected function setUp(): void
    {
        parent::setUp();
        $command = $this->artisan('migrate', ['--force' => true]);
        if (is_int($command)) {
            $this->fail('Migration wrapper unavailable.');
        }
        $command->assertExitCode(0);
        unset($command);
        config()->set('app.url', 'https://psikotes.oncam.id');
        URL::forceRootUrl('https://psikotes.oncam.id');
        URL::forceScheme('https');
        config()->set('assessment_integration.checkout_handoff.enabled', true);
        config()->set('assessment_integration.checkout_handoff.ttl_seconds', 600);
        config()->set('assessment_integration.checkout_session', ['enabled' => true, 'idle_minutes' => 30, 'absolute_minutes' => 120,
            'terminal_retention_days' => 30, 'http' => ['destination_origin' => 'https://psikotes.oncam.id',
                'trusted_exchange_origins' => ['https://seleksi.beasiswajepang.id', 'https://seleksi.serbaindo.com'],
                'exchange_per_minute' => 10, 'hydrate_per_minute' => 60, 'mutation_per_minute' => 10]]);
        $this->assertNotNull(Route::getRoutes()->getByName('checkout.summary'));
        RateLimiter::for(Contract::LIMITER, fn (Request $request) => app(Contract::class)->rateLimit($request));
        $boundary = [ProtectCheckoutSessionHttpBoundary::class, 'throttle:'.Contract::LIMITER];
        Route::post('/checkout/session', [CheckoutSessionController::class, 'exchange'])->middleware($boundary);
        Route::get('/checkout', [CheckoutSessionController::class, 'summary'])->middleware($boundary)->name('test.summary');
        Route::post('/checkout/logout', [CheckoutSessionController::class, 'logout'])
            ->middleware([...$boundary, AuthenticateCheckoutSession::class, VerifyCheckoutSessionMutation::class]);
        Route::get('/checkout/unavailable', [CheckoutSessionController::class, 'unavailable'])->middleware($boundary);
        Route::get('/test/summary-existing-auth', fn (Request $request) => response((string) $request->user()?->getAuthIdentifier()))->middleware(['web', 'auth']);
        Route::getRoutes()->refreshNameLookups();
        $this->fixture = Fixture::create();
        DB::table('branches')->update(['status' => 'ACTIVE', 'is_active' => true]);
        DB::table('integration_clients')->update(['enabled' => true]);
        DB::table('assessment_participants')->update(['assessment_status' => 'PROVISIONED', 'funding_mode' => 'INVOICED_TO_ORGANIZATION',
            'metadata' => '{"checkout_contract_version":"checkout-v2","checkout_initial_funding_mode":null}']);
        $attempt = AssessmentParticipant::findOrFail($this->fixture['attempt']);
        DB::table('integration_sources')->insert(['integration_client_id' => $attempt->integration_client_id,
            'source_system' => $attempt->source_system, 'contract_version' => 'checkout-v2', 'status' => 'ACTIVE',
            'allowed_assessment_packages' => json_encode([DB::table('packages')->where('id', $attempt->package_id)->value('code')], JSON_THROW_ON_ERROR),
            'allowed_funding_modes' => '[]']);
        $issued = app(RlsContextRunner::class)->runAsService(fn () => app(IssueCheckoutHandoff::class)->execute(
            new CheckoutHandoffIssueInput(IntegrationClient::findOrFail($attempt->integration_client_id), $attempt->assessment_attempt_id,
                $attempt->source_system, 'ih1_'.bin2hex(random_bytes(16)), CheckoutHandoffIntent::Issue)));
        $raw = $issued->rawToken();
        $exchange = $this->call('POST', '/checkout/session', ['handoffToken' => $raw], [], [],
            $this->server('https://seleksi.beasiswajepang.id'), 'handoffToken='.$raw)->assertStatus(303);
        $this->cookies = [];
        foreach ($exchange->headers->getCookies() as $cookie) {
            $this->cookies[$cookie->getName()] = $cookie->getValue();
        }
        DB::table('assessment_participants')->update(['assessment_status' => 'READY']);
        DB::table('checkout_handoffs')->update(['issued_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '-3 minutes')"),
            'consumed_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '-2 minutes')"), 'expires_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '+5 minutes')")]);
        DB::table('checkout_sessions')->update(['established_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '-2 minutes')"),
            'last_seen_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '-1 minute')"), 'idle_expires_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '+5 minutes')")]);
    }

    public function test_single_read_exact_inert_contract_and_verified_csrf_only_in_meta_and_logout(): void
    {
        $stack = Route::getRoutes()->getByName('test.summary')->gatherMiddleware();
        $this->assertSame([ProtectCheckoutSessionHttpBoundary::class, 'throttle:'.Contract::LIMITER], $stack);
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $response = $this->page()->assertOk();
            $queries = array_column(DB::getQueryLog(), 'query');
        } finally {
            DB::disableQueryLog();
        }
        $this->assertCount(1, array_filter($queries, fn ($q) => str_contains($q, 'AS current_time')));
        $this->assertCount(1, array_filter($queries, fn ($q) => str_starts_with($q, 'update "checkout_sessions"')));
        $this->assertPrivate($response);
        $this->assertSame([], $response->headers->getCookies());
        $data = $this->payload($response);
        $this->assertSame(['contractVersion', 'sourceName', 'branchName', 'packageName', 'packageSource', 'attemptLabel', 'profile',
            'identityMessage', 'payment', 'access', 'consents'], array_keys($data));
        $this->assertSame('checkout-summary-v2', $data['contractVersion']);
        $this->assertSame('paid', $data['payment']['state']);
        $this->assertSame('ready', $data['access']['state']);
        $this->assertFalse($data['payment']['actionAvailable']);
        $this->assertNull($data['payment']['action']);
        $this->assertSame($data['payment']['actionAvailable'], $data['payment']['action'] !== null);
        $this->assertFalse($data['access']['startAvailable']);
        $this->assertSame(2, substr_count($response->getContent(), $this->cookies[Contract::CSRF_COOKIE]));
        foreach ($this->cookies as $credential) {
            $this->assertStringNotContainsString($credential, json_encode($data, JSON_THROW_ON_ERROR));
        }
        $xpath = $this->dom($response);
        $this->assertSame(1, $xpath->query('//main')->length);
        $this->assertSame(1, $xpath->query('//h1')->length);
        $this->assertSame(1, $xpath->query('//form')->length);
        $this->assertSame('/checkout/logout', $xpath->query('//form')->item(0)->getAttribute('action'));
        $this->assertSame(1, $xpath->query('//script')->length);
        $this->assertSame(0, $xpath->query('//script[@src] | //input[not(@type="hidden")]')->length);
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertStringStartsWith('text/html', $this->page(['HTTP_ACCEPT' => 'application/json'])->assertOk()->headers->get('Content-Type'));
    }

    public function test_enabled_summary_exposes_only_exact_server_priced_self_action(): void
    {
        config()->set('assessment_integration.checkout_session.http.payment', [
            'enabled' => true, 'writer_enabled' => true, 'max_body_bytes' => 256,
        ]);
        DB::table('assessment_entitlements')->delete();
        DB::table('assessment_bill_items')->delete();
        DB::table('assessment_charges')->delete();
        DB::table('assessment_participants')->update(['assessment_status' => 'PROVISIONED',
            'funding_mode' => 'COMMERCIAL_SELF_PAY',
            'metadata' => '{"checkout_contract_version":"checkout-v2","checkout_initial_funding_mode":"COMMERCIAL_SELF_PAY"}']);
        DB::table('branches')->update(['allowed_payer_types' => '["self"]']);
        DB::table('integration_sources')->update(['allowed_payer_types' => '["self"]', 'locked_payer_type' => 'self']);
        DB::table('packages')->update(['consultation_amount' => 50_000]);

        $response = $this->page()->assertOk();
        $payment = $this->payload($response)['payment'];

        $this->assertSame(['payer', 'state', 'amountIdr', 'amountSource', 'consultationRequested',
            'actionAvailable', 'action'], array_keys($payment));
        $this->assertTrue($payment['actionAvailable']);
        $this->assertSame($payment['actionAvailable'], $payment['action'] !== null);
        $this->assertSame(['path', 'mode', 'currency', 'choices'], array_keys($payment['action']));
        $this->assertSame('/checkout/payment', $payment['action']['path']);
        $this->assertSame('select', $payment['action']['mode']);
        $this->assertSame('IDR', $payment['action']['currency']);
        $this->assertSame([false, true], array_column($payment['action']['choices'], 'consultationRequested'));
        foreach (['invoice_url', 'gateway_ref', 'public_reference', 'item_count', 'participantId'] as $private) {
            $this->assertStringNotContainsString($private, json_encode($payment, JSON_THROW_ON_ERROR));
        }
    }

    public function test_escaping_preserves_exact_data_and_document_without_executable_markup(): void
    {
        $hostile = '</script><img src=x onerror="alert(1)"> & \' 日本語';
        DB::table('participants')->update(['full_name' => $hostile]);
        DB::table('branches')->update(['display_name' => $hostile]);
        config()->set('consent.documents.psychotest.text', $hostile);
        $response = $this->page()->assertOk();
        $data = $this->payload($response);
        $this->assertSame($hostile, $data['branchName']);
        $this->assertSame($hostile, $data['profile'][0]['displayValue']);
        $this->assertSame($hostile, $data['consents']['psychotest']['document']['text']);
        $xpath = $this->dom($response);
        $this->assertSame(1, $xpath->query('//img')->length);
        $this->assertSame(1, $xpath->query('//img[@src="/brand/oncam-logo-full-color.png" and @alt="ONCAM"]')->length);
        $this->assertSame(0, $xpath->query('//img[@src="x"]')->length);
        foreach ($xpath->query('//@*') as $attribute) {
            $this->assertFalse(str_starts_with(strtolower($attribute->nodeName), 'on'), 'Event handler attribute must never be rendered.');
        }
        $this->assertSame($hostile, $xpath->query('//dt[text()="Cabang"]/following-sibling::dd[1]')->item(0)->textContent);
        $this->assertStringContainsString(e($hostile), $response->getContent());
        $this->assertSame(1, $this->dom($response)->query('//script')->length);
        $this->assertStringNotContainsString($hostile, $response->getContent());
    }

    public function test_cookie_is_captured_once_even_if_request_cookie_changes_during_read(): void
    {
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed): void {
            if ($armed && str_starts_with($query->sql, 'update "checkout_sessions"')) {
                $armed = false;
                request()->cookies->set(Contract::CSRF_COOKIE, 'ocsrf1_'.str_repeat('0', 64));
            }
        });
        $response = $this->page()->assertOk();
        $this->assertFalse($armed);
        $this->assertSame(2, substr_count($response->getContent(), $this->cookies[Contract::CSRF_COOKIE]));
        $this->assertStringNotContainsString('ocsrf1_'.str_repeat('0', 64), $response->getContent());
    }

    #[DataProvider('badCredentials')]
    public function test_invalid_pair_or_query_clears_exact_cookies_without_idle_or_payload(string $kind): void
    {
        $before = $this->sessionRows();
        $cookies = $this->cookies;
        $path = '/checkout';
        match ($kind) {
            'missing' => $cookies = [],
            'selector' => $cookies[Contract::SELECTOR_COOKIE] = 'ocs1_'.str_repeat('0', 64),
            'csrf' => $cookies[Contract::CSRF_COOKIE] = 'ocsrf1_'.str_repeat('0', 64),
            'array' => $cookies[Contract::CSRF_COOKIE] = ['bad'],
            'query' => $path .= '?attempt=1',
        };
        $response = $this->call('GET', $path, [], $cookies, [], $this->server())->assertStatus(303)->assertRedirect('/checkout/unavailable');
        $this->assertClear($response);
        $this->assertSame($before, $this->sessionRows());
    }

    public static function badCredentials(): iterable
    {
        foreach (['missing', 'selector', 'csrf', 'array', 'query'] as $kind) {
            yield [$kind];
        }
    }

    public function test_verified_old_principal_or_headers_cannot_replace_cookie_authority(): void
    {
        $principal = app(CheckoutSessionLifecycle::class)->hydrateWithCsrfDelivery(new CheckoutSessionMutationCredentials(
            $this->cookies[Contract::SELECTOR_COOKIE], $this->cookies[Contract::CSRF_COOKIE]));
        app('events')->listen(RouteMatched::class, fn (RouteMatched $event) => $event->request->attributes->set(Contract::PRINCIPAL_ATTRIBUTE, $principal));
        $response = $this->call('GET', '/checkout', [], [], [], [...$this->server(),
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->cookies[Contract::SELECTOR_COOKIE],
            'HTTP_X_CHECKOUT_CSRF' => $this->cookies[Contract::CSRF_COOKIE]])->assertStatus(303);
        $this->assertClear($response);
    }

    #[DataProvider('failures')]
    public function test_component_and_postcommit_failures_are_reported_private_without_partial_output(string $kind): void
    {
        $reported = [];
        app(ExceptionHandler::class)->reportable(function (Throwable $error) use (&$reported): bool {
            $reported[] = $error;

            return false;
        });
        if ($kind === 'component') {
            DB::table('participants')->update(['full_name' => ' ']);
        } elseif ($kind === 'utf8') {
            DB::table('branches')->update(['display_name' => "Invalid\xB1"]);
        } else {
            View::composer('checkout.summary', fn () => throw new RuntimeException('PRIVATE_RENDER pii@example.test SELECT secret'));
        }
        $before = $this->sessionRows();
        $response = $this->page()->assertStatus(500);
        $this->assertPrivate($response);
        $this->assertNotEmpty($reported, 'Real framework report callback was not invoked.');
        $this->assertSame([], $response->headers->getCookies());
        foreach (['checkout-summary-v1', 'Synthetic', 'PRIVATE_RENDER', 'pii@example.test', 'SELECT', ...array_values($this->cookies)] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        if ($kind === 'component') {
            $this->assertSame($before, $this->sessionRows());
        } else {
            $this->assertNotSame($before, $this->sessionRows(), 'Rendering cannot roll back an already committed lifecycle read.');
        }
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame(0, DB::transactionLevel());
    }

    public static function failures(): iterable
    {
        yield ['component'];
        yield ['utf8'];
        yield ['render'];
    }

    public function test_off_and_throttle_rejections_are_private_without_summary(): void
    {
        config()->set('assessment_integration.checkout_session.enabled', false);
        $this->assertPrivate($this->page()->assertStatus(404));
        config()->set('assessment_integration.checkout_session.enabled', true);
        config()->set('assessment_integration.checkout_session.http.hydrate_per_minute', 1);
        RateLimiter::clear('checkout-http:hydrate:127.0.0.1');
        $this->page()->assertOk();
        $response = $this->page()->assertStatus(429);
        $this->assertPrivate($response);
        $this->assertStringNotContainsString('checkout-summary-v1', $response->getContent());
    }

    public function test_summary_native_logout_and_real_encrypted_login_cookie_remain_independent(): void
    {
        $user = User::factory()->create();
        $login = $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $name = (string) config('session.cookie');
        $cookie = collect($login->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === $name);
        $this->assertNotNull($cookie);
        $raw = $cookie->getValue();
        Auth::forgetGuards();
        $this->call('GET', '/test/summary-existing-auth', [], [$name => $raw], [], $this->server())->assertOk()->assertSee((string) $user->id);
        $cookies = [...$this->cookies, $name => $raw];
        $page = $this->call('GET', '/checkout', [], $cookies, [], $this->server())->assertOk();
        $this->assertSame([], $page->headers->getCookies());
        $csrf = $this->dom($page)->query('//input[@name="_checkout_csrf"]')->item(0)->getAttribute('value');
        $logout = $this->call('POST', '/checkout/logout', ['_checkout_csrf' => $csrf], $cookies, [],
            $this->server('null'), '_checkout_csrf='.$csrf)->assertStatus(303);
        $this->assertClear($logout);
        $this->assertFalse(collect($logout->headers->getCookies())->contains(fn ($cookie) => $cookie->getName() === $name));
        Auth::forgetGuards();
        $this->call('GET', '/test/summary-existing-auth', [], [$name => $raw], [], $this->server())->assertOk()->assertSee((string) $user->id);
    }

    #[DataProvider('hostileLogout')]
    public function test_native_logout_exception_is_not_broadened(string $kind): void
    {
        $csrf = $this->cookies[Contract::CSRF_COOKIE];
        $server = $this->server($kind === 'hostile' ? 'https://evil.example' : 'null');
        if (in_array($kind, ['dual', 'header'], true)) {
            $server['HTTP_X_CHECKOUT_CSRF'] = $csrf;
        }
        if ($kind === 'metadata') {
            $server['HTTP_SEC_FETCH_SITE'] = 'cross-site';
        }
        $response = $this->call('POST', '/checkout/logout', $kind === 'header' ? [] : ['_checkout_csrf' => $csrf],
            $this->cookies, [], $server, $kind === 'header' ? '' : '_checkout_csrf='.$csrf)->assertStatus(419);
        $this->assertPrivate($response);
        $this->assertSame('ACTIVE', DB::table('checkout_sessions')->value('status'));
    }

    public static function hostileLogout(): iterable
    {
        foreach (['hostile', 'dual', 'header', 'metadata'] as $kind) {
            yield [$kind];
        }
    }

    public function test_no_charge_and_missing_profile_preserve_unknown_values_in_html_and_json(): void
    {
        DB::table('assessment_entitlements')->delete();
        DB::table('assessment_bill_items')->delete();
        DB::table('assessment_charges')->delete();
        DB::table('assessment_participants')->update(['assessment_status' => 'PROVISIONED', 'funding_mode' => null]);
        DB::table('participants')->update(['full_name' => null, 'birth_date' => null, 'gender' => null,
            'education_level' => null, 'intended_field' => null, 'email' => null, 'phone' => null]);
        $response = $this->page()->assertOk();
        $data = $this->payload($response);
        $this->assertSame('catalog', $data['packageSource']);
        $this->assertNull($data['payment']['amountIdr']);
        $this->assertNull($data['payment']['consultationRequested']);
        $this->assertSame('unselected', $data['payment']['state']);
        $this->assertSame('locked', $data['access']['state']);
        $this->assertSame(array_fill(0, 7, 'missing'), array_column($data['profile'], 'state'));
        $this->assertSame('Belum tersedia', $this->dom($response)->query('//dt[text()="Konsultasi diminta"]/following-sibling::dd[1]')->item(0)->textContent);
    }

    public function test_frozen_own_facts_and_partial_access_do_not_expose_collective_data_or_mutate_business(): void
    {
        $charge = AssessmentCharge::findOrFail($this->fixture['charge']);
        $snapshot = $charge->price_snapshot;
        $snapshot['testTypes'] = ['dass21', 'ist'];
        $charge->update(['price_snapshot' => $snapshot]);
        DB::table('consent_records')->where('consent_type', 'dass')->update(['status' => 'declined']);
        DB::table('packages')->update(['name' => 'PRIVATE_CHANGED_CATALOG', 'amount' => 999]);
        DB::table('package_items')->where('test_type', 'ist')->update(['test_type' => 'papi']);
        $other = Fixture::create(identity: ['organization' => $this->fixture['organization']]);
        DB::table('participants')->where('id', $other['participant'])->update(['full_name' => 'PRIVATE_OTHER_PROFILE']);
        DB::table('assessment_bill_items')->where('id', $other['item'])->update(['bill_id' => $this->fixture['bill']]);
        DB::table('assessment_bills')->where('id', $this->fixture['bill'])->update(['amount' => 200, 'item_count' => 2,
            'gateway_ref' => 'PRIVATE_GATEWAY', 'invoice_url' => 'https://synthetic.invalid/PRIVATE_INVOICE']);
        $tables = ['participants', 'assessment_participants', 'assessment_bills', 'assessment_bill_items',
            'assessment_charges', 'assessment_entitlements', 'consent_records', 'outbox_messages'];
        $before = array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), $tables);
        $response = $this->page()->assertOk();
        $data = $this->payload($response);
        $this->assertSame('Synthetic', $data['packageName']);
        $this->assertSame('charge_snapshot', $data['packageSource']);
        $this->assertSame(100, $data['payment']['amountIdr']);
        $this->assertSame('paid', $data['payment']['state']);
        $this->assertSame('partial', $data['access']['state']);
        $this->assertSame('required', $data['consents']['dass']['state']);
        foreach (['PRIVATE_CHANGED_CATALOG', 'PRIVATE_OTHER_PROFILE', 'PRIVATE_GATEWAY', 'PRIVATE_INVOICE'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->assertSame($before, array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), $tables));
    }

    #[DataProvider('invalidSessions')]
    public function test_current_session_or_scope_invalidity_never_renders_summary(string $kind): void
    {
        match ($kind) {
            'logout' => app(CheckoutSessionLifecycle::class)->logout(new CheckoutSessionMutationCredentials(
                $this->cookies[Contract::SELECTOR_COOKIE], $this->cookies[Contract::CSRF_COOKIE])),
            'expired' => DB::table('checkout_sessions')->update(['idle_expires_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '-1 second')")]),
            'client' => DB::table('integration_clients')->update(['enabled' => false]),
            'source' => DB::table('integration_sources')->update(['status' => 'REVOKED']),
            'participant' => DB::table('participants')->where('id', $this->fixture['participant'])->update(['deleted_at' => now()]),
        };
        $response = $this->page()->assertStatus(303)->assertRedirect('/checkout/unavailable');
        $this->assertClear($response);
        $this->assertStringNotContainsString($this->cookies[Contract::CSRF_COOKIE], $response->getContent());
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame(0, DB::transactionLevel());
    }

    public static function invalidSessions(): iterable
    {
        foreach (['logout', 'expired', 'client', 'source', 'participant'] as $kind) {
            yield [$kind];
        }
    }

    private function page(array $server = []): TestResponse
    {
        return $this->call('GET', '/checkout', [], $this->cookies, [], [...$this->server(), ...$server]);
    }

    private function server(?string $origin = null): array
    {
        return ['HTTPS' => 'on', 'HTTP_HOST' => 'psikotes.oncam.id', 'SERVER_NAME' => 'psikotes.oncam.id', 'SERVER_PORT' => '443',
            'REMOTE_ADDR' => '127.0.0.1', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded', ...($origin === null ? [] : ['HTTP_ORIGIN' => $origin])];
    }

    private function dom(TestResponse $response): DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            $document->loadHTML($response->getContent());

            return new DOMXPath($document);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function payload(TestResponse $response): array
    {
        $nodes = $this->dom($response)->query('//script[@id="checkout-summary-v1" and @type="application/json"]');
        $this->assertSame(1, $nodes->length);

        return json_decode($nodes->item(0)->textContent, true, flags: JSON_THROW_ON_ERROR);
    }

    private function sessionRows(): string
    {
        return DB::table('checkout_sessions')->orderBy('id')->get()->toJson();
    }

    private function assertPrivate(TestResponse $response): void
    {
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        foreach (['Pragma' => 'no-cache', 'Referrer-Policy' => 'no-referrer', 'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'self'; base-uri 'none'; frame-ancestors 'none'"] as $header => $value) {
            $this->assertSame($value, $response->headers->get($header));
        }
    }

    private function assertClear(TestResponse $response): void
    {
        $this->assertPrivate($response);
        $this->assertCount(2, $response->headers->getCookies());
        foreach ($response->headers->getCookies() as $cookie) {
            $this->assertContains($cookie->getName(), [Contract::SELECTOR_COOKIE, Contract::CSRF_COOKIE]);
            $this->assertSame('', $cookie->getValue());
            $this->assertSame('/checkout', $cookie->getPath());
            $this->assertNull($cookie->getDomain());
            $this->assertTrue($cookie->isSecure());
            $this->assertTrue($cookie->isHttpOnly());
            $this->assertSame('lax', $cookie->getSameSite());
            $this->assertLessThan(time(), $cookie->getExpiresTime());
        }
        $this->assertStringNotContainsString('checkout-summary-v1', $response->getContent());
    }
}
