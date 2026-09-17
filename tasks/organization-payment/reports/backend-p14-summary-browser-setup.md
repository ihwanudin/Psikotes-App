# P14 private summary browser: disposable setup checkpoint

Original checkpoint: BLOCKED before bootstrap, init, server or browser, 2026-09-04.
The historical evidence below is preserved; see the guard-fix amendment at the end
for the refreshed manifest and current review boundary.
Worker HEAD before this report: ae6af1bfc4a5837ef2bd82a3a201158b99983f3f.
Root observed HEAD: 46c46c15ffd7fde57d303c0c9c7516d5a8358a06.
The accepted harness increments 0dc7586/ae6af1b are not browser acceptance.

## Independent copy and manifest

Run: `C:/Users/ThinkPad/AppData/Local/Temp/oncam-checkout-7b2a098a09934fa38a946b9d796b6480`.
Source: the run's `source/`; manifest: `source-manifest.json`; metadata: `setup-evidence.json`.
Manifest SHA-256: `0018ded08751721428a4b1b59a1314a75604d832cafad0fb66b7ebeb23c8a5d7`.

The manifest contains 22,126 exact relative file paths and SHA-256 hashes:
359 source/support files and 21,767 vendor files. All 175 installed package
names, versions and distribution references match the worker composer.lock.
Root and worker lock hash both equal
`44aa7ea181ecf0accdd18a05ae5da39bc8d9016c88431bfe9aebfcb536720e16`.

Copied independently from this worker, including accepted untracked overlays:
app, bootstrap excluding cache, config, database/migrations, database/factories,
routes, resources/views, tests/Support, composer.json/lock and the three browser
harness files. Vendor was copied separately after lock checks. lang was absent.
No root source was overlaid. No .env*, storage, existing cache/output, node_modules,
public build, SQLite data or old fixture registry was copied. database/schema is
not in the approved allowlist: the two migrations referring to it return for
non-PostgreSQL drivers. This SQLite-only preparation is not a PG runnable copy.

Each copied file was checked against donor bytes, with distinct filesystem inode.
Final inventory exactly matches the manifest: 0 reparse entries, 0 multiple-link
files, 0 .env* files. browser.sqlite, storage, fixtures.json and baseline.json are
absent. The source manifest and copy remain local for review, not committed.

Preparation initially hit a Python default-encoding read error before run creation;
explicit UTF-8 fixed it. A slow sequential copy was interrupted and the same fresh
run resumed using 12 local filesystem I/O threads, checking existing partial files
before reuse. It completed successfully; no old run or shared junction was reused.

## Blocking guard result

The reviewed checkoutBrowserSafeRelative predicate permits only alphanumeric,
underscore, dot and hyphen path segments. Eight legitimate Carbon vendor paths
contain @ and fail its actual PHP predicate:

- `vendor/nesbot/carbon/src/Carbon/Lang/aa_ER@saaho.php`
- `vendor/nesbot/carbon/src/Carbon/Lang/be_BY@latin.php`
- `vendor/nesbot/carbon/src/Carbon/Lang/ks_IN@devanagari.php`
- `vendor/nesbot/carbon/src/Carbon/Lang/nan_TW@latin.php`
- `vendor/nesbot/carbon/src/Carbon/Lang/sd_IN@devanagari.php`
- `vendor/nesbot/carbon/src/Carbon/Lang/sr_RS@latin.php`
- `vendor/nesbot/carbon/src/Carbon/Lang/tt_RU@iqtelif.php`
- `vendor/nesbot/carbon/src/Carbon/Lang/uz_UZ@cyrillic.php`

The predicate was extracted and evaluated in isolation, without including the
harness or vendor autoloader. All eight reject. No vendor removal/rename, guard
relaxation or substitute dependency was attempted. Therefore full filesystem
guard acceptance is NOT green. Review a narrow legal-filename guard adjustment
before any further preparation/launch; this report does not authorize that edit.

## Root comparison and critical hashes

All 359 non-vendor source files exist in root. Six differ from this worker copy:

- AssessmentParticipantResource: root adds the `Nama belum dilengkapi` placeholder.
- OrderResource: root adds the same participant-name placeholder.
- config/assessment_billing.php: comment-only reconciliation activation wording.
- config/assessment_integration.php: root adds checkout_handoff enabled=false and
  ttl_seconds=600 defaults, absent in worker. The accepted disposable harness sets
  explicit synthetic enabled/TTL values; that does not make baseline bytes equal.
- resources/views/checkout/private.blade.php: line endings only.
- tools/testing/tests/Browser/checkout-session.browser.mjs: line endings only.

No missing baseline was reconstructed. These differences remain for coordinator
acceptance; no root files were copied to resolve them. Critical source hashes
below match worker and disposable copy; root matches bytes except the noted JS
line endings (normalized content matches).

