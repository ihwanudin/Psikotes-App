# Fresh synthetic checkout smoke preparation — 2026-09-05

Preparation only, following root commit `76039b395dca3b9c80f918117e51bdeaa88f6020`.
No application source changes, init, supervisor execution, server, browser, database,
migration, outbound request, install, or source activation were performed.
The previous run was neither read nor modified. Stop for coordinator review before
any separately authorized smoke run (at most three requests).

## Prepared artifacts

Run directory: `C:/Users/ThinkPad/AppData/Local/Temp/oncam-checkout-88ecdb30b351401db6c6111d8d4ffb27`.
All paths below are relative to this new directory unless explicitly absolute.

- `source/`: immutable root snapshot plus independent vendor files.
- `source-manifest.json`: flat, sorted relative-path/SHA-256 map of all 22,145 files.
- `preparation-evidence.json`: copy/parity evidence, no application data.
- `supervisor-config.json`, `runtime.ini`, `browser-config.json`: explicit run-local configuration.
- `cert.pem`, `key.pem`: fresh synthetic certificate/key, not committed.
- `prepare-copy.py`, `prepare-certificate.py`, `config-data.py`,
  `static-preparation-check.py`: local preparation helpers, not application changes.
- Empty `.playwright/` bounds cached CLI workspace discovery to this run.
- `source-archive.tar`: immutable selected-root archive used for extraction.

Scripts and hand-authored configs were materialized through apply_patch.
Generated archive, copied vendor bytes, manifest and copy evidence are preparation
data. No dependency scripts, composer setup, package installation or downloads ran.

## Source and dependency parity

Pinned root: `76039b395dca3b9c80f918117e51bdeaa88f6020`, including accepted
asset delivery `9e5a8ed` and supervisor lifecycle correction `7a9005b`.
The selected root paths were clean against this pin before copying.

| Evidence | Actual result |
| --- | --- |
| Application/tool source files | 378 |
| Independently copied vendor files | 21,767 |
| Manifest total | 22,145 |
| Composer lock packages, including dev | 175 |
| Installed names/versions/source and dist references | 175/175 exact |
| Git blob differences | 0 |
| Root working-tree byte differences | 0 |
| Vendor byte differences before/copy/after | 0 |
| Reparse points, symlinks, hardlinks, .env files | 0 |
| Critical harness files present and hash-verified | 71/71 |

Each dependency copy has distinct filesystem identity from its origin; there are
no shared vendor directories/junctions. Lock parity is not a vulnerability audit.

Source allowlist: PHP files under app, bootstrap, config, database/migrations,
database/factories, routes, resources/views and tests/Support; composer.json/lock,
artisan, tests/TestCase.php, the two exact public assets, and the six reviewed
browser harness/driver/proxy/supervisor/helper-test files. Bootstrap cache is
excluded. No database/schema, seeders, database files, active cache, logs or .env
were copied. The two PostgreSQL-only migration SQL reads return before accessing
schema files on SQLite; no schema dump was substituted. Consent text comes from
copied configuration and fixture dependencies are within tests/Support.

Manifest SHA-256: `4dd4496954a6ddfd0e9ab7c9883716cdabdbd587cce920c60e8a321dc081c8bd`.
Archive SHA-256: `d110addb1c08302db6c6fdbb6daa544bcceddfb963809aab53488b3370bc4b39`.
Supervisor config SHA-256: `3f3b42e34815f537facb52973ada31b9247d005a98dc53abd1d75a93ac793ddd`.

## Source hashes

These are actual hashes from the new pinned copy, not hashes assumed from earlier
worker snapshots. The manifest contains the complete inventory.

| File under source/ | SHA-256 |
| --- | --- |
| composer.json | `6e77f5595684a48cdafd010ae766c171241d79d2f92a436d329403ff2d874f3d` |
| composer.lock | `44aa7ea181ecf0accdd18a05ae5da39bc8d9016c88431bfe9aebfcb536720e16` |
| public/css/checkout-summary-v1.css | `4f480f3a9fba460a272d7835144f007def3f5817fcaab1b3b513c1fa0e115890` |
| public/brand/oncam-logo-full-color.png | `3d9925178da1f7ed0af8046340b721f58c57e8e475e580427baceadbd00e7597` |
| resources/views/checkout/summary.blade.php | `8958dae1d5b45cbd8651aa8963abe21c454a7a485d36f2b8fbb7d030d9e2dce2` |
| tools/testing/tests/Browser/serve-checkout-session.php | `48103ec2b8e6f0a78836028e2c60b4fef2134cbb880cde39b3c9ca5e442adecf` |
| tools/testing/tests/Browser/checkout-session.browser.mjs | `e6d8475aa494c1fc7270683b77e1f9a4f742a45462be3c3f234edfd67fa71cd6` |
| tools/testing/tests/Browser/https-loopback-proxy.py | `cd66a224abe8c10c3e5e5742a5f2e354a3fe717cc0c429c083b66e6f0dc71fa7` |
| tools/testing/tests/Browser/checkout-supervisor.py | `9a2888fba850536c3b35184274f5b3630a627febae1361bb2ef267f584ac037b` |
| tools/testing/tests/Browser/checkout-integrity-tests.php | `ab4f9eb98f0d0a4c23b4e77ce46d89cc136152ba90063c41ede627496d8ac799` |
| tools/testing/tests/Browser/test_checkout_supervisor.py | `564537d449b4c1e331395269e613ff4fb9d4471ea4b1e5bcd69ddd1752dd1309` |

