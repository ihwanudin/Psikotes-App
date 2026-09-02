<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Integrations\CheckoutSessionHttpContract;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class VerifyCheckoutSessionMutation
{
    public function __construct(private CheckoutSessionHttpContract $contract) {}

    public function handle(Request $request, Closure $next): Response
    {
        $explicit = $this->contract->explicitCsrf($request);
        $delivery = $request->cookies->get(CheckoutSessionHttpContract::CSRF_COOKIE);
        if (! $this->contract->mutationOriginMatches($request)
            || $explicit === null || ! is_string($delivery) || ! hash_equals($delivery, $explicit)) {
            return response('Page Expired', 419);
        }

        return $next($request);
    }
}
