# Fortify / `users` guard cluster — fact-finding (2026-09-21)

Lead's request after the catch-all RLS audit found `users`, `password_reset_tokens`,
`sessions`, `passkeys` with no RLS: report facts on whether this cluster is live
or dead scaffold, no fixes. This is that report. No files changed as part of
this investigation.

## 1. Does anything actually use the `web` guard / `App\Models\User`?

- `config/auth.php:22` — default guard is `web` (`AUTH_GUARD` env, defaults `web`).
  Two guards exist: `web` → provider `users` → `App\Models\User`; `admin` →
  provider `admins` → `App\Models\Admin`.
- Filament's admin panel is explicitly wired to the **`admin` guard**
  (`app/Providers/Filament/AdminPanelProvider.php:34`, `->authGuard('admin')`),
  not `web`/`User`. No `Auth::guard('web')`/`auth('web')` call exists anywhere
  in `app/`.
- The **default guard IS reachable** through unqualified `auth`/`verified`
  middleware and `$request->user()`:
  - `routes/web.php:223-225` — `/dashboard`, middleware `['auth', 'verified']`.
  - `routes/settings.php` — `settings/profile`, `settings/security`,
    `settings/appearance` under `auth` (+`verified` on some).
  - `Settings/ProfileController.php`, `Settings/SecurityController.php` call
    `$request->user()` with no guard arg → resolves `web` → `User`.
  - `HandleInertiaRequests.php:43-45` shares `auth.user` (default guard) on
    every Inertia response.
- `App\Models\User` usage in `app/`: `Fortify\CreateNewUser::create()` (see
  §3 — unreachable route), `ProfileValidationRules` (`Rule::unique(User::class)`,
  validation only), `app/Models/User.php` itself.
- **RLS gap is real at the code level, not just the DB level**:
  `ApplyRlsContext` middleware (which the Filament/`admin`-guard panel requires)
  is NOT applied to `/dashboard` or `settings/*` (confirmed via
  `bootstrap/app.php:33-37`'s `web` middleware group and each route's own
  middleware list). `database/schema/rls_policies.sql` has zero references to
  `users`/`passkeys`/`password_reset_tokens`/`sessions` — this cluster was
  never brought into the RLS design at all, on either side.
- `config/session.php` defaults `SESSION_DRIVER` to `database` (would use the
  `sessions` table), but `.env.example` sets `SESSION_DRIVER=redis`. No `.env`
  exists in this worktree, so the actual production session driver — and thus
  whether the `sessions` table is genuinely written in prod — **cannot be
  determined from this repo alone**.
- **Not fully dead**: the public homepage links into this flow —
  `resources/js/pages/welcome.tsx:307-311`, a "Portal pengelola" button that
  goes to `login()` when logged out, `dashboard()` when logged in. This is a
  live, reachable, linked entry point.

## 2. Anything creating `users` rows outside `tests/`?

- `database/seeders/DatabaseSeeder.php:16-19` seeds only `BranchSeeder`,
  `InstrumentSeeder`, `TestPackageSeeder`, `PaymentMethodSeeder` — **no user
  seeder exists.**
- No `app/Console/Commands/*` creates/references a `users` row (no
  `make:admin`-style command).
- The only non-test creation call is `app/Actions/Fortify/CreateNewUser.php:29`
  (`User::create(...)`), reachable only via Fortify's `register.store` route —
  which is **not registered** (see §3, registration feature is off).
- Every other `User::factory()->create()` call is inside `tests/**` (13 test
  files, full list in the investigation transcript) — none reach production
  data.

**Net effect**: nothing outside the test suite can currently create a `users`
row through any registered path.

## 3. What routes does Fortify + the starter kit actually expose?

`config/fortify.php` features array: `resetPasswords()`, `emailVerification()`,
`twoFactorAuthentication()`, `passkeys()` are ON. `registration()` is **absent
→ OFF**, confirmed by Fortify's package route file
(`vendor/laravel/fortify/routes/routes.php`) never registering `/register`
(GET/POST) or the `register`/`register.store` route names when that feature
flag is off.

Registered and reachable given current flags (all under Fortify's own `web`
middleware group, from `vendor/laravel/fortify/routes/routes.php`):

| Route | Guard |
|---|---|
| `GET/POST /login`, `POST /logout` | `guest:web` / `auth:web` |
| `GET/POST /forgot-password`, `GET /reset-password/{token}`, `POST /reset-password` | `guest:web` |
| `GET /email/verify`, `GET /email/verify/{id}/{hash}`, `POST /email/verification-notification` | `auth:web` |
| `GET/POST /user/confirm-password`, `GET /user/confirmed-password-status` | `auth:web` |
| `GET/POST /two-factor-challenge` | `guest:web` |
| `/user/two-factor-*` (enable/confirm/qr-code/secret-key/recovery-codes) | `auth:web` + `password.confirm` |
| `/passkeys/login*`, `/passkeys/confirm*`, `/user/passkeys*` | mixed `guest:web`/`auth:web` + `password.confirm` |

**`/register` is NOT Fortify's** — this repo's own `routes/web.php:91-92`
claims the `register`-named route for the app's real participant-registration
flow (`ParticipantRegistrationController`), confirmed by
`tests/Feature/Auth/RegistrationTest.php` (test name
`test_registration_screen_is_the_participant_flow_not_fortify_signup`,
asserting `Route::has('register.store')` is false and `POST /register` is
405). `Fortify::registerView` in `FortifyServiceProvider.php` is therefore
dead code — nothing ever calls it, and no `resources/js/pages/auth/register.tsx`
view exists.

This repo's own additions on top of Fortify: `/` (public welcome page),
`/dashboard` (`auth`+`verified`, `web` guard — the starter kit's own route,
distinct from Fortify's `'home' => '/dashboard'` post-login redirect target),
`settings/profile`, `settings/security`, `settings/appearance`
(`routes/settings.php`, all `auth`-gated), and a public
`.well-known/passkey-endpoints` JSON route.

**Reachability**: `/login` is linked from the public homepage header. Once
authenticated (`web` guard), `/dashboard` and `settings/*` are linked from
the starter-kit's own layout components
(`resources/js/components/app-header.tsx`, `app-sidebar.tsx`).
`resources/js/pages/dashboard.tsx` is the **unmodified Laravel React-starter-kit
placeholder** (three empty `PlaceholderPattern` boxes) — strong evidence this
page was never built out for real product use, even though the route itself
is live and reachable.

**Provenance**: `chisel.php` (Laravel installer's starter-kit feature-toggle
script) would normally delete `CreateNewUser.php`, the register view, and
`RegistrationTest.php` when registration is toggled off — none of that
cleanup happened here. Instead, `RegistrationTest.php` was kept and
rewritten to assert the participant-flow behavior. This looks like
intentional, partial repurposing of the starter kit rather than pure
accidental leftovers, but the underlying `web`-guard/`User`/Fortify login
surface itself (`/login`, password reset, 2FA, passkeys, `/dashboard`,
`settings/*`) was not removed and remains live, linked, and RLS-unprotected.

## Not answered here (needs project-owner decision per CLAUDE.md)

Whether this cluster should be removed, kept and RLS-protected, or something
else is a product/security decision, not a fact this report settles.
CLAUDE.md requires explicit permission before deleting existing code —
that decision goes to Lead → project owner, not decided in this document.
