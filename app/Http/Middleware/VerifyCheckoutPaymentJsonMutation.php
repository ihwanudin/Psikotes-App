<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Integrations\CheckoutSessionHttpContract;
use App\Services\Integrations\StrictCheckoutJson;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Payment-only transport boundary; authentication remains the next separate middleware. */
final readonly class VerifyCheckoutPaymentJsonMutation
{
    public function __construct(
        private CheckoutSessionHttpContract $contract,
        private StrictCheckoutJson $json,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $limit = $this->contract->paymentJsonBodyLimit();
        if ($limit === null) {
            return response('Service Unavailable', 503);
        }

        $delivery = $request->cookies->get(CheckoutSessionHttpContract::CSRF_COOKIE);
        $selector = $request->cookies->get(CheckoutSessionHttpContract::SELECTOR_COOKIE);
        $headers = $request->headers->all('X-Checkout-CSRF');
        $explicit = count($headers) === 1 ? $headers[0] : null;
        if ($request->method() !== 'POST' || $request->query->all() !== []
            || count($request->headers->all('Origin')) !== 1 || ! $this->contract->mutationOriginMatches($request)
            || ! is_string($selector) || preg_match('/^ocs1_[0-9a-f]{64}$/D', $selector) !== 1
            || ! is_string($delivery) || preg_match('/^ocsrf1_[0-9a-f]{64}$/D', $delivery) !== 1
            || ! is_string($explicit) || preg_match('/^ocsrf1_[0-9a-f]{64}$/D', $explicit) !== 1
            || ! hash_equals($delivery, $explicit)
            || $request->headers->all('Sec-Fetch-Site') !== ['same-origin']
            || $request->headers->all('Sec-Fetch-Mode') !== ['cors']
            || $request->headers->all('Sec-Fetch-Dest') !== ['empty']) {
            return response('Page Expired', 419);
        }
        if ($request->headers->all('Content-Type') !== ['application/json']
            || $request->headers->all('Accept') !== ['application/json']
            || ! $this->json->isValidObject($request->getContent(), $limit)) {
            return response('Unprocessable Content', 422);
        }

        return $next($request);
    }
}
