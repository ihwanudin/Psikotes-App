<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\IdentityMatcher;
use App\Contracts\Notifier;
use App\Contracts\PaymentProvider;
use App\Contracts\RunsRlsContext;
use App\Security\RlsContextRunner;
use App\Services\Identity\ManualReviewIdentityMatcher;
use App\Services\Integrations\GenericAssessmentResultCallbackConfiguration;
use App\Services\Notifications\N8nNotifier;
use App\Services\Payments\XenditProvider;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(RlsContextRunner::class);
        $this->app->alias(RlsContextRunner::class, RunsRlsContext::class);
        $this->app->bind(IdentityMatcher::class, ManualReviewIdentityMatcher::class);
        $this->app->bind(Notifier::class, N8nNotifier::class);
        $this->app->bind(PaymentProvider::class, XenditProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        if (config('app.env') !== 'production') {
            return;
        }

        $missing = $this->missingProductionConfiguration();

        if ($missing !== []) {
            throw new RuntimeException(
                'Konfigurasi production belum lengkap: '.implode(', ', $missing),
            );
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );

        RateLimiter::for('registrations', fn (Request $request): Limit => Limit::perMinute(5)
            ->by((string) $request->ip()));
        RateLimiter::for('identity-evidence-uploads', fn (Request $request): Limit => Limit::perMinute(5)
            ->by($request->session()->getId().'|'.(string) $request->ip()));
        RateLimiter::for('manual-payment-proof-uploads', fn (Request $request): Limit => Limit::perMinute(5)
            ->by($request->session()->getId().'|'.(string) $request->ip()));
        RateLimiter::for('identity-evidence-access', fn (Request $request): Limit => Limit::perMinute(30)
            ->by((string) ($request->user('admin')?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('manual-payment-proof-access', fn (Request $request): Limit => Limit::perMinute(30)
            ->by((string) ($request->user('admin')?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('participant-login', fn (Request $request): Limit => Limit::perMinute(5)
            ->by((string) $request->ip())
            ->response(fn (Request $request, array $headers) => response()->json([
                'error' => [
                    'code' => 'RATE_LIMITED',
                    'message' => 'Terlalu banyak percobaan. Silakan coba lagi nanti.',
                ],
            ], 429, $headers)));
        RateLimiter::for('selection-integration', fn (Request $request): Limit => Limit::perMinute(120)
            ->by((string) $request->ip())
            ->response(fn (Request $request, array $headers) => response()->json([
                'error' => [
                    'code' => 'RATE_LIMITED',
                    'message' => 'Terlalu banyak permintaan layanan.',
                ],
            ], 429, $headers)));
    }

    /** @return list<string> */
    private function missingProductionConfiguration(): array
    {
        $requirements = [
            'APP_KEY' => filled(config('app.key')),
            'APP_DEBUG_FALSE' => config('app.debug') === false,
            'APP_URL_HTTPS' => $this->isHttpsUrl(config('app.url')),
            'DB_CONNECTION_PGSQL' => config('database.default') === 'pgsql',
            'DB_HOST' => filled(config('database.connections.pgsql.host')),
            'DB_DATABASE' => filled(config('database.connections.pgsql.database')),
            'DB_USERNAME' => filled(config('database.connections.pgsql.username')),
            'DB_PASSWORD' => filled(config('database.connections.pgsql.password')),
            'CACHE_STORE_REDIS' => config('cache.default') === 'redis',
            'QUEUE_CONNECTION_REDIS' => config('queue.default') === 'redis',
            'SESSION_DRIVER_REDIS' => config('session.driver') === 'redis',
            'SESSION_SECURE_COOKIE' => config('session.secure') === true,
            'REDIS_PASSWORD' => filled(config('database.redis.default.password')),
            'PARTICIPANT_JWT_SECRET' => $this->isValidParticipantJwtSecret(
                config('participant_auth.jwt.secret'),
            ),
        ];

        if ((bool) config('selection_integration.enabled')) {
            $requirements += [
                'SELECTION_INTEGRATION_CLIENT_ID' => filled(
                    config('selection_integration.client_id'),
                ),
                'SELECTION_INTEGRATION_CLIENT_SECRET' => is_string(
                    config('selection_integration.client_secret'),
                ) && strlen((string) config('selection_integration.client_secret')) >= 32,
                'SELECTION_INTEGRATION_BRANCH_REF' => filled(
                    config('selection_integration.branch_ref'),
                ),
                'SELECTION_INTEGRATION_TEST_TYPES' => config('selection_integration.test_types') !== [],
                'SELECTION_APP_BASE_URL_HTTPS' => $this->isHttpsUrl(
                    config('selection_integration.selection_base_url'),
                ),
                'SELECTION_APP_ALLOW_INSECURE_LOCAL_HTTP_FALSE' => config(
                    'selection_integration.allow_insecure_local_http',
                ) === false,
            ];
        }

        if ((bool) config('selection_integration.result_callback_enabled')) {
            $requirements += app(GenericAssessmentResultCallbackConfiguration::class)->requirements();
        }

        if ((bool) config('selection_integration.result_poll_enabled')) {
            $pollSecret = config('selection_integration.client_secret');
            $pollTolerance = config('selection_integration.signature_tolerance_seconds');
            $requirements += [
                'SELECTION_INTEGRATION_CLIENT_ID' => filled(config('selection_integration.client_id')),
                'SELECTION_INTEGRATION_CLIENT_SECRET' => is_string($pollSecret)
                    && strlen($pollSecret) >= 32,
                'SELECTION_INTEGRATION_SIGNATURE_TOLERANCE_SECONDS' => is_int($pollTolerance)
                    && $pollTolerance >= 30 && $pollTolerance <= 900,
            ];
        }

        return array_keys(array_filter($requirements, static fn (bool $valid): bool => ! $valid));
    }

    private function isValidParticipantJwtSecret(mixed $value): bool
    {
        if (! is_string($value) || ! str_starts_with($value, 'base64:')) {
            return false;
        }

        $decoded = base64_decode(substr($value, 7), true);

        return is_string($decoded) && strlen($decoded) >= 32;
    }

    private function isHttpsUrl(mixed $value): bool
    {
        return is_string($value)
            && filter_var($value, FILTER_VALIDATE_URL) !== false
            && parse_url($value, PHP_URL_SCHEME) === 'https';
    }
}
