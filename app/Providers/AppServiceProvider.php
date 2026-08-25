<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\IdentityMatcher;
use App\Contracts\PaymentProvider;
use App\Contracts\RunsRlsContext;
use App\Security\RlsContextRunner;
use App\Services\Identity\ManualReviewIdentityMatcher;
use App\Services\Payments\XenditProvider;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
        $this->app->bind(PaymentProvider::class, XenditProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
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
        RateLimiter::for('identity-evidence-access', fn (Request $request): Limit => Limit::perMinute(30)
            ->by((string) ($request->user('admin')?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('participant-login', fn (Request $request): Limit => Limit::perMinute(5)
            ->by((string) $request->ip())
            ->response(fn (Request $request, array $headers) => response()->json([
                'error' => [
                    'code' => 'RATE_LIMITED',
                    'message' => 'Terlalu banyak percobaan. Silakan coba lagi nanti.',
                ],
            ], 429, $headers)));
    }
}
