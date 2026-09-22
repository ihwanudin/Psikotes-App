# F2 — Admin panel MFA (email OTP) plan (2026-09-22)

**Status: plan only, no code.** Owner's decision: email OTP, not an
authenticator app. This closes the deferred item 7 from
`tasks/handoffs/f2/admin-account-lifecycle-plan.md` ("MFA — options noted,
not designed") now that #104 (admin bootstrap/lifecycle) and #100 (legacy
entitlement provisioning / bridge funding) are both done, per Lead's stated
priority order.

## What already exists: Filament 5 ships email-OTP, unused

`composer.json` already pins `filament/filament: ~5.0`, and the vendored
package includes a **complete, working email-code multi-factor provider** —
`Filament\Auth\MultiFactor\Email\EmailAuthentication`
(`vendor/filament/filament/src/Auth/MultiFactor/Email/EmailAuthentication.php`).
It is not wired into `AdminPanelProvider.php` at all today (confirmed: no
`->multiFactorAuthentication(...)` call anywhere in this codebase).

What it already does, out of the box:
- Generates a 6-digit code (`generateCode()`, overridable), hashes and
  stores it session-side with an expiry (`codeExpiryMinutes()`, default 4
  minutes, fluently configurable per provider instance).
- Sends it via `$user->notify(...)` — reuses `Admin`'s existing `Notifiable`
  trait (`use Notifiable, SoftDeletes;`, `Admin.php`), so no new mail
  plumbing is needed for the *delivery mechanism* itself.
- Own rate limiting on resend (`RateLimiter::tooManyAttempts(...,
  maxAttempts: 2)`), independent of this app's own named `throttle:`
  limiters.
