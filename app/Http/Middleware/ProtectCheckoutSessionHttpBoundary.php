<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Integrations\CheckoutSessionHttpContract;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

final readonly class ProtectCheckoutSessionHttpBoundary
{
    public function __construct(private CheckoutSessionHttpContract $contract) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->contract->enabled() || ! $this->contract->destinationMatches($request)) {
            return $this->contract->private(response('Not Found', 404));
        }
        $limit = $this->contract->limit($request);
        if ($limit === null) {
            return $this->contract->private(response('Not Found', 404));
        }
        $key = $this->contract->rateKey($request);
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return $this->contract->private(response('Too Many Requests', 429));
        }
        RateLimiter::hit($key, 60);

        return $this->contract->private($next($request));
    }
}
