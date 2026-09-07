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
use App\Registration\ConsentDocument;
use App\Security\RlsContextRunner;
use App\Services\Integrations\CheckoutSessionHttpContract as Contract;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentAccessFixture as Fixture;

final class CheckoutMandatoryDassHttpPresentationTest extends OrganizationPaymentTestCase
{
    private array $fixture;

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
        $this->fixture = $fixture;
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
        $payload = json_decode($xpath->query('//script[@id="checkout-summary-v2"]')->item(0)->textContent, true, flags: JSON_THROW_ON_ERROR);

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

    public function test_injected_native_form_posts_only_missing_profile_and_current_mandatory_consents_to_test_callback(): void
    {
        config()->set('consent.legal_review_pending', false);
        DB::table('consent_records')->where('consent_type', 'psychotest')->update(['status' => 'declined']);
        $captured = null;
        Route::post('/checkout/confirm', function (Request $request) use (&$captured) {
            $captured = $request->request->all();

            return response('Synthetic presentation callback', 204);
        });
        Route::getRoutes()->refreshNameLookups();
        View::composer('checkout.summary', function ($view): void {
            $profile = [
                'fullName' => ['control' => 'text', 'autocomplete' => 'name'],
                'phone' => ['control' => 'tel', 'autocomplete' => 'tel'],
            ];
            $consents = [];
            foreach (['psychotest', 'dass'] as $type) {
                $document = ConsentDocument::for($type);
                $consents[$type] = ['documentVersion' => $document->version, 'documentHash' => $document->hash];
            }
            $view->with('confirmationForm', ['action' => '/checkout/confirm', 'profile' => $profile,
                'consents' => $consents]);
        });

        $page = $this->page()->assertOk();
        $this->assertPrivate($page);
        $xpath = $this->dom($page);
        $form = $xpath->query('//form[@data-checkout-confirmation]')->item(0);
        $this->assertNotNull($form);
        $this->assertSame('/checkout/confirm', $form->getAttribute('action'));
        $this->assertSame(['profile[fullName]', 'profile[phone]'], array_values(array_map(
            static fn ($node): string => $node->getAttribute('name'),
            iterator_to_array($xpath->query('.//*[starts-with(@name, "profile[")]', $form)),
        )));
        $this->assertSame(0, $xpath->query('.//*[@name="profile[email]" or @name="branchName" or @name="packageName" or @name="payer" or @name="amountIdr" or @name="access"]', $form)->length);
        $this->assertSame(2, $xpath->query('.//input[@type="checkbox" and @required and not(@checked)]', $form)->length);
        $this->assertSame(0, $xpath->query('.//input[@type="radio"] | .//*[@name="consents[dass][declined]"]', $form)->length);

        $psychotest = ConsentDocument::for('psychotest');
        $dass = ConsentDocument::for('dass');
        $payload = [
            '_checkout_csrf' => $this->cookies[Contract::CSRF_COOKIE],
            'profile' => ['fullName' => 'Peserta Sintetis', 'phone' => '+62 812 3456 7890'],
            'consents' => [
                'psychotest' => ['accepted' => 'true', 'documentVersion' => $psychotest->version,
                    'documentHash' => $psychotest->hash],
                'dass' => ['accepted' => 'true', 'documentVersion' => $dass->version, 'documentHash' => $dass->hash],
            ],
        ];
        $this->call('POST', '/checkout/confirm', $payload, $this->cookies, [], $this->server('https://psikotes.oncam.id'))
            ->assertNoContent();

        $this->assertSame($payload, $captured);
        $this->assertArrayNotHasKey('email', $captured['profile']);
        foreach (['branchName', 'packageName', 'payer', 'amountIdr', 'paid', 'access'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $captured);
        }
        $this->assertSame('READY', DB::table('assessment_participants')->value('assessment_status'));
        $this->assertSame('declined', DB::table('consent_records')->where('consent_type', 'dass')->value('status'));
    }

