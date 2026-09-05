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
use App\Models\AssessmentCharge;
use App\Models\AssessmentParticipant;
use App\Models\IntegrationClient;
use App\Security\RlsContextRunner;
use App\Services\Integrations\CheckoutSessionHttpContract as Contract;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentAccessFixture as Fixture;

final class CheckoutMandatoryDassHttpPresentationTest extends OrganizationPaymentTestCase
{
    private array $cookies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config()->set('app.url', 'https://psikotes.oncam.id');
        URL::forceRootUrl('https://psikotes.oncam.id');
        URL::forceScheme('https');
        config()->set('assessment_integration.checkout_handoff.enabled', true);
        config()->set('assessment_integration.checkout_handoff.ttl_seconds', 600);
        config()->set('assessment_integration.checkout_session', ['enabled' => true, 'idle_minutes' => 30, 'absolute_minutes' => 120,
            'terminal_retention_days' => 30, 'http' => ['destination_origin' => 'https://psikotes.oncam.id',
                'trusted_exchange_origins' => ['https://seleksi.beasiswajepang.id', 'https://seleksi.serbaindo.com'],
                'exchange_per_minute' => 10, 'hydrate_per_minute' => 60, 'mutation_per_minute' => 10]]);
        RateLimiter::for(Contract::LIMITER, fn (Request $request) => app(Contract::class)->rateLimit($request));
        $boundary = [ProtectCheckoutSessionHttpBoundary::class, 'throttle:'.Contract::LIMITER];
        Route::post('/checkout/session', [CheckoutSessionController::class, 'exchange'])->middleware($boundary);
        Route::get('/checkout', [CheckoutSessionController::class, 'summary'])->middleware($boundary);
        Route::post('/checkout/logout', [CheckoutSessionController::class, 'logout'])
            ->middleware([...$boundary, AuthenticateCheckoutSession::class, VerifyCheckoutSessionMutation::class]);
        Route::get('/checkout/unavailable', [CheckoutSessionController::class, 'unavailable'])->middleware($boundary);
        Route::getRoutes()->refreshNameLookups();

        $fixture = Fixture::create();
        DB::table('branches')->update(['status' => 'ACTIVE', 'is_active' => true]);
        DB::table('integration_clients')->update(['enabled' => true]);
        DB::table('assessment_participants')->update(['assessment_status' => 'PROVISIONED', 'funding_mode' => 'INVOICED_TO_ORGANIZATION',
            'metadata' => '{"checkout_contract_version":"checkout-v2","checkout_initial_funding_mode":null}']);
        $attempt = AssessmentParticipant::findOrFail($fixture['attempt']);
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
        DB::table('checkout_sessions')->update(['idle_expires_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '+5 minutes')")]);

        $charge = AssessmentCharge::findOrFail($fixture['charge']);
        $snapshot = $charge->price_snapshot;
        $snapshot['testTypes'] = ['dass21', 'ist'];
        $charge->update(['price_snapshot' => $snapshot]);
        DB::table('participants')->update(['full_name' => null, 'phone' => null, 'email' => null]);
        DB::table('consent_records')->where('consent_type', 'dass')->update(['status' => 'declined']);
    }

    public function test_required_dass_and_only_server_required_profile_are_private_readonly_http_output(): void
    {
        $hostile = '</script><img src=x onerror="alert(1)"> & \' 日本語';
        config()->set('consent.documents.dass.title', $hostile);
        config()->set('consent.documents.dass.text', $hostile);

        $response = $this->page()->assertOk();
        $this->assertPrivate($response);
        $this->assertSame([], $response->headers->getCookies());
        $xpath = $this->dom($response);
        $payload = json_decode($xpath->query('//script[@id="checkout-summary-v1"]')->item(0)->textContent, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('required', $payload['consents']['dass']['state']);
        $this->assertSame($hostile, $payload['consents']['dass']['document']['title']);
        $requirements = trim($xpath->query('//section[@aria-labelledby="requirements-heading"]')->item(0)->textContent);
        $this->assertStringContainsString('2 data profil wajib belum lengkap', $requirements);
        $this->assertStringContainsString('Nama lengkap', $requirements);
        $this->assertStringContainsString('Nomor telepon', $requirements);
        $this->assertStringNotContainsString('Email', $requirements);
        $this->assertStringContainsString('Persetujuan DASS-21 wajib belum tercatat', $requirements);
        $consent = trim($xpath->query('//section[@aria-labelledby="consent-heading"]')->item(0)->textContent);
        $this->assertStringContainsString('DASS-21 (wajib untuk paket ini)', $consent);
        $this->assertStringContainsString('Hasil DASS-21 tidak memengaruhi kelayakan', $consent);
        $this->assertSame($hostile, $xpath->query('//section[@aria-labelledby="consent-heading"]//h4')->item(0)->textContent);
        $this->assertSame(0, $xpath->query('//img[@src="x"] | //*[@onerror]')->length);
        $this->assertStringNotContainsString($hostile, $response->getContent());

        $this->assertSame(0, $xpath->query('//input[@type="checkbox" or @type="radio"] | //a')->length);
        $this->assertSame(1, $xpath->query('//form')->length);
        $this->assertSame(1, $xpath->query('//button')->length);
        $this->assertSame('/checkout/logout', $xpath->query('//form')->item(0)->getAttribute('action'));
        $csrf = $xpath->query('//input[@name="_checkout_csrf"]')->item(0)->getAttribute('value');
        $this->assertSame($this->cookies[Contract::CSRF_COOKIE], $csrf);
        $this->assertSame(2, substr_count($response->getContent(), $csrf));

        $logout = $this->call('POST', '/checkout/logout', ['_checkout_csrf' => $csrf], $this->cookies, [],
            $this->server('null'), '_checkout_csrf='.$csrf)->assertStatus(303)->assertRedirect('/checkout/unavailable');
        $this->assertPrivate($logout);
        $this->assertCount(2, $logout->headers->getCookies());
        foreach ($logout->headers->getCookies() as $cookie) {
            $this->assertContains($cookie->getName(), [Contract::SELECTOR_COOKIE, Contract::CSRF_COOKIE]);
            $this->assertSame('', $cookie->getValue());
            $this->assertSame('/checkout', $cookie->getPath());
            $this->assertTrue($cookie->isSecure());
            $this->assertTrue($cookie->isHttpOnly());
            $this->assertSame('lax', $cookie->getSameSite());
        }
        $this->assertSame('REVOKED', DB::table('checkout_sessions')->value('status'));
        $this->assertSame('LOGOUT', DB::table('checkout_sessions')->value('revocation_reason'));
        $this->page()->assertStatus(303)->assertRedirect('/checkout/unavailable');
    }

    private function page(): TestResponse
    {
        return $this->call('GET', '/checkout', [], $this->cookies, [], $this->server());
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

    private function assertPrivate(TestResponse $response): void
    {
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        foreach (['Pragma' => 'no-cache', 'Referrer-Policy' => 'no-referrer', 'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'self'; base-uri 'none'; frame-ancestors 'none'"] as $header => $value) {
            $this->assertSame($value, $response->headers->get($header));
        }
    }
}
