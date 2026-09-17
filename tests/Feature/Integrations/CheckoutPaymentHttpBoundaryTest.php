<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Actions\Integrations\IssueCheckoutHandoff;
use App\Data\Integrations\CheckoutHandoffIssueInput;
use App\Enums\CheckoutHandoffIntent;
use App\Http\Middleware\AuthenticateCheckoutSession;
use App\Http\Middleware\PreventCheckoutResponseCaching;
use App\Http\Middleware\ProtectCheckoutSessionHttpBoundary;
use App\Http\Middleware\VerifyCheckoutPaymentJsonMutation;
use App\Http\Requests\CheckoutPaymentRequest;
use App\Models\IntegrationClient;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Integrations\CheckoutSessionHttpContract;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\OrganizationPaymentTestCase;

final class CheckoutPaymentHttpBoundaryTest extends OrganizationPaymentTestCase
{
    private string $downstream = 'success';

    private int $calls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $migration = $this->artisan('migrate', ['--force' => true]);
        if ($migration instanceof PendingCommand) {
            $migration->assertExitCode(0);
        } else {
            $this->assertSame(0, $migration);
        }
        config()->set('app.debug', false);
        config()->set('app.url', 'https://psikotes.oncam.id');
        URL::forceRootUrl('https://psikotes.oncam.id');
        URL::forceScheme('https');
        Route::any('/checkout/payment', function (CheckoutPaymentRequest $request): Response {
            $this->calls++;
            if ($this->downstream === 'failure') {
                throw new RuntimeException('Synthetic Person private@example.test SELECT * FROM assessment_bills');
            }
            if ($this->downstream === 'conflict') {
                return response()->json(['error' => ['code' => 'PAYMENT_UNAVAILABLE']], 409);
            }

            return response()->json(['data' => [
                'paymentState' => $request->consultationRequested() ? 'paid' : 'pending',
                'paymentUrl' => $request->consultationRequested() ? null : 'https://pay.example/synthetic',
            ]]);
        })->withoutMiddleware('web')->middleware([
            PreventCheckoutResponseCaching::class,
            ProtectCheckoutSessionHttpBoundary::class,
            'throttle:'.CheckoutSessionHttpContract::LIMITER,
            VerifyCheckoutPaymentJsonMutation::class,
            AuthenticateCheckoutSession::class,
        ])->name('test.checkout.payment');
        Route::getRoutes()->refreshNameLookups();
    }

    protected function tearDown(): void
    {
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame(0, DB::transactionLevel());
        parent::tearDown();
    }

    public function test_defaults_are_off_typed_bounded_and_payment_has_named_throttle_classification(): void
    {
        $this->assertFalse(config('assessment_integration.checkout_session.http.payment.enabled'));
        $this->assertFalse(config('assessment_integration.checkout_session.http.payment.writer_enabled'));
        $this->assertSame(256, config('assessment_integration.checkout_session.http.payment.max_body_bytes'));

        $route = Route::getRoutes()->getByName('test.checkout.payment');
        $this->assertNotNull($route);
        $this->assertSame([
            PreventCheckoutResponseCaching::class,
            ProtectCheckoutSessionHttpBoundary::class,
            'throttle:'.CheckoutSessionHttpContract::LIMITER,
            VerifyCheckoutPaymentJsonMutation::class,
            AuthenticateCheckoutSession::class,
        ], array_values(array_filter($route->gatherMiddleware(), static fn (string $item): bool => $item !== 'web')));
        $this->enable();
        $request = Request::create('/checkout/payment', 'POST', server: ['REMOTE_ADDR' => '127.0.0.41']);
        $contract = app(CheckoutSessionHttpContract::class);
        $this->assertSame(10, $contract->limit($request));
        $this->assertSame('checkout-http:payment:127.0.0.41', $contract->rateKey($request));
        $this->assertSame(256, $contract->paymentJsonBodyLimit());
    }

    public function test_exact_boolean_body_reaches_only_the_fake_downstream_projection(): void
    {
        $credentials = $this->credentials();
        $this->assertPrivate($this->payment($credentials, '{"consultationRequested":false}')
            ->assertOk()->assertExactJson(['data' => [
                'paymentState' => 'pending',
                'paymentUrl' => 'https://pay.example/synthetic',
            ]]));
        $this->assertPrivate($this->payment($credentials, '{"consultationRequested":true}')
            ->assertOk()->assertExactJson(['data' => ['paymentState' => 'paid', 'paymentUrl' => null]]));
        $this->assertSame(2, $this->calls);
        foreach (['assessment_bills', 'assessment_charges', 'assessment_entitlements', 'outbox_messages'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    /** @param array<string, string|null> $override */
    #[DataProvider('mutationRejections')]
    public function test_method_query_origin_fetch_and_csrf_fail_as_generic_419(array $override, string $body): void
    {
        $credentials = $this->credentials();
        $method = $override['method'] ?? 'POST';
        $path = $override['path'] ?? '/checkout/payment';
        unset($override['method'], $override['path']);
        $response = $this->payment($credentials, $body, $override, $method, $path)->assertStatus(419);
        $this->assertSame('Page Expired', $response->getContent());
        $this->assertPrivate($response);
        $this->assertSame(0, $this->calls);
    }

    /** @return iterable<string, array{array<string, string|null>, string}> */
    public static function mutationRejections(): iterable
    {
        $valid = '{"consultationRequested":false}';

        yield 'wrong method' => [['method' => 'PUT'], $valid];
        yield 'query' => [['path' => '/checkout/payment?next=https://evil.example'], $valid];
        yield 'missing origin' => [['HTTP_ORIGIN' => null], $valid];
        yield 'foreign origin' => [['HTTP_ORIGIN' => 'https://evil.example'], $valid];
        yield 'null origin' => [['HTTP_ORIGIN' => 'null'], $valid];
        yield 'missing fetch site' => [['HTTP_SEC_FETCH_SITE' => null], $valid];
        yield 'cross site' => [['HTTP_SEC_FETCH_SITE' => 'cross-site'], $valid];
        yield 'navigate mode' => [['HTTP_SEC_FETCH_MODE' => 'navigate'], $valid];
        yield 'document destination' => [['HTTP_SEC_FETCH_DEST' => 'document'], $valid];
        yield 'missing csrf' => [['HTTP_X_CHECKOUT_CSRF' => null], $valid];
        yield 'wrong csrf' => [['HTTP_X_CHECKOUT_CSRF' => 'ocsrf1_'.str_repeat('0', 64)], $valid];
        yield 'csrf body fallback' => [['HTTP_X_CHECKOUT_CSRF' => null],
            '{"consultationRequested":false,"_checkout_csrf":"ocsrf1_'.str_repeat('a', 64).'"}'];
    }

    /** @param array<string, string|null> $override */
    #[DataProvider('transportRejections')]
    public function test_content_type_size_duplicate_keys_and_nonobject_json_are_422(array $override, string $body): void
    {
        $credentials = $this->credentials();
        $response = $this->payment($credentials, $body, $override)->assertStatus(422);
        $this->assertSame('Unprocessable Content', $response->getContent());
        $this->assertPrivate($response);
        $this->assertSame(0, $this->calls);
    }

    /** @return iterable<string, array{array<string, string|null>, string}> */
    public static function transportRejections(): iterable
    {
        yield 'content type parameters' => [['CONTENT_TYPE' => 'application/json; charset=UTF-8'],
            '{"consultationRequested":false}'];
        yield 'wrong accept' => [['HTTP_ACCEPT' => 'text/html'], '{"consultationRequested":false}'];
        yield 'form' => [['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], 'consultationRequested=true'];
        yield 'empty' => [[], ''];
        yield 'array' => [[], '[false]'];
        yield 'scalar' => [[], 'false'];
        yield 'malformed' => [[], '{"consultationRequested":'];
        yield 'duplicate same' => [[], '{"consultationRequested":false,"consultationRequested":false}'];
        yield 'duplicate conflict' => [[], '{"consultationRequested":false,"consultationRequested":true}'];
        yield 'oversize' => [[], '{"consultationRequested":false}'.str_repeat(' ', 230)];
    }

    #[DataProvider('schemaRejections')]
    public function test_missing_extra_and_nonboolean_fields_are_422_without_fallback(string $body): void
    {
        $credentials = $this->credentials();
        $response = $this->payment($credentials, $body)->assertStatus(422);
        $this->assertPrivate($response);
        $this->assertStringNotContainsString('private@example.test', (string) $response->getContent());
        $this->assertSame(0, $this->calls);
    }

    /** @return iterable<string, array{string}> */
    public static function schemaRejections(): iterable
    {
        yield 'missing' => ['{}'];
        yield 'null' => ['{"consultationRequested":null}'];
        yield 'integer zero' => ['{"consultationRequested":0}'];
        yield 'integer one' => ['{"consultationRequested":1}'];
        yield 'string' => ['{"consultationRequested":"https://evil.example"}'];
        yield 'extra authority' => ['{"consultationRequested":false,"amount":1}'];
    }

    public function test_header_and_cookie_never_supply_the_product_choice(): void
    {
        $credentials = $this->credentials();
        $header = $this->payment($credentials, '{}', ['HTTP_X_CONSULTATION_REQUESTED' => 'true'])
            ->assertStatus(422);
        $cookie = $this->payment($credentials, '{}', extraCookies: ['consultationRequested' => 'true'])
            ->assertStatus(422);
        $this->assertPrivate($header);
        $this->assertPrivate($cookie);
        $this->assertSame(0, $this->calls);
    }

    #[DataProvider('unavailableSettings')]
    public function test_disabled_or_invalid_payment_config_is_generic_503(string $key, mixed $value): void
    {
        $credentials = $this->credentials();
        config()->set($key, $value);
        $response = $this->payment($credentials, '{"consultationRequested":false}')
            ->assertStatus(503);
        $this->assertSame('Service Unavailable', $response->getContent());
        $this->assertPrivate($response);
        $this->assertSame(0, $this->calls);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function unavailableSettings(): iterable
    {
        yield 'disabled' => ['assessment_integration.checkout_session.http.payment.enabled', false];
        yield 'enabled untyped' => ['assessment_integration.checkout_session.http.payment.enabled', 1];
        yield 'writer disabled' => ['assessment_integration.checkout_session.http.payment.writer_enabled', false];
        yield 'writer untyped' => ['assessment_integration.checkout_session.http.payment.writer_enabled', 'true'];
        yield 'bytes untyped' => ['assessment_integration.checkout_session.http.payment.max_body_bytes', '256'];
        yield 'bytes too small' => ['assessment_integration.checkout_session.http.payment.max_body_bytes', 31];
        yield 'bytes too large' => ['assessment_integration.checkout_session.http.payment.max_body_bytes', 4097];
    }

    public function test_named_payment_throttle_is_private_and_does_not_call_downstream_twice(): void
    {
        $credentials = $this->credentials();
        config()->set('assessment_integration.checkout_session.http.mutation_per_minute', 1);
        RateLimiter::clear('checkout-http:payment:127.0.0.41');
        $this->assertPrivate($this->payment($credentials, '{"consultationRequested":false}')->assertOk());
        $response = $this->payment($credentials, '{"consultationRequested":false}')->assertStatus(429);
        $this->assertPrivate($response);
        $this->assertSame(1, $this->calls);
    }

    public function test_conflict_and_framework_500_are_private_and_sanitized(): void
    {
        $credentials = $this->credentials();
        $this->downstream = 'conflict';
        $conflict = $this->payment($credentials, '{"consultationRequested":false}')
            ->assertConflict()->assertExactJson(['error' => ['code' => 'PAYMENT_UNAVAILABLE']]);
        $this->assertPrivate($conflict);

        $reported = [];
        $handler = app(ExceptionHandler::class);
        $this->assertInstanceOf(Handler::class, $handler);
        $handler->reportable(function (RuntimeException $exception) use (&$reported): void {
            $reported[] = $exception;
        });
        $this->downstream = 'failure';
        $failure = $this->payment($credentials, '{"consultationRequested":true}')
            ->assertStatus(500)->assertExactJson(['message' => 'Server Error']);
        $this->assertPrivate($failure);
        $this->assertCount(1, $reported);
        foreach (['Synthetic Person', 'private@example.test', 'SELECT', 'assessment_bills', 'trace'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $failure->getContent());
        }
        $this->assertSame(2, $this->calls);
    }

    public function test_session_authentication_remains_the_existing_separate_middleware(): void
    {
        $this->enable();
        $credentials = [
            'selector' => 'ocs1_'.str_repeat('a', 64),
            'csrf' => 'ocsrf1_'.str_repeat('b', 64),
        ];
        $response = $this->payment($credentials, '{"consultationRequested":false}')
            ->assertStatus(303)->assertRedirect('/checkout/unavailable');
        $this->assertPrivate($response);
        $this->assertSame(0, $this->calls);
    }

    private function enable(): void
    {
        config()->set('assessment_integration.checkout_handoff.enabled', true);
        config()->set('assessment_integration.checkout_handoff.ttl_seconds', 600);
        config()->set('assessment_integration.checkout_session.enabled', true);
        config()->set('assessment_integration.checkout_session.http.payment.enabled', true);
        config()->set('assessment_integration.checkout_session.http.payment.writer_enabled', true);
        config()->set('assessment_integration.checkout_session.http.payment.max_body_bytes', 256);
        RateLimiter::clear('checkout-http:exchange:127.0.0.41');
        RateLimiter::clear('checkout-http:payment:127.0.0.41');
    }

    /** @return array{selector:string,csrf:string} */
    private function credentials(): array
    {
        $this->enable();
        $key = (string) Str::ulid();
        $source = 'PAY_'.$key;
        $packageCode = 'PAY'.$key;
        $organization = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => 'Synthetic', 'organization_code' => $key,
            'display_name' => 'Synthetic', 'status' => 'ACTIVE', 'is_active' => true,
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $organization, 'referral_branch_id' => $organization,
            'referral_source' => 'manual', 'source_system' => $source, 'full_name' => 'Synthetic Person',
            'birth_date' => '2000-01-02', 'gender' => 'female', 'education_level' => 'SMA_SMK',
            'intended_field' => 'KAIGO', 'phone' => '620000000000',
        ]);
        $client = DB::table('integration_clients')->insertGetId([
            'organization_id' => $organization, 'client_id' => $key,
            'credential_reference' => 'synthetic-only', 'enabled' => true,
        ]);
        DB::table('integration_sources')->insert([
            'integration_client_id' => $client, 'source_system' => $source,
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
        $attempt = (string) Str::ulid();
        $case = DB::table('assessment_cases')->insertGetId([
            'public_id' => $attempt,
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
            'assessment_attempt_id' => $attempt, 'source_system' => $source,
            'external_candidate_id' => $key, 'funding_mode' => 'COMMERCIAL_SELF_PAY',
            'assessment_status' => 'PROVISIONED', 'idempotency_key' => $key,
            'request_hash' => hash('sha256', $key), 'logical_assessment_key' => hash('sha256', 'logical'.$key),
            'metadata' => json_encode(['checkout_contract_version' => 'checkout-v2',
                'checkout_initial_funding_mode' => 'COMMERCIAL_SELF_PAY']),
        ]);
        $issued = app(RlsContextRunner::class)->run(new RlsContext('service'), fn () => app(IssueCheckoutHandoff::class)
            ->execute(new CheckoutHandoffIssueInput(IntegrationClient::findOrFail($client), $attempt,
                $source, 'ih1_'.bin2hex(random_bytes(16)), CheckoutHandoffIntent::Issue)));
        $body = 'handoffToken='.$issued->rawToken();
        $exchange = $this->call('POST', '/checkout/session', ['handoffToken' => $issued->rawToken()], [], [],
            [...$this->server('https://seleksi.beasiswajepang.id'),
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded'], $body)->assertStatus(303);
        $cookies = collect($exchange->headers->getCookies())->keyBy(fn ($cookie): string => $cookie->getName());

        return [
            'selector' => (string) $cookies->get(CheckoutSessionHttpContract::SELECTOR_COOKIE)?->getValue(),
            'csrf' => (string) $cookies->get(CheckoutSessionHttpContract::CSRF_COOKIE)?->getValue(),
        ];
    }

    /** @param  array{selector:string,csrf:string}  $credentials
     * @param  array<string,string|null>  $override
     * @param  array<string,string>  $extraCookies
     * @return TestResponse<Response>
     */
    private function payment(array $credentials, string $body, array $override = [],
        string $method = 'POST', string $path = '/checkout/payment', array $extraCookies = []): TestResponse
    {
        $server = [...$this->server(), 'HTTP_X_CHECKOUT_CSRF' => $credentials['csrf']];
        foreach ($override as $key => $value) {
            if ($value === null) {
                unset($server[$key]);
            } else {
                $server[$key] = $value;
            }
        }

        return $this->call($method, $path, [], [...[
            CheckoutSessionHttpContract::SELECTOR_COOKIE => $credentials['selector'],
            CheckoutSessionHttpContract::CSRF_COOKIE => $credentials['csrf'],
        ], ...$extraCookies], [], $server, $body);
    }

    /** @return array<string,string> */
    private function server(string $origin = 'https://psikotes.oncam.id'): array
    {
        return [
            'HTTPS' => 'on', 'HTTP_HOST' => 'psikotes.oncam.id', 'SERVER_NAME' => 'psikotes.oncam.id',
            'SERVER_PORT' => '443', 'REMOTE_ADDR' => '127.0.0.41', 'HTTP_ORIGIN' => $origin,
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_SEC_FETCH_MODE' => 'cors',
            'HTTP_SEC_FETCH_DEST' => 'empty',
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