    #[DataProvider('paymentPresentations')]
    public function test_server_projected_payment_states_remain_readonly_and_private(
        string $payer,
        string $state,
        int $baseAmount,
        bool $consultation,
        int $amount,
        string $payerLabel,
        string $stateLabel,
    ): void {
        if ($payer === 'self') {
            $this->useSelfPayer();
        }
        if ($baseAmount === 0 && ! $consultation) {
            $this->useFreeCharge();
        } elseif ($consultation) {
            $this->useConsultationCharge($baseAmount, $amount);
        }
        if (in_array($state, ['pending', 'rejected', 'expired'], true)) {
            DB::table('assessment_bill_items')->update(['settled_at' => null]);
            DB::table('assessment_bills')->update(['status' => $state, 'paid_at' => null]);
        }
        DB::table('assessment_bills')->update([
            'invoice_url' => 'https://provider.invalid/PRIVATE_INVOICE', 'gateway_ref' => 'PRIVATE_PROVIDER_REFERENCE',
            'proof_object_key' => 'PRIVATE_PROOF_KEY']);

        $response = $this->page()->assertOk();
        $xpath = $this->dom($response);
        $payload = json_decode($xpath->query('//script[@id="checkout-summary-v2"]')->item(0)->textContent, true, flags: JSON_THROW_ON_ERROR);
        $payment = $payload['payment'];
        $section = trim($xpath->query('//section[@aria-labelledby="payment-heading"]')->item(0)->textContent);

        $this->assertSame($payer, $payment['payer']);
        $this->assertSame($state, $payment['state']);
        $this->assertSame($amount, $payment['amountIdr']);
        $this->assertSame($consultation, $payment['consultationRequested']);
        $this->assertFalse($payment['actionAvailable']);
        $this->assertNull($payment['action']);
        $this->assertSame($payment['actionAvailable'], $payment['action'] !== null);
        $this->assertSame($payerLabel, trim($xpath->query('//dt[text()="Pembayar"]/following-sibling::dd[1]')->item(0)->textContent));
        $this->assertSame($stateLabel, trim($xpath->query('//dt[text()="Status"]/following-sibling::dd[1]')->item(0)->textContent));
        $this->assertSame('Rp '.number_format($amount, 0, ',', '.'), trim($xpath->query('//dt[text()="Nominal Anda"]/following-sibling::dd[1]')->item(0)->textContent));
        $this->assertSame($consultation ? 'Ya' : 'Tidak', trim($xpath->query('//dt[text()="Konsultasi diminta"]/following-sibling::dd[1]')->item(0)->textContent));
        $this->assertSame(['payer', 'state', 'amountIdr', 'amountSource', 'consultationRequested', 'actionAvailable', 'action', ...($payer === 'organization' ? ['organizationName'] : [])], array_keys($payment));
        foreach (['PRIVATE_INVOICE', 'PRIVATE_PROVIDER_REFERENCE', 'PRIVATE_PROOF_KEY', 'provider.invalid'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        $this->assertStringContainsString('DASS-21 (wajib untuk paket ini)', $response->getContent());
        $this->assertStringContainsString('Hasil DASS-21 tidak memengaruhi kelayakan', $response->getContent());
        $this->assertSame('required', $payload['consents']['dass']['state']);
        $this->assertSame('locked', $payload['access']['state']);
        $this->assertFalse($payload['access']['startAvailable']);
        $this->assertSame(0, $xpath->query('//input[@type="checkbox" or @type="radio"] | //a')->length);
        $this->assertSame(1, $xpath->query('//button')->length);
        $this->assertSame('Keluar', trim($xpath->query('//button')->item(0)->textContent));
        $this->assertStringNotContainsString('Total batch', $section);
        $this->assertStringNotContainsString('Invoice', $section);
    }

    public static function paymentPresentations(): iterable
    {
        yield 'organization pending' => ['organization', 'pending', 100, false, 100, 'Dibayar lembaga', 'Menunggu pembayaran'];
        yield 'self paid' => ['self', 'paid', 100, false, 100, 'Bayar sendiri', 'Pembayaran lunas'];
        yield 'organization rejected' => ['organization', 'rejected', 100, false, 100, 'Dibayar lembaga', 'Pembayaran ditolak'];
        yield 'self expired' => ['self', 'expired', 100, false, 100, 'Bayar sendiri', 'Pembayaran kedaluwarsa'];
        yield 'zero without consultation' => ['organization', 'free', 0, false, 0, 'Dibayar lembaga', 'Gratis — tercatat oleh server'];
        yield 'zero package with consultation' => ['self', 'pending', 0, true, 50000, 'Bayar sendiri', 'Menunggu pembayaran'];
    }

    private function useSelfPayer(): void
    {
        DB::table('assessment_bill_items')->delete();
        DB::table('assessment_participants')->update(['funding_mode' => 'COMMERCIAL_SELF_PAY']);
        DB::table('assessment_charges')->update(['payer_type' => 'self']);
        DB::table('assessment_bills')->update(['payer_type' => 'self', 'payer_participant_id' => $this->fixture['participant']]);
        DB::table('assessment_bill_items')->insert([
            'bill_id' => $this->fixture['bill'], 'charge_id' => $this->fixture['charge'],
            'organization_id' => $this->fixture['organization'], 'participant_id' => $this->fixture['participant'],
            'payer_type' => 'self', 'payer_participant_id' => $this->fixture['participant'],
            'amount' => 100, 'currency' => 'IDR', 'settled_at' => now(),
        ]);
    }

    private function useFreeCharge(): void
    {
        DB::table('assessment_bill_items')->delete();
        $charge = AssessmentCharge::findOrFail($this->fixture['charge']);
        $snapshot = $charge->price_snapshot;
        $snapshot['baseAmount'] = $snapshot['amount'] = 0;
        $snapshot['consultationRequested'] = false;
        $charge->update(['base_amount' => 0, 'amount' => 0, 'consultation_requested' => false,
            'price_snapshot' => $snapshot, 'free_settled_at' => now()]);
    }

    private function useConsultationCharge(int $baseAmount, int $amount): void
    {
        DB::table('assessment_bill_items')->delete();
        $charge = AssessmentCharge::findOrFail($this->fixture['charge']);
        $snapshot = $charge->price_snapshot;
        $snapshot['baseAmount'] = $baseAmount;
        $snapshot['consultationRequested'] = true;
        $snapshot['consultationAmount'] = $amount - $baseAmount;
        $snapshot['amount'] = $amount;
        $charge->update(['base_amount' => $baseAmount, 'amount' => $amount, 'consultation_requested' => true,
            'consultation_amount' => $amount - $baseAmount, 'price_snapshot' => $snapshot, 'free_settled_at' => null]);
        DB::table('assessment_bills')->update(['amount' => $amount, 'status' => 'pending', 'paid_at' => null]);
        DB::table('assessment_bill_items')->insert([
            'bill_id' => $this->fixture['bill'], 'charge_id' => $this->fixture['charge'],
            'organization_id' => $this->fixture['organization'], 'participant_id' => $this->fixture['participant'],
            'payer_type' => 'self', 'payer_participant_id' => $this->fixture['participant'],
            'amount' => $amount, 'currency' => 'IDR', 'settled_at' => null,
        ]);
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
