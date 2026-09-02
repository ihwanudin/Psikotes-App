<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

final readonly class CheckoutSessionHttpContract
{
    public const string SELECTOR_COOKIE = '__Secure-oncam_checkout_session';

    public const string CSRF_COOKIE = '__Secure-oncam_checkout_csrf';

    public const string PRINCIPAL_ATTRIBUTE = 'checkout_session_principal';

    public function enabled(): bool
    {
        return config('assessment_integration.checkout_session.enabled') === true && $this->settings() !== null;
    }

    public function destinationMatches(Request $request): bool
    {
        $settings = $this->settings();

        return $settings !== null && hash_equals($settings['destinationOrigin'], $request->getSchemeAndHttpHost());
    }

    public function exchangeOriginMatches(Request $request): bool
    {
        $settings = $this->settings();
        $origin = $request->headers->get('Origin');

        return $settings !== null && is_string($origin)
            && in_array($origin, $settings['trustedOrigins'], true);
    }

    public function mutationOriginMatches(Request $request): bool
    {
        $settings = $this->settings();
        $origin = $request->headers->get('Origin');

        return $settings !== null && is_string($origin)
            && hash_equals($settings['destinationOrigin'], $origin);
    }

    public function limit(Request $request): ?int
    {
        $settings = $this->settings();
        if ($settings === null) {
            return null;
        }

        return match ([$request->method(), '/'.$request->path()]) {
            ['POST', '/checkout/session'] => $settings['exchangeLimit'],
            ['GET', '/checkout'], ['GET', '/checkout/unavailable'] => $settings['hydrateLimit'],
            ['POST', '/checkout/logout'] => $settings['mutationLimit'],
            default => null,
        };
    }

    public function rateKey(Request $request): string
    {
        $kind = match ([$request->method(), '/'.$request->path()]) {
            ['POST', '/checkout/session'] => 'exchange',
            ['POST', '/checkout/logout'] => 'mutation',
            default => 'hydrate',
        };

        return 'checkout-http:'.$kind.':'.($request->ip() ?? 'unknown');
    }

    /** @return array{0:Cookie,1:Cookie} */
    public function credentialCookies(string $selector, string $csrf, CarbonInterface $absoluteExpiry): array
    {
        return [
            Cookie::create(self::SELECTOR_COOKIE, $selector, $absoluteExpiry, '/checkout', null, true, true, false, Cookie::SAMESITE_LAX),
            Cookie::create(self::CSRF_COOKIE, $csrf, $absoluteExpiry, '/checkout', null, true, true, false, Cookie::SAMESITE_LAX),
        ];
    }

    /** @return array{0:Cookie,1:Cookie} */
    public function clearedCookies(): array
    {
        return [
            Cookie::create(self::SELECTOR_COOKIE, '', 1, '/checkout', null, true, true, false, Cookie::SAMESITE_LAX),
            Cookie::create(self::CSRF_COOKIE, '', 1, '/checkout', null, true, true, false, Cookie::SAMESITE_LAX),
        ];
    }

    public function clear(Response $response): Response
    {
        foreach ($this->clearedCookies() as $cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }

    public function private(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'self'; base-uri 'none'; frame-ancestors 'none'");

        return $response;
    }

    public function explicitCsrf(Request $request): ?string
    {
        if ($request->query->all() !== []) {
            return null;
        }
        $formPresent = $request->request->has('_checkout_csrf');
        $headerPresent = $request->headers->has('X-Checkout-CSRF');
        if ($formPresent === $headerPresent) {
            return null;
        }
        if ($formPresent) {
            if ($request->headers->get('Content-Type') !== 'application/x-www-form-urlencoded'
                || array_keys($request->request->all()) !== ['_checkout_csrf']) {
                return null;
            }
            $value = $request->request->get('_checkout_csrf');
        } else {
            if ($request->request->all() !== [] || $request->getContent() !== '') {
                return null;
            }
            $value = $request->headers->get('X-Checkout-CSRF');
        }

        return is_string($value) && preg_match('/^ocsrf1_[0-9a-f]{64}$/D', $value) ? $value : null;
    }

    /** @return array{destinationOrigin:string,trustedOrigins:list<string>,exchangeLimit:int,hydrateLimit:int,mutationLimit:int}|null */
    private function settings(): ?array
    {
        $origin = config('assessment_integration.checkout_session.http.destination_origin');
        $trusted = config('assessment_integration.checkout_session.http.trusted_exchange_origins');
        $exchange = config('assessment_integration.checkout_session.http.exchange_per_minute');
        $hydrate = config('assessment_integration.checkout_session.http.hydrate_per_minute');
        $mutation = config('assessment_integration.checkout_session.http.mutation_per_minute');
        if (! is_string($origin) || $origin !== 'https://oncam.id'
            || $trusted !== ['https://seleksi.beasiswajepang.id', 'https://seleksi.serbaindo.com']
            || ! is_int($exchange) || $exchange < 1 || $exchange > 60
            || ! is_int($hydrate) || $hydrate < 1 || $hydrate > 120
            || ! is_int($mutation) || $mutation < 1 || $mutation > 60) {
            return null;
        }

        return [
            'destinationOrigin' => $origin, 'trustedOrigins' => $trusted,
            'exchangeLimit' => $exchange, 'hydrateLimit' => $hydrate, 'mutationLimit' => $mutation,
        ];
    }
}
