# P14 private summary: bounded runtime attempt

Original attempt, 2026-09-04: offline CLI/browser smoke GREEN; runtime preparation STOPPED
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

## Authorized INI correction and second attempt — 2026-09-04

Coordinator approved removing ONLY extension=bcmath from this run's runtime.ini
using apply_patch. That exact correction was applied; no other ini/shared settings
or source files changed. Basic CLI compatibility smoke was accepted and not rerun.
The read-only PHP -n -c runtime.ini probe now emits no warning: PHP8.3.26, bcmath,
pdo_sqlite, mbstring, openssl, dom and fileinfo present, prepend/append empty.

Before init, rehashed all 22,126 manifest files, checked exact inventory and no
.env*, and confirmed no preexisting SQLite file. Manifest remains 9eb114d8...1337
as recorded above. Process environment was constructed from Windows runtime path/
temp/system variables plus the three exact ONCAM_CHECKOUT_BROWSER values; no
inherited application/provider environment was passed to init or server processes.
The reviewed harness itself supplies synthetic testing settings and fake bindings.

Guarded init command used the explicit PHP executable, -n -c runtime.ini and the
copied serve-checkout-session.php init, pointing to this run and the current exact
manifest digest. Actual result: exit0, exact fixture-ready message (43 stdout
bytes), zero stderr. SQLite, storage, fixtures.json and baseline.json were created
only here. No fixture or guard change was made to obtain this result.

Created run-local RSA2048 self-signed certificate/key for the two synthetic hosts,
valid one day, and browser-config.json. No trust-store/hosts edits. Browser config
uses the already smoke-tested explicit Chromium1234, isolated headless context,
ignoreHTTPSErrors for this context, serviceWorkers=block, viewport1280x800,
no-proxy-server, disabled background networking and the approved resolver rules:
psikotes.oncam.id/oncam.id ->127.0.0.1, all other DNS ->NOTFOUND. The unmodified
driver installs interception before login; no trace/video/storageState export.

Immediate all-family port check was empty before launch. Launched hidden owned
processes with explicit executable/arguments and recorded owned-processes.json:

| Process | PID | Verified listener |
| --- | --- | --- |
| PHP8.3.26 -n -c runtime.ini, exact copied router/document root | 43024 | 127.0.0.1:8126 |
| Python3.14 unchanged copied TLS bridge, exact run cert/key | 46036 | 127.0.0.1:443 |

Executable, command line, PID and both addresses were checked before browser work;
no IPv6 or wildcard listener was present on those ports. Opened named isolated
session p14-summary-7b2a-runtime on about:blank, CLI-reported PID60636. Direct
cached CLI invocations again used NO_UPDATE_NOTIFIER=1 and CI=1. No download.

### First browser failure and diagnosis

Ran the exact reviewed checkout-session.browser.mjs via run-code --filename.
Driver exit1, no result section, stderr0, error section:
`page.goto: net::ERR_EMPTY_RESPONSE` for the fixed synthetic /__browser/login URL.
This is the first navigation, before auth probe, issue or exchange. No summary
case completed, no assertion was weakened, and no retry/application edit followed.

Observed timing: PHP accepted a loopback connection at21:29:29; driver failure
output was written at21:29:39.900733; PHP closed that connection at21:29:59 and
accepted two further connections. TLS stderr is empty. The unchanged bridge uses
HTTPConnection upstream timeout10. Installed Python http.server catches TimeoutError
in handle_one_request, logs via log_error then closes the connection; this bridge
overrides log_message with a no-op, explaining why such a timeout can be silent.
The ten-second failure interval and slower PHP response strongly support an
upstream timeout as the immediate failure mechanism, not a summary assertion.

The PHP router performs full tree/22,126-file manifest hash work on every request
before application bootstrap. That is a plausible source of latency on this host,
but logs do NOT identify the exact PHP stage or conclusively prove that cause.
No diagnostic rerun, timeout increase, cache/bypass, proxy alteration or guard
relaxation was attempted. Follow-up needs separate review of bounded timing
diagnostics; full integrity checks and browser expectations remain requirements.

### Actual post-failure evidence and cleanup

Closed only named runtime browser60636. Verified owned PHP/TLS executable and exact
run command line before Stop-Process on43024/46036. At
2026-09-04T21:30:23.3979946+07:00 browser PID60636 was absent and the all-family
8126/443 listener query was empty. No unrelated process was stopped.

Read-only SQLite URI mode=ro comparison after owned processes stopped: all42
baseline tables matched init rows, comparing decoded JSON records with sorted
keys/rows. This is a separate failure-diagnostic comparison, not the mandatory
successful-browser business/lifecycle verifier. Handoffs0, checkout sessions0,
audit rows0, outbox0. No violations.txt was recorded. The driver's final verifier
and CLI lifecycle verifier were NOT run: their expected11handoffs/9sessions/34audits
require a completed browser run, which did not happen. No success claimed.

Scanned10 local log/snapshot files: zero matches for bounded och1_/ocs1_/ocsrf1_
credential, authorization, checkout-cookie and synthetic PII/private-marker
patterns. No raw fixture/credential data is printed or committed. Scan limitations
remain; this is not proof against every possible secret encoding. Logs and the
synthetic run database/cert/config remain local for review; no cleanup deletion or
workaround was attempted, and older scratch remains untouched.

Only this runtime report is committed. Source/vendor/manifest, reviewed PHP/JS
harnesses, active data, shared config and production wiring remain unchanged.
No provider/payment/notifier call, task/agent, deploy/push or migration on an active
database. Diff-check and single-file staging apply. STOP for timeout diagnosis
review; P14 browser acceptance remains incomplete.
