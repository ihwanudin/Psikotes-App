# P14 private summary: bounded runtime attempt

2026-09-04. Outcome: offline CLI/browser smoke GREEN; runtime preparation STOPPED
before database init on a PHP startup warning. This is not summary browser GREEN.
Ownership: this report only. Worker code remains the reviewed guard 1c0d5b0 and
report ed7e86d (root 6064eeb/63f4231); no harness or application fix was attempted.

## Exact source and local tooling

Existing approved run:
`C:/Users/ThinkPad/AppData/Local/Temp/oncam-checkout-7b2a098a09934fa38a946b9d796b6480`.
Current manifest SHA-256:
`9eb114d8f37a0ed028f559b257dd7cad3c08a550ae5818e742a46f57d8121337`.
The old 0018ded digest was not used. Rechecked all 22,126 file hashes and exact
inventory before runtime preparation; all match. No .env* or existing database.
Six accepted baseline differences remain as documented; no root overlay/equality
claim. Source copy, vendor and manifest were not modified during this increment.

Read the Playwright skill, cached CLI README configuration schema and entrypoint
source. npx exists but was never invoked. Used only the explicit existing path:
`C:/Users/ThinkPad/AppData/Local/npm-cache/_npx/31e32ef8478fbf80/node_modules/@playwright/cli/playwright-cli.js`.
Cached CLI is 0.1.19 (core 1.63.0-alpha-2026-08-31). Every CLI invocation set
process-local NO_UPDATE_NOTIFIER=1 and CI=1; source confirms this returns before
the registry update check. Local --help succeeded. No installation, download,
package substitution, wrapper or existing user browser was used.

README explicitly supports browser.launchOptions.executablePath. Selected
`C:/Users/ThinkPad/AppData/Local/ms-playwright/chromium-1234/chrome-win64/chrome.exe`
instead of the CLI's default expected revision 1243. This explicit launch passed
the bounded smoke; it does not establish full compatibility with all driver APIs.
Node is v24.11.0; PHP CLI is 8.3.26.

## Observed smoke result

Created only run-local smoke-config.json, smoke.browser.mjs and smoke-output.
Configuration: isolated=true, headless=true, offline=true, serviceWorkers=block,
explicit executable, resolver MAP * ~NOTFOUND, no-proxy-server and disabled
background networking. No remote navigation was attempted. The smoke installs
an abort-all route before its page/context/cookie probes; offline configuration
was already set before opening about:blank.

Named session: p14-summary-7b2a-smoke. CLI open reported PID 61712.
Commands were direct cached CLI open about:blank --config smoke-config.json,
run-code --filename smoke.browser.mjs, then close, with that session name.
Result: passed=true, version=151.0.7922.34, basicPageContextCookieApis=true,
networkNavigations=0. Probes checked about:blank, newPage/setContent/locator text,
addCookies/readCookies/clearCookies with a synthetic smoke.invalid cookie, and
closing the extra page. No browser secrets or actual checkout fixture were used.
No OS-wide packet capture was performed: networkNavigations=0 describes the
smoke's operations, not a claim of measured zero packets from the operating system.

Smoke was closed successfully before PHP preparation. All-family listener checks
before and after smoke showed no listeners on 8126 or 443. No PHP/TLS process was
started, no port was reserved and no unrelated process was killed.

## First failure and mandatory stop

Generated a new run-local runtime.ini from explicit settings, rather than copying
an active ini/.env. It set the installed extension directory, required SQLite/
mbstring/openssl/fileinfo modules and other known modules, memory 512M, timezone
UTC, variables_order=EGPCS, errors hidden/not logged and allow_url_fopen=Off.
auto_prepend_file and auto_append_file remained empty. No process environment or
application feature flag was changed for an init/server: neither was launched.

The read-only `php -n -c runtime.ini -r ...` capability probe emitted a PHP startup
warning: unable to load dynamic library bcmath because php_bcmath.dll is absent.
This was caused by my generated INI unnecessarily declaring extension=bcmath,
not by the reviewed harness or source copy. Although the command exited 0 and
reported all five required extensions present, the warning is a runtime failure
under the coordinator's stop-on-first-failure rule.

STOPPED without editing/retrying the ini or changing any harness assertion.
A subsequent read-only `php -n -r ...` inspection confirmed bcmathCompiledIn=true
on PHP 8.3.26. Narrow proposed follow-up for separate review: omit the redundant
dynamic bcmath declaration from this disposable INI, then recheck startup before
considering guarded init. No dependency rebuild/download is needed or proposed.

## Counts, evidence and cleanup boundary

- One named offline smoke passed and closed; one PHP capability probe had the
  startup warning. No full summary-driver case ran.
- Zero checkout fixture init runs, summary exchanges, lifecycle verification runs,
  PHP/TLS listeners, provider calls or actual checkout browser requests.
- browser.sqlite, storage, fixtures.json, baseline.json, cert.pem and key.pem
  remain absent. Mandatory business/lifecycle verification is unexecuted because
  no initialization or successful summary browser run occurred; no pass claimed.
- One local smoke snapshot/log artifact scanned: zero matches for the bounded
  checkout cookie/credential, authorization, key and synthetic PII marker patterns.
  No app/PHP/TLS logs exist. This marker scan is not exhaustive secret detection.
- At 2026-09-04T21:23:59.7615433+07:00, PID 61712 no longer existed; subsequent
  process inspection found zero direct children of that PID. All-family 8126/443
  listener query was empty. No broad kill or browser close-all operation used.
- Run-local config/probe/snapshot evidence is retained for review, alongside the
  approved source and manifest. No scratch deletion was attempted; old runs and
  unrelated build artifacts remain untouched. No certificate/trust/hosts changes.

No application, harness, dependency, routes/config, active DB/data, source/gate,
provider/notifier, deployment/push, new task/agent or baseline reset. No PHPUnit,
PG, Pint or PHPStan rerun for this report-only increment. Report diff-check and
single-file staging are the commit checks. STOP for runtime-INI follow-up review;
do not interpret the successful smoke as P14 browser acceptance.
