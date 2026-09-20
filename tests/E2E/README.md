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

The Playwright global setup creates `storage/e2e/e2e.sqlite`, runs
`php artisan migrate:fresh --seed` against that file, and seeds only synthetic
branches, admins, participants, ledger rows, and one assessment case.

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

On a warmed Windows workstation with Composer/npm dependencies already present,
the full 4-viewport run is expected to take about 3-6 minutes. A cold CI run
that installs Composer dependencies, Node dependencies, Vite assets, and
Chromium is expected to take about 8-15 minutes.

## Known red finding on this baseline

The `f5-report-signing.spec.ts` tests assert the correct eventual browser
behavior and are expected to fail on `facb2bb` until the queued upstream
DeepSeek fix changes the route/entry point. A red result there is a product
reachability defect, not a harness defect.
