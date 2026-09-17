<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Data\Integrations\CheckoutSessionPrincipal;
use App\Services\Integrations\CheckoutSessionHttpContract;
use App\Services\Integrations\StrictCheckoutJson;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** P15-only JSON transport boundary. It must run after AuthenticateCheckoutSession. */
final readonly class VerifyCheckoutSessionJsonMutation
{
    public function __construct(
        private CheckoutSessionHttpContract $contract,
        private StrictCheckoutJson $json,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $limit = $this->contract->confirmationJsonBodyLimit();
        $principal = $request->attributes->get(CheckoutSessionHttpContract::PRINCIPAL_ATTRIBUTE);
        $delivery = $request->cookies->get(CheckoutSessionHttpContract::CSRF_COOKIE);
        $selector = $request->cookies->get(CheckoutSessionHttpContract::SELECTOR_COOKIE);
        $headers = $request->headers->all('X-Checkout-CSRF');
        $explicit = count($headers) === 1 ? $headers[0] : null;
        if ($limit === null || $request->method() !== 'POST' || $request->query->all() !== []
            || ! $principal instanceof CheckoutSessionPrincipal
            || count($request->headers->all('Origin')) !== 1 || ! $this->contract->mutationOriginMatches($request)
            || ! is_string($selector) || ! preg_match('/^ocs1_[0-9a-f]{64}$/D', $selector)
            || ! is_string($delivery) || ! preg_match('/^ocsrf1_[0-9a-f]{64}$/D', $delivery)
            || ! is_string($explicit) || ! preg_match('/^ocsrf1_[0-9a-f]{64}$/D', $explicit)
            || ! hash_equals($delivery, $explicit)
            || $request->headers->all('Sec-Fetch-Site') !== ['same-origin']
            || $request->headers->all('Sec-Fetch-Mode') !== ['cors']
            || $request->headers->all('Sec-Fetch-Dest') !== ['empty']) {
            return response('Page Expired', 419);
        }
        if ($request->headers->all('Content-Type') !== ['application/json']
            || ! $this->json->isValidObject($request->getContent(), $limit)) {
            return response('Unprocessable Content', 422);
        }

        return $next($request);
    }
}
