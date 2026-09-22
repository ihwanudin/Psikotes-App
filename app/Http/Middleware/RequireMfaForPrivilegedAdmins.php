<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\AdminRole;
use App\Models\Admin;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin panel MFA (2026-09-22, Lead sign-off,
 * tasks/handoffs/f2/admin-mfa-plan.md). Filament's own
 * multiFactorAuthentication() `isRequired` parameter cannot be scoped by
 * role -- it is only ever consulted at route-registration time
 * (Pages\Concerns\HasRoutes::getRouteMiddleware()), before any admin is
 * authenticated, so a role-checking closure passed there would never see a
 * real user. This middleware does the actual, per-request, role-scoped
 * enforcement instead, registered in AdminPanelProvider's authMiddleware.
 *
 * Runs after RejectDisabledAdmin: a disabled admin must be rejected there
 * regardless of their MFA status, not redirected to an MFA setup page.
 *
 * Redirects to the admin's own profile page, not Filament's built-in
 * "set up required" page: that page's route
 * (`{panel}.auth.multi-factor-authentication.set-up-required`) is itself
 * only ever registered when the panel's own `isRequired` flag is true
 * (`vendor/filament/filament/routes/web.php`) -- with `isRequired: false`
 * (required here, see above) that route simply does not exist. The profile
 * page (`->profile()`, enabled in AdminPanelProvider) already renders every
 * registered MFA provider's enable/disable toggle automatically
 * (`Filament\Auth\Pages\EditProfile::getMultiFactorAuthenticationContentComponent()`),
 * so it is the correct, always-registered place to send an admin who still
 * needs to turn it on.
 */
final class RequireMfaForPrivilegedAdmins
{
    /** @var list<AdminRole> */
    private const array REQUIRED_ROLES = [AdminRole::SuperAdmin, AdminRole::Psychologist];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user();

        if (! ($admin instanceof Admin)
            || ! in_array($admin->role, self::REQUIRED_ROLES, true)
            || $admin->hasEmailAuthentication()) {
            return $next($request);
        }

        $profileUrl = Filament::getProfileUrl();

        // Guard against redirecting to the profile page from the profile
        // page itself -- authMiddleware applies panel-wide, including the
        // profile route, and this class has no way to be excluded from
        // that per-route.
        if ($profileUrl === null || $request->fullUrlIs($profileUrl) || $request->fullUrlIs($profileUrl.'*')) {
            return $next($request);
        }

        return redirect($profileUrl);
    }
}
