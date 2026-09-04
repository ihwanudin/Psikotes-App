<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Data\Integrations\CheckoutSessionPrincipal;
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
        if (count($request->headers->all('Origin')) !== 1
            || (! $this->contract->mutationOriginMatches($request) && ! $this->nativeLogoutWithSuppressedOrigin($request))
            || $explicit === null || ! is_string($delivery) || ! hash_equals($delivery, $explicit)) {
            return response('Page Expired', 419);
        }

        return $next($request);
    }

    private function nativeLogoutWithSuppressedOrigin(Request $request): bool
    {
        // A literal null is not trusted: only the authenticated native logout form qualifies.
        if ($request->method() !== 'POST' || $request->getPathInfo() !== '/checkout/logout'
            || ! $this->contract->destinationMatches($request)
            || $request->headers->all('Origin') !== ['null']
            || ! $request->attributes->get(CheckoutSessionHttpContract::PRINCIPAL_ATTRIBUTE) instanceof CheckoutSessionPrincipal
            || ! $request->request->has('_checkout_csrf') || $request->headers->has('X-Checkout-CSRF')) {
            return false;
        }
        foreach (['Sec-Fetch-Site' => 'same-origin', 'Sec-Fetch-Mode' => 'navigate',
            'Sec-Fetch-Dest' => 'document'] as $header => $expected) {
            if ($request->headers->has($header) && $request->headers->all($header) !== [$expected]) {
                return false;
            }
        }

        // explicitCsrf still enforces canonical bounded raw form bytes; auth verified both cookie digests.
        return true;
    }
}
