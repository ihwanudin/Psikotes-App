# F2 — identity-evidence route doesn't invalidate sessions on password reset (known, deferred)

Recorded 2026-09-22, during the PR #104 security review (commit `34c1405`
fixed the disabled-admin gap on this same route). Lead's explicit decision:
**leave this as tracked debt, do not fix now** — the risk window is narrow
(requires an admin's password to be reset *while* that admin still holds an
active session specifically on this non-panel route) and #100 is more
urgent. Recorded here so it isn't lost.

## The gap

`POST /admin/identity-evidence/{evidence}/temporary-url`
(`routes/web.php:122-125`) uses `auth:admin` + `admin.not-disabled` only. It
never goes through Filament's panel middleware stack, so it never passes
through `Filament\Http\Middleware\AuthenticateSession` — the middleware
Filament registers in `AdminPanelProvider.php`'s own `->middleware([...])`
array, applied to every route inside the `admin` panel. That middleware is
what makes a password change immediately invalidate any other active session
for that user (via Laravel's auth-password-hash session check).

**Consequence**: an admin who resets their password (their own, or one reset
by another admin/operator) does not have their *existing* session on this
specific route invalidated immediately — it keeps working until the session
naturally expires or the admin logs out. Every other admin route is safe: all
of them go through `panel:admin` + Filament's real `Authenticate` middleware,
which resolves through the panel's full stack including `AuthenticateSession`.

## Why this is narrow, not the same severity as the disabled-admin gap

The just-fixed gap (`RejectDisabledAdmin` never running on this route) meant
a disabled admin's session worked *indefinitely* until it naturally expired —
a *standing* compromised-access channel with no time bound tied to any other
event. This gap only matters in the specific window between "an admin's
password is reset" and "that admin's other sessions on other properly-secured
routes get invalidated" — narrower, and requires the attacker to already hold
a valid, unexpired session cookie for this route at the moment of reset.

## Fix, not implemented here

Either:
- Add `Filament\Http\Middleware\AuthenticateSession` (or an equivalent
  auth-password-hash check) to this route's own middleware array, mirroring
  what the panel already does; or
- Route this endpoint through the panel's own middleware group entirely
  (would need to become a Filament page/action instead of a hand-written
  controller route, a bigger change).

Whichever is chosen, it should extend to any other route that might in the
future use `auth:admin` + `admin.not-disabled` outside the panel — the same
class of gap as the disabled-admin one this session fixed, just for
password-reset instead of disable.
