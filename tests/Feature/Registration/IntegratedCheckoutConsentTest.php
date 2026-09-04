<?php

declare(strict_types=1);

namespace Tests\Feature\Registration;

use App\Actions\Integrations\EstablishCheckoutSession;
use App\Actions\Integrations\IssueCheckoutHandoff;
use App\Data\Integrations\CheckoutHandoffIssueInput;
use App\Data\Integrations\CheckoutSessionExchangeInput;
use App\Enums\CheckoutHandoffIntent;
use App\Http\Middleware\AuthenticateCheckoutSession;
use App\Http\Middleware\ProtectCheckoutSessionHttpBoundary;
use App\Http\Middleware\VerifyCheckoutSessionJsonMutation;
use App\Models\IntegrationClient;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Integrations\CheckoutSessionHttpContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\OrganizationPaymentTestCase;

final class IntegratedCheckoutConsentTest extends OrganizationPaymentTestCase
{
    private int $downstreamCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
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
                'confirmation' => ['enabled' => true, 'max_body_bytes' => 4096],
            ],
        ]);

        RateLimiter::for(CheckoutSessionHttpContract::LIMITER,
            fn (Request $request) => app(CheckoutSessionHttpContract::class)->rateLimit($request));
        Route::match(['GET', 'POST', 'PUT'], '/checkout/confirm', function (Request $request) {
            $this->downstreamCalls++;

            return response('', 204);
        })->middleware([
            ProtectCheckoutSessionHttpBoundary::class,
            AuthenticateCheckoutSession::class,
            VerifyCheckoutSessionJsonMutation::class,
            'throttle:'.CheckoutSessionHttpContract::LIMITER,
        ]);
        Route::match(['GET', 'PUT'], '/checkout/_test/confirm-method', fn () => response('', 204))
            ->middleware([AuthenticateCheckoutSession::class, VerifyCheckoutSessionJsonMutation::class]);
    }

    public function test_exact_authenticated_json_reaches_the_downstream_boundary(): void
    {
        $fixture = $this->established();

        $this->mutation($fixture, $this->payload())->assertNoContent();
        $this->assertSame(1, $this->downstreamCalls);
    }

    public function test_boundary_is_default_off_and_validates_the_body_limit_config(): void
    {
        $fixture = $this->established();
        foreach ([null, false, true] as $enabled) {
            config()->set('assessment_integration.checkout_session.http.confirmation.enabled', $enabled);
            foreach ([null, 255, 8193, '4096'] as $bytes) {
                config()->set('assessment_integration.checkout_session.http.confirmation.max_body_bytes', $bytes);
                $this->mutation($fixture, $this->payload())->assertStatus(419)->assertSeeText('Page Expired');
            }
        }
        $this->assertSame(0, $this->downstreamCalls);
    }

    public function test_security_metadata_and_single_csrf_channel_fail_closed_without_an_oracle(): void
    {
        $fixture = $this->established();
        $cases = [
            'origin missing' => ['server' => ['HTTP_ORIGIN' => null]],
            'origin foreign' => ['server' => ['HTTP_ORIGIN' => 'https://evil.test']],
            'csrf missing' => ['server' => ['HTTP_X_CHECKOUT_CSRF' => null]],
            'csrf empty' => ['server' => ['HTTP_X_CHECKOUT_CSRF' => '']],
            'csrf foreign' => ['server' => ['HTTP_X_CHECKOUT_CSRF' => 'ocsrf1_'.str_repeat('0', 64)]],
            'csrf duplicate' => ['server' => ['HTTP_X_CHECKOUT_CSRF' => $fixture['csrf'].','.$fixture['csrf']]],
            'fetch site' => ['server' => ['HTTP_SEC_FETCH_SITE' => 'cross-site']],
            'fetch mode' => ['server' => ['HTTP_SEC_FETCH_MODE' => 'navigate']],
            'fetch dest' => ['server' => ['HTTP_SEC_FETCH_DEST' => 'document']],
        ];
        foreach ($cases as $label => $options) {
            $response = $this->mutation($fixture, $this->payload(), ...$options);
            $this->assertSame(419, $response->status(), $label);
            $response->assertSeeText('Page Expired');
        }
        $this->assertSame(0, $this->downstreamCalls);
    }

    public function test_non_post_method_is_rejected_by_the_json_boundary(): void
    {
        $fixture = $this->established();
        $this->mutation($fixture, $this->payload(), method: 'PUT', path: '/checkout/_test/confirm-method')
            ->assertStatus(419)->assertSeeText('Page Expired');
    }

    public function test_test_only_route_uses_the_existing_mutation_limiter(): void
    {
        config()->set('assessment_integration.checkout_session.http.mutation_per_minute', 1);
        $fixture = $this->established();

        $this->mutation($fixture, $this->payload())->assertNoContent();
        $this->mutation($fixture, $this->payload())->assertTooManyRequests();
        $this->assertSame(1, $this->downstreamCalls);
    }

    public function test_non_json_empty_oversize_malformed_array_and_duplicate_keys_are_422(): void
    {
        $fixture = $this->established();
        $cases = [
            'empty' => [''],
            'whitespace' => ['  '],
            'form' => ['profile=forged', 'application/x-www-form-urlencoded'],
            'multipart' => ['--boundary', 'multipart/form-data; boundary=boundary'],
            'charset' => [$this->payload(), 'application/json; charset=UTF-8'],
            'malformed' => ['{"profile":'],
            'array' => ['[]'],
            'duplicate root' => ['{"profile":{},"profile":{}}'],
            'duplicate nested escaped' => ['{"profile":{"fullName":"A","full\\u004eame":"B"}}'],
            'oversize' => ['{"padding":"'.str_repeat('x', 4090).'"}'],
        ];
        foreach ($cases as $label => $case) {
            [$body, $contentType] = $case + [1 => 'application/json'];
            $this->mutation($fixture, $body, contentType: $contentType)
                ->assertStatus(422, $label)->assertSeeText('Unprocessable Content');
        }
        $this->assertSame(0, $this->downstreamCalls);
    }

    public function test_authentication_runs_first_for_missing_foreign_and_revoked_sessions(): void
    {
        $fixture = $this->established();
        $this->mutation($fixture, $this->payload(), path: '/checkout/confirm?attempt=foreign')
            ->assertRedirect('/checkout/unavailable');
        $this->mutation($fixture, $this->payload(), cookies: [])->assertRedirect('/checkout/unavailable');
        $this->mutation($fixture, $this->payload(), cookies: [
            CheckoutSessionHttpContract::SELECTOR_COOKIE => 'ocs1_'.str_repeat('0', 64),
            CheckoutSessionHttpContract::CSRF_COOKIE => $fixture['csrf'],
        ])->assertRedirect('/checkout/unavailable');
        DB::table('checkout_sessions')->update([
            'status' => 'REVOKED', 'active_marker' => null, 'revoked_at' => now(), 'revocation_reason' => 'LOGOUT',
        ]);
        $this->mutation($fixture, $this->payload())->assertRedirect('/checkout/unavailable');
        $this->assertSame(0, $this->downstreamCalls);
    }

    public function test_existing_logout_mutation_middleware_remains_byte_identical(): void
    {
        $this->assertSame(
            '830fe3010872aa46137c06f0a30057b7baf53040d6e5dc6cca96c6c07228653c',
            hash_file('sha256', app_path('Http/Middleware/VerifyCheckoutSessionMutation.php')),
        );
    }

    /** @return array{selector:string,csrf:string} */
    private function established(): array
    {
        $key = (string) Str::ulid();
        $sourceSystem = 'P15_'.$key;
        $packageCode = 'P15'.$key;
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
        DB::table('package_items')->insert([
            ['package_id' => $package, 'test_type' => 'ist', 'sort_order' => 1],
            ['package_id' => $package, 'test_type' => 'dass21', 'sort_order' => 2],
        ]);
        $attemptPublicId = (string) Str::ulid();
        DB::table('assessment_participants')->insert([
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
            ->execute(new CheckoutHandoffIssueInput(IntegrationClient::findOrFail($client), $attemptPublicId,
                $sourceSystem, 'ih1_'.bin2hex(random_bytes(16)), CheckoutHandoffIntent::Issue)));
        $established = app(EstablishCheckoutSession::class)->execute(new CheckoutSessionExchangeInput($issued->rawToken()));

        return ['selector' => $established->rawSelector(), 'csrf' => $established->rawCsrfToken()];
    }

    private function payload(): string
    {
        return json_encode([
            'profile' => ['fullName' => 'Synthetic Person'],
            'consents' => [
                'psychotest' => ['accepted' => true, 'documentVersion' => 'draft-2026-08-25.2'],
                'dass' => ['accepted' => true, 'documentVersion' => 'draft-2026-09-05'],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    /** @param array<string, string>|null $cookies
     * @param  array<string, string|null>  $server
     */
    private function mutation(array $fixture, string $body, string $method = 'POST', string $path = '/checkout/confirm',
        string $contentType = 'application/json', ?array $cookies = null, array $server = [])
    {
        $cookies ??= [
            CheckoutSessionHttpContract::SELECTOR_COOKIE => $fixture['selector'],
            CheckoutSessionHttpContract::CSRF_COOKIE => $fixture['csrf'],
        ];
        $defaults = [
            'HTTPS' => 'on', 'HTTP_HOST' => 'psikotes.oncam.id', 'SERVER_NAME' => 'psikotes.oncam.id',
            'SERVER_PORT' => '443', 'CONTENT_TYPE' => $contentType,
            'HTTP_ORIGIN' => 'https://psikotes.oncam.id', 'HTTP_X_CHECKOUT_CSRF' => $fixture['csrf'],
            'HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_SEC_FETCH_MODE' => 'cors',
            'HTTP_SEC_FETCH_DEST' => 'empty',
        ];

        return $this->call($method, $path, [], $cookies, [], array_filter(
            array_replace($defaults, $server), static fn ($value): bool => $value !== null,
        ), $body);
    }
}