| Relative file | SHA-256 |
| --- | --- |
| `composer.lock` | `44aa7ea181ecf0accdd18a05ae5da39bc8d9016c88431bfe9aebfcb536720e16` |
| `app/Http/Controllers/CheckoutSessionController.php` | `70594d53490d231a8b438dc8eba5b095c56ef30ae1390d99a25ab1fee76a4144` |
| `resources/views/checkout/summary.blade.php` | `f4cabb0a088584deb3d8dcc4bfaa3d5cf11d9b8323ad235ac5c64ee55e5765a0` |
| `app/Actions/Integrations/CheckoutSessionLifecycle.php` | `128c1a8c96cf51bb2fb638e524c7bc32e69e2f9b8cfa3d1b59ba7ee5677a7418` |
| `app/Services/Integrations/CheckoutSummaryComposer.php` | `fe71be003fda9eed11b426bfb462422d6a8ad1a669cadc8f956b3151157093b3` |
| `app/Data/Integrations/CheckoutSummary.php` | `eb41ed26fb85d320d34102072d1d6dccd50864a6fca13896fdc7e60f0d6d81f0` |
| `app/Http/Middleware/AuthenticateCheckoutSession.php` | `474d78568037d739149fad60c43d176c46f35661058bf65249d4290b50abc73a` |
| `app/Http/Middleware/VerifyCheckoutSessionMutation.php` | `830fe3010872aa46137c06f0a30057b7baf53040d6e5dc6cca96c6c07228653c` |
| `app/Http/Middleware/ProtectCheckoutSessionHttpBoundary.php` | `0bd78c2c7edaf00798301c6cd529ac42094b84172cdd5b726bf341f71f2b3afb` |
| `app/Services/Integrations/CheckoutSessionHttpContract.php` | `2b8d63df6014d41f5cb69632ca25b667ba4ae6d4963c54fca802326990e9321b` |
| `app/Services/ParticipantAuth/AssessmentEntitlementGate.php` | `572b8741e0702783897aa65433e9fca3eb53827aab734fe3d21eba73c65200ed` |
| `app/Services/ParticipantAuth/AssessmentAccessPrerequisites.php` | `54419e9e6ca387dac04462c967f8ed5f78ad50f4f6bb528e9accff82bfb76caf` |
| `app/Actions/Identity/StoreIdentityEvidence.php` | `1ecdb7e2ed2f040faac93eb8c903440dd20e139bec353869ad6ca04d016135a5` |
| `app/Services/Payments/AssessmentSettlementReader.php` | `7abc5d9123f3d64d87e2493a8e05fab119cf8097ceafc484c26840faeb344c6b` |
| `app/Services/ParticipantAuth/AcceptedConsentReader.php` | `9984c0b12a7c1fad76b11555b2d31b509ceb966a35354541ff6bb371c3fe39b4` |
| `tests/Support/AssessmentAccessFixture.php` | `358c4ddb0a4e557b47d35889fc945097ab7ef97b697e03808edbdc4eacf959e3` |
| `tests/Support/AssessmentBillingFixture.php` | `3df0dc88055964dfdc70af3835e3fc954e576d79a0a3e34ff38b31b05892a1ba` |
| `tools/testing/tests/Browser/serve-checkout-session.php` | `11824ddc9068a53086861c2f1f7ce105fda033b87ff7f8f153cbc81267f59562` |
| `tools/testing/tests/Browser/checkout-session.browser.mjs` | `1558a132824fc326cf4d8a1abbcc8afa4c8ebdbe2f7a9a4a5d19976625702b13` |
| `tools/testing/tests/Browser/https-loopback-proxy.py` | `cd66a224abe8c10c3e5e5742a5f2e354a3fe717cc0c429c083b66e6f0dc71fa7` |

## Read-only runtime and ports

PHP CLI 8.3.26: D:/laragon/bin/php/php-8.3.26-Win32-vs16-x64/php.exe.
pdo_sqlite, mbstring, openssl, dom and fileinfo are present.
Node v24.11.0: D:/laragon/bin/nodejs/node-v24/node.exe.
Python: C:/Python314/python.exe, installed cryptography 46.0.6.
No dependency installation, npx invocation or certificate generation occurred.

playwright-cli is not on PATH. Cached @playwright/cli 0.1.19 exists under
AppData/Local/npm-cache/_npx/31e32ef8478fbf80/node_modules/@playwright/cli;
its core expects Chromium revision 1243 (153.0.8010.12), not installed.
Cached CLI 0.1.18 expects revision 1237, also different from the installed browser.
Installed Chromium is ms-playwright/chromium-1234/chrome-win64/chrome.exe,
product version 151.0.7922.34; matching headless shell is present.
Cached playwright library 1.62.1 under _npx/e41f203b7505f1fb expects revision 1234,
but that cache has no CLI package. No compatibility run or tool substitution is
claimed. Future use of an explicit executable needs review/verification.

Cached CLI source has an update-check network request unless NO_UPDATE_NOTIFIER
or CI is set. Any later approved direct cached CLI invocation must suppress that
check using process-local NO_UPDATE_NOTIFIER=1 and CI=1. CLI was not run here.

