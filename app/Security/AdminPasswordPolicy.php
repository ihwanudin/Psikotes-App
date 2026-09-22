<?php

declare(strict_types=1);

namespace App\Security;

use Illuminate\Validation\Rules\Password;

/**
 * The sole source of truth for admin password strength -- applied in every
 * environment, unlike App\Providers\AppServiceProvider's Password::defaults()
 * (which is scoped to the `web` starter-kit guard/App\Models\User, gated on
 * app()->isProduction(), and deliberately left untouched here since that
 * login system is scheduled for removal per the FE plan in PR #94). Every
 * admin password-setting path (bootstrap, creation, reset, the one-time
 * set-password link) validates against this same rule, never a copy of it.
 *
 * uncompromised() calls the HIBP range API over HTTPS
 * (Illuminate\Validation\NotPwnedVerifier::search()); on any network
 * failure it catches the exception, reports it, and treats the password as
 * NOT compromised (fails open) -- Laravel's own built-in behavior, not
 * something this class adds. Kept as-is deliberately: failing closed here
 * would make admin bootstrap/creation impossible in any environment without
 * outbound HTTPS to api.pwnedpasswords.com (a real possibility in a locked-
 * down production network), which is a worse operational risk than
 * occasionally letting a compromised-but-otherwise-strong password through.
 * The other four rules (length, mixed case, numbers, symbols) apply
 * unconditionally regardless of network reachability.
 */
final class AdminPasswordPolicy
{
    public static function rule(): Password
    {
        return Password::min(12)
            ->mixedCase()
            ->letters()
            ->numbers()
            ->symbols()
            ->uncompromised();
    }
}
