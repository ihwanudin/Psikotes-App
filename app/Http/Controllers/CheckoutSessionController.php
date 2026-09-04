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

    public function summary(Request $request, CheckoutSessionLifecycle $lifecycle,
        CheckoutSessionHttpContract $contract): Response
    {
        if ($request->query->all() !== []) {
            return $contract->clear(redirect('/checkout/unavailable', 303));
        }
        $cookies = $request->cookies->all();
        $selector = $cookies[CheckoutSessionHttpContract::SELECTOR_COOKIE] ?? null;
        $csrf = $cookies[CheckoutSessionHttpContract::CSRF_COOKIE] ?? null;
        if (! is_string($selector) || ! is_string($csrf)) {
            return $contract->clear(redirect('/checkout/unavailable', 303));
        }
        try {
            $summary = $lifecycle->readSummary(new CheckoutSessionMutationCredentials($selector, $csrf))->toArray();
        } catch (InvalidCheckoutSession) {
            return $contract->clear(redirect('/checkout/unavailable', 303));
        }

        // Strict inert JSON: encoding/render errors occur after the lifecycle commit.
        $summaryJson = json_encode($summary, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);

        return response()->view('checkout.summary', [
            'summary' => $summary, 'summaryJson' => $summaryJson, 'checkoutCsrf' => $csrf,
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
