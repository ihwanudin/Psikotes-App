# E2E-2 Browser E2E harness

This increment implements the approved E2E-1 §3 harness proposal and §5 E2E-2
scenario using `@playwright/test`, SQLite disposable data, and synthetic users
only. It intentionally does **not** touch application code, routes, config,
seeders, migrations, existing tests, or acceptance checklists.

## Scope

- T-23 admin access matrix for `psychologist`, `super_admin`, `branch_admin`,
  `staff`, and `guest`.
- F5 `ReportSigning` reachability assertions for the correct eventual behavior.
  These are expected to fail red on this baseline because `main` still has
  `ReportSigning::$slug = 'report-signing'` with no `{case}` route parameter
  while `mount(string $case, ...)` requires one.
- T-24 registration/catalog proof that a main psychotest package includes
  DASS-21 automatically and does not expose DASS-21 as an optional add-on item.

## Windows PowerShell local run

```powershell
# from repository root
Copy-Item .env.example .env

# Ensure the synthetic .env is testing before Composer scripts run.
(Get-Content .env) `
  -replace '^APP_ENV=.*', 'APP_ENV=testing' `
  -replace '^APP_KEY=.*', 'APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' `
  -replace '^DB_CONNECTION=.*', 'DB_CONNECTION=sqlite' `
  | Set-Content .env

New-Item -ItemType Directory -Force storage/framework/views,storage/framework/cache/data,storage/framework/sessions,storage/framework/testing,bootstrap/cache,storage/e2e | Out-Null

php D:/laragon/bin/composer/composer.phar install --no-interaction
npm ci
npm run build
npx playwright install chromium
npm run e2e
```

The Playwright web server command runs `tests/E2E/setup/start-server.mjs`. That
wrapper fails fast when the full testing environment override is missing,
`vendor/autoload.php` is absent, `public/build/manifest.json` is absent, or
Chromium is not installed. It then creates `storage/e2e/e2e.sqlite`, runs
`php artisan migrate:fresh --seed`, applies `tests/E2E/setup/seed-e2e.php`, and
only then starts `php artisan serve`.

Readiness waits on `/favicon.ico`, a static public file that does not touch the
database, Redis-backed `/health`, or Vite's built asset manifest. This keeps the
probe focused on whether the PHP process is answering while the database has
already been prepared by the wrapper.

## CI run

Add a separate CI job after the existing PHP/Node setup:

```bash
cp .env.example .env
sed -i.bak \
  -e 's/^APP_ENV=.*/APP_ENV=testing/' \
  -e 's#^APP_KEY=.*#APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=#' \
  -e 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/' \
  .env
mkdir -p storage/framework/{views,cache/data,sessions,testing} bootstrap/cache storage/e2e
composer install --no-interaction --prefer-dist
npm ci
npm run build
npx playwright install --with-deps chromium
npm run e2e:ci
```

Upload `storage/e2e/playwright-report`, `storage/e2e/test-results`, and
`storage/e2e/junit.xml` as artifacts.

## Expected duration

This suite is intended for a CI runner, not a Windows workstation. A cold CI run
that installs Composer dependencies, Node dependencies, Vite assets, and
Chromium is expected to take about 8-15 minutes.

Lead's dynamic run on commit `695b833` proved that the old webServer startup
timeout is fixed: Playwright discovered all 56 tests and got as far as executing
them. That run produced 8 passes; the remaining tests failed on the per-test
45s timeout, not on the webServer startup timeout. Most slow failures happened
while filling the login form on a Windows workstation where Lead measured `/` at
7.8s and `/admin/login` at 54s with the full E2E environment. The 54s login
latency exceeds the current 45s per-test budget.

The 45s per-test timeout is deliberate. Do not silently raise it just to chase a
green run on slow workstation hardware: doing so would hide the real hardware
requirement and mislead the next worker into thinking this suite runs normally
on a workstation. If this timeout ever changes, the change must include a
written, numeric rationale in code comments, just like the webServer timeout
rationale below.

## Startup and timeout rationale

The harness no longer relies on Playwright `globalSetup` for database setup:
Playwright starts `webServer` and waits for readiness before `globalSetup` runs,
so a clean database could time out before the migration/seed step ever started.
The wrapper fixes that ordering by doing migration and seed synchronously before
launching the server process.

The web server timeout is 300 seconds. Lead measured dynamic cold responses at
7.8s for `/` and 54s for `/admin/login`; 300s is about 5.5x the slower 54s
dynamic-page latency and leaves room for the pre-start migrate/seed step on a
slower Windows or CI runner. A timeout without the ordering fix would not be
acceptable; both are required.

## Known red finding on this baseline

The `f5-report-signing.spec.ts` tests assert the correct eventual browser
behavior and are expected to fail on `facb2bb` until the queued upstream
DeepSeek fix changes the route/entry point. A red result there is a product
reachability defect, not a harness defect.