## Explicit runtime paths and hashes

Supervisor config pins these exact files. Tools are pre-existing installations;
application source/vendor are independent copies. Tool hash verification is not
a claim that the browser runtime has been exercised.

| Config key | Path | SHA-256 |
| --- | --- | --- |
| php | `D:/laragon/bin/php/php-8.3.26-Win32-vs16-x64/php.exe` | `bd9082be692fe9153758c11bf85f726ea84a8b4f8a5f3a270c25b7064f82a7c4` |
| python | `C:/Python314/python.exe` | `fda7026477256845afab371e354c4d512896665f1761939cb5887d0a9dec257a` |
| node | `D:/laragon/bin/nodejs/node-v24/node.exe` | `b7d912484d42e7a0d0cb5b26a86410ec973a79ece7d61ad535e2d1a97a9026e1` |
| powershell | `C:/Windows/System32/WindowsPowerShell/v1.0/powershell.exe` | `7600ffe12da441fe89d035b13801e8e91d064bc544a27b19a5cf49f6ab8b18f5` |
| cli | `C:/Users/ThinkPad/AppData/Local/npm-cache/_npx/31e32ef8478fbf80/node_modules/@playwright/cli/playwright-cli.js` | `fda252270793401d2856530a5f503adf7fc326fc07bed97e413138f67c50662b` |
| browser | `C:/Users/ThinkPad/AppData/Local/ms-playwright/chromium-1234/chrome-win64/chrome.exe` | `409805a16d6416087e6b2f778df1cf8f7bbb267d6b99f6b5bb0a618eace234f2` |
| ini | `C:/Users/ThinkPad/AppData/Local/Temp/oncam-checkout-88ecdb30b351401db6c6111d8d4ffb27/runtime.ini` | `97ac524e1d6782681a9f22a4590e5ca6de2e61424a1aee1a95eb47827388adf3` |
| browser_config | `C:/Users/ThinkPad/AppData/Local/Temp/oncam-checkout-88ecdb30b351401db6c6111d8d4ffb27/browser-config.json` | `fd66fb20379353601bfe8c2cc0e4cc46c60931944e827d61293e7a3e68829556` |
| cert | `C:/Users/ThinkPad/AppData/Local/Temp/oncam-checkout-88ecdb30b351401db6c6111d8d4ffb27/cert.pem` | `c1e93f24c119a20decb3119af7260ce66765b3c72fdd4b281e5212acf8f7e6f2` |
| key | `C:/Users/ThinkPad/AppData/Local/Temp/oncam-checkout-88ecdb30b351401db6c6111d8d4ffb27/key.pem` | `9c6cb1cd5cbe7c7c9de251222bdbb1f5b3cfbdf7574bf02e6a16622958a6ba30` |

INI uses PHP 8.3.26 with explicit extension directory, memory 512M,
max_execution_time declaration 90, UTC, no prepend/append/session auto-start,
no displayed/logged errors, no URL fopen/include. Native CLI probe loaded this
exact INI: pdo_sqlite, sqlite3, mbstring, openssl, dom, fileinfo, intl, curl, zip,
sodium and bcmath available; no startup error detected. CLI reports execution
time 0 despite the file declaration; server timeout behavior has **not** been
tested and must not be inferred from that CLI probe.

Browser config: isolated headless Chromium, explicit executable, offline context,
service workers blocked, no proxy/background networking, exact two loopback DNS
mappings with other names rejected; action/navigation 30 seconds. The driver also
sets 30-second timeouts; proxy source retains its 10-second upstream timeout.
No browser download, hosts-file change, trust-store or permission change occurred.

Certificate generated with installed cryptography 46.0.6: RSA-2048/SHA-256,
self-signed, CA=false, server-auth, SAN exactly psikotes.oncam.id and oncam.id.
Key/public certificate match was checked. Validity:
`2026-09-04T17:44:02+00:00` through `2026-09-05T17:44:02+00:00`.
No PEM/private-key contents are in this report. Expiry is a review constraint;
nothing automatically regenerates the reviewed certificate or refreshes hashes.

## Verification and limits

- Copied harness `--self-test` with PHP `-n`: **64 pure checks PASS**, before app bootstrap.
- Copied browser driver: Node `--check` PASS.
- Supervisor and proxy: Python AST parsing **2 PASS**, no imports/execution.
- Native explicit-INI probe: extensions and startup checks above PASS, no app/DB.
- Standalone read-only preparation checker: inventory 22,145; critical hashes
  71/71; executable/config/certificate hashes 10/10; asset review map 4/4 PASS.
- Reparse/hardlink/.env/database checks PASS across the fresh run.
- Git diff check for the report passed before commit; only this report is staged.

The source closure audit found no missing critical source file and required no
application or harness changes. This is **not** a supervisor-preflight PASS:
`browser.sqlite`, `baseline.json` and `fixtures.json` are deliberately absent.
The actual preflight requires those fresh init outputs and was not invoked.
No placeholder database/fixture was created. Storage, bootstrap/cache,
supervisor.json, integrity-evidence.json and integrity-invalid are also absent.

No PHPUnit, Pint, PHPStan, PostgreSQL, application/HTTP asset fetch, browser smoke,
full matrix, init, ownership inspector, server start or supervisor lifecycle was
run in this preparation increment. Prior accepted test totals are not attributed
to this copy. Full runtime compatibility and resulting HTML/assets/cookies remain
unverified. No active/root data or old-run contents were read or reused; no root
source was modified and no baseline snapshot is included in this report commit.
