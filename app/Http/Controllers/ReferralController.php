<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Referral\ReferralAttribution;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

final class ReferralController extends Controller
{
    public function __invoke(
        Request $request,
        string $refCode,
        ReferralAttribution $attribution,
        RlsContextRunner $runner,
    ): RedirectResponse {
        $cookieName = (string) config('referral.cookie_name', 'psikotes_referral');
        $cookie = $request->cookie($cookieName);
        $resolution = $runner->run(
            new RlsContext('service'),
            fn () => $attribution->recordVisit(
                $refCode,
                is_string($cookie) ? $cookie : null,
                $request->ip(),
                $request->userAgent(),
            ),
        );
        $response = redirect()->route('register');

        if (! $resolution->shouldSetCookie) {
            return $response;
        }

        return $response->withCookie(Cookie::make(
            $cookieName,
            $resolution->cookiePayload,
            max(1, (int) config('referral.ttl_days', 30)) * 24 * 60,
            '/',
            config('session.domain'),
            true,
            true,
            false,
            'lax',
        ));
    }
}
