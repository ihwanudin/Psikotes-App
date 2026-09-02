<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Integrations\CheckoutSessionLifecycle;
use App\Actions\Integrations\EstablishCheckoutSession;
use App\Actions\Integrations\InvalidCheckoutHandoff;
use App\Actions\Integrations\InvalidCheckoutSession;
use App\Data\Integrations\CheckoutSessionExchangeInput;
use App\Data\Integrations\CheckoutSessionMutationCredentials;
use App\Data\Integrations\CheckoutSessionPrincipal;
use App\Http\Requests\ExchangeCheckoutSessionRequest;
use App\Services\Integrations\CheckoutSessionHttpContract;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Real P14b1 adapter; deliberately not registered by production routes. */
final class CheckoutSessionController extends Controller
{
    public function exchange(ExchangeCheckoutSessionRequest $request, EstablishCheckoutSession $establish,
        CheckoutSessionHttpContract $contract): Response
    {
        try {
            $result = $establish->execute(new CheckoutSessionExchangeInput($request->rawHandoffToken()));
        } catch (InvalidCheckoutHandoff) {
            return redirect('/checkout/unavailable', 303);
        }
        $response = redirect('/checkout', 303);
        foreach ($contract->credentialCookies(
            $result->rawSelector(), $result->rawCsrfToken(), $result->absoluteExpiresAt,
        ) as $cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }

    public function show(Request $request): Response
    {
        $principal = $request->attributes->get(CheckoutSessionHttpContract::PRINCIPAL_ATTRIBUTE);
        $csrf = $request->cookies->get(CheckoutSessionHttpContract::CSRF_COOKIE);
        if (! $principal instanceof CheckoutSessionPrincipal || ! is_string($csrf)) {
            throw new InvalidCheckoutSession;
        }

        return response()->view('checkout.private', [
            'principal' => $principal,
            'checkoutCsrf' => $csrf,
        ]);
    }

    public function logout(Request $request, CheckoutSessionLifecycle $lifecycle,
        CheckoutSessionHttpContract $contract): Response
    {
        $selector = $request->cookies->get(CheckoutSessionHttpContract::SELECTOR_COOKIE);
        $csrf = $contract->explicitCsrf($request);
        if (! is_string($selector) || $csrf === null) {
            return response('Page Expired', 419);
        }
        try {
            $lifecycle->logout(new CheckoutSessionMutationCredentials($selector, $csrf));
        } catch (InvalidCheckoutSession) {
            return $contract->clear(redirect('/checkout/unavailable', 303));
        }

        return $contract->clear(redirect('/checkout/unavailable', 303));
    }

    public function unavailable(): Response
    {
        return response('Checkout tidak tersedia.', 200);
    }
}
