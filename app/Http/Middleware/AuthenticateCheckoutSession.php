<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\Integrations\CheckoutSessionLifecycle;
use App\Actions\Integrations\InvalidCheckoutSession;
use App\Data\Integrations\CheckoutSessionMutationCredentials;
use App\Services\Integrations\CheckoutSessionHttpContract;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class AuthenticateCheckoutSession
{
    public function __construct(
        private CheckoutSessionLifecycle $lifecycle,
        private CheckoutSessionHttpContract $contract,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->query->all() !== []) {
            return $this->unavailable();
        }
        $selector = $request->cookies->get(CheckoutSessionHttpContract::SELECTOR_COOKIE);
        $csrf = $request->cookies->get(CheckoutSessionHttpContract::CSRF_COOKIE);
        if (! is_string($selector) || ! is_string($csrf)) {
            return $this->unavailable();
        }
        try {
            $principal = $this->lifecycle->hydrateWithCsrfDelivery(
                new CheckoutSessionMutationCredentials($selector, $csrf),
            );
        } catch (InvalidCheckoutSession) {
            return $this->unavailable();
        }
        $request->attributes->set(CheckoutSessionHttpContract::PRINCIPAL_ATTRIBUTE, $principal);

        return $next($request);
    }

    private function unavailable(): Response
    {
        return $this->contract->clear(redirect('/checkout/unavailable', 303));
    }
}