- A full login-challenge form component (`getChallengeFormComponents()`,
  with a working "resend code" action), a management-schema toggle for the
  admin's own profile page (enable/disable), and the `SetUpRequiredMultiFactor
  Authentication` page Filament auto-registers a route for.
- No recovery-codes concept (unlike the TOTP `AppAuthentication` provider) —
  not needed for email OTP, since the admin's email is already the same
  channel `ResetAdminPasswordCommand`/`AdminPasswordSetupController` treat
  as the recovery channel for the account itself.

**This means implementation is mostly wiring, not building** — register the
provider, add one column + one interface + one trait to `Admin`, and (see
critical finding below) one small custom middleware.

## Critical finding: Filament's own `isRequired` cannot be scoped by role

The panel builder's `multiFactorAuthentication(providers, setUpRequiredAction,
isRequired)` method (`HasAuth.php:666`) accepts `isRequired` as `bool |
Closure`. The natural design would be a role-checking closure — e.g. "require
it for `super_admin` and `psychologist` only" — but this **does not work**:

`isRequired` is only ever consumed through
`Pages\Concerns\HasRoutes::getRouteMiddleware()`
(`vendor/filament/filament/src/Pages/Concerns/HasRoutes.php:86-94`), which
Filament calls once **at route registration time** (Laravel boot), not
per-request:

```php
public static function getRouteMiddleware(Panel $panel): string | array
{
    return [
        ...(static::isMultiFactorAuthenticationRequired($panel) ? [...] : []),
        ...
    ];
}
```

At boot time there is no authenticated admin at all, so a role-checking
closure passed as `isRequired` would evaluate against no user, every time,
and the enforcement middleware (`EnsureMultiFactorAuthenticationIsEnabled`)
would either never attach (closure defaults to false with no user) or attach
unconditionally for every role (if written to fail open/closed the other
way) — there is no way to make Filament's own built-in enforcement
role-aware. Confirmed by reading the only call site; not inferred from docs.

**Consequence for the design below**: `isRequired` is registered as `false`
(Filament never forces setup on its own), and role-scoped enforcement is a
**small custom middleware**, `RequireMfaForPrivilegedAdmins` (name
illustrative), mirroring `App\Http\Middleware\RejectDisabledAdmin`'s exact
shape (same file, same pattern, registered the same way):

```php
final class RequireMfaForPrivilegedAdmins
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user();
        if ($admin instanceof Admin
            && in_array($admin->role, self::REQUIRED_ROLES, true)
            && ! $admin->hasEmailAuthentication()) {
            return redirect(Filament::getSetUpRequiredMultiFactorAuthenticationUrl());
        }
        return $next($request);
    }
}
```

Registered in `AdminPanelProvider.php`'s `authMiddleware` array (runs
per-request, unlike route-level middleware arrays built at boot — confirmed
by the same reasoning `RejectDisabledAdmin` already relies on there), placed
after `Authenticate::class` (needs a resolved admin) and after the new
`RejectDisabledAdmin`/`admin.not-disabled` check (a disabled admin should
never even reach an MFA prompt).

## Who must have it enabled — this is my technical call, not the owner's

Recommendation: **`super_admin` and `psychologist` only**, matching the
admin-lifecycle plan's original framing (`admin-account-lifecycle-plan.md`
§7): `super_admin` can do essentially anything in the system;
`psychologist` accounts sign and (once `central_admin` lands) publish
legally-relevant HPP reports bearing a real SILP/STR number. `branch_admin`/
`staff` are lower-privilege and branch-scoped; not required for now, may
enable voluntarily (Filament's own per-admin toggle stays available to
everyone regardless of the role list, since the ability to *turn it on* is
provider-level, not gated by the enforcement middleware).

`central_admin` is not on the required list only because the role doesn't
exist yet — extending `REQUIRED_ROLES` to include it once
`tasks/handoffs/f2/central-admin-role-plan.md` ships is a one-line change,
same extensibility pattern already established for `RlsContext::ROLES`/
`RlsContextRunner::ADMIN_ROLES`/`CreateAdminCommand`'s role list.

## Schema change

One column on `admins`: `has_email_authentication` (boolean, default
`false`, NOT NULL — no separate token/secret table needed, unlike TOTP,
since Filament's `EmailAuthentication` provider stores the per-login code
transiently in the session, not persisted at rest at all).

`Admin` model needs:
- `implements HasEmailAuthentication` (interface, 2 methods:
  `hasEmailAuthentication(): bool`, `toggleEmailAuthentication(bool): void`)
- `use InteractsWithEmailAuthentication` (Filament's trait — implements
  both interface methods against the new column, adds the boolean cast
  itself via `initializeInteractsWithEmailAuthentication()`)

No RLS change needed: `admins` already has RLS from the base schema:
this column carries the same visibility as every other column on the row,
nothing new to decide.

## Config

New key in the already-existing `config/admin_accounts.php` (from #104),
not a magic number in the provider registration call:
`'mfa_code_expiry_minutes' => (int) env('ADMIN_MFA_CODE_EXPIRY_MINUTES', 5)`
— Filament's own default is 4; bumping to 5 is a minor, non-binding
suggestion, easy to leave at Filament's default if Lead prefers not to
diverge without a reason.

## Wiring, `AdminPanelProvider.php`

```php
->multiFactorAuthentication(
    [EmailAuthentication::make()->codeExpiryMinutes(config('admin_accounts.mfa_code_expiry_minutes'))],
    isRequired: false, // see "critical finding" above
)
->authMiddleware([
    Authenticate::class,
    RejectDisabledAdmin::class,
    RequireMfaForPrivilegedAdmins::class,
    ApplyRlsContext::class,
], isPersistent: true);
```

## Explicitly not designed further here

- **Whether Filament's `Notification::send()` mail path is production-ready
  today** — this app's other outbound admin-facing communication (activation,
  password-setup links) goes through this codebase's own `Notifier`
  contract/outbox pattern (`App\Contracts\Notifier`,
  `App\Actions\Notifications\EnqueueParticipantActivation`); Filament's
  built-in notification sends directly via Laravel's standard mail stack,
  bypassing that abstraction entirely. Whether `config/mail.php` is
  correctly configured for production and whether that divergence from this
  app's own pattern matters enough to wrap Filament's notification in the
  outbox pattern too is an implementation-time question, not decided here.
- **Exact `mfa_code_expiry_minutes` value** — 5 minutes suggested above,
  not load-bearing; Lead/owner can set anything.
- **Grace period for already-active sessions** the moment this ships** — an
  admin mid-session when `RequireMfaForPrivilegedAdmins` is deployed will be
  redirected to the setup page on their very next request, same as the
  disabled-admin behavior already shipped in #104. Not treated as a problem
  needing a transition window (no owner ask for one), but flagged in case
  Lead wants a heads-up communicated to current super_admin/psychologist
  users before deploy.
- **Tests** (designed only in outline): provider registration surfaces the
  challenge form after password auth for a `super_admin`/`psychologist`;
  `RequireMfaForPrivilegedAdmins` redirects an admin in the required-role
  list who hasn't enabled it, and passes one who has; `branch_admin`/`staff`
  are never redirected regardless of their own toggle state; the boot-time
  `isRequired`-cannot-be-role-scoped finding gets its own regression test
  (assert the panel's own `isMultiFactorAuthenticationRequired()` stays
  `false`, i.e. that enforcement genuinely lives in the custom middleware,
  not silently reintroduced via the built-in mechanism later).

## Nothing changed yet

No code in this PR. `admin` panel has no MFA today, exactly as before. This
document is design only, per Lead's explicit "rencana dulu, belum kode."