Read-only Get-NetTCPConnection over all listeners at
2026-09-04T21:04:21.0647556+07:00 found 43 listeners and zero entries for 8126/443,
including IPv4, IPv6 and wildcard addresses. A final repeat also found no target
listeners. This is an observation, not a reservation; repeat before approved launch.
No process was killed and no hosts/trust setting was changed.

## Actual pure verification and execution boundary

On the new source copy:

- PHP harness --self-test: 16 pure checks passed; exits before autoload/bootstrap.
- php -l of the PHP harness: no syntax errors.
- node --check of the browser script: passed.
- Evaluating the browser function with null in a Node VM: 14 positive and 37
  negative probes passed, browserStarted=false.
- Actual isolated PHP path predicate: eight legitimate vendor filenames rejected.
- Filesystem inventory: 22,126 manifest entries exactly match file paths; no links
  or environment files; database and runtime artifact absence verified.

No application/PHPUnit/Pint/PHPStan/PG suite was rerun for this report-only setup.
Pure probes are not full manifest-guard, bootstrap or browser GREEN. No database
initialization, PHP/TLS listener, browser navigation or driver launch occurred.
No synthetic fixture matrix or business postcondition was executed or claimed.

After separate review resolves the guard and baseline/tooling questions, the
intended commands remain the approved harness init, PHP 127.0.0.1:8126 with this
run's storage/public and reviewed disposable ini, and the copied TLS proxy binding
127.0.0.1:443 to upstream 8126. Process-local directory and manifest digest must
point exactly to this run. Init must not be invoked merely to test the guard.
Certificate, ini and isolated browser configuration are not yet created/reviewed.
The eventual browser must use the approved resolver/interception allowlist,
blocked service workers and process-local update-check suppression. These are
future gates, not launch-ready commands or permission to continue.

Only this report is owned by the checkpoint. Existing dirty source/overlays and
old scratch/build artifacts remain intact. No .env/active data, provider/notifier,
public route/source/gate, migration, deployment, push or additional task/agent.
Commit only this report after diff-check; STOP for setup review.

## Guard-fix amendment — 2026-09-04

Coordinator accepted the setup as root aabd2af and authorized only the literal @
path fix. Code commit: `1c0d5b0d4cf021be483e75f3f3acf80d672ed554`.
Ownership is the PHP harness and this existing report. No vendor file was renamed,
removed or edited; no source from root was overlaid. The coordinator accepted the
six documented baseline differences for this synthetic summary-only test, not as
a statement of byte equality. Browser CLI compatibility remains a separate gate.

The predicate adds only literal @ to each ordinary path segment's character class.
The dot/dot-dot segment checks and rejection of absolute, drive, backslash, empty
segment, colon, percent and control-character paths remain. The main harness's
realpath/reparse checks, exact inventory and file/hash verification are unchanged.

TDD evidence, without autoload/bootstrap or database:

- Added the eight actual Carbon locale names as positive pure checks and 22
  negative paths, including @-adjacent traversal, dot segments, drive and backslash
  variants, absolute/UNC paths, alternate-stream colon, percent encoding, LF/NUL.
- RED: running --self-test with the old predicate exited 1, pure checks failed.
- GREEN: after the single-character allowlist addition, all 46 checks passed
  (16 existing + 8 positive + 22 negative). Worker and refreshed copy both pass.
- PHP syntax check and focused Pint --test passed. No application PHPUnit,
  PHPStan, PG or browser result is claimed for this isolated harness change.

After the code commit, refreshed ONLY
`source/tools/testing/tests/Browser/serve-checkout-session.php` in the same run.
Before copying, all 22,126 files matched the previous exact manifest. Afterward,
rehashing every file confirmed that this is the sole changed source entry; the
other 22,125 hashes are unchanged. Copy remains independent, with no multiple-link
files/reparse files and exact inventory. Manifest JSON was regenerated with sorted
paths and compact UTF-8 encoding.

- Historical manifest SHA-256:
  `0018ded08751721428a4b1b59a1314a75604d832cafad0fb66b7ebeb23c8a5d7`.
- Current manifest SHA-256:
  `9eb114d8f37a0ed028f559b257dd7cad3c08a550ae5818e742a46f57d8121337`.
- Current worker/copy PHP harness SHA-256:
  `5457dad2192a28f8ef6e033b2295bbce52ce51a06204096f1bf21cdf22fd4acf`.

The original setup-evidence.json remains a historical checkpoint, including its
old manifest digest and rejected paths; this amendment supplies the current
digest. Do not use that old digest to launch the refreshed copy.

Isolated evaluation of the actual PHP path and tree functions validates all 22,126
manifest paths and the run tree, without including the application or autoloader.
The original eight vendor-name refusals are resolved. Database, storage, fixtures
and baseline remain absent. No full init/bootstrap or database verifier ran.

No init, server, TLS, browser/CLI, dependency install/download, certificate,
operational config, active data or public wiring. Keep this copy for review;
STOP before tooling review/launch. Diff-check and explicit file staging preserve
the unrelated dirty baseline. This is guard GREEN, not browser acceptance.
