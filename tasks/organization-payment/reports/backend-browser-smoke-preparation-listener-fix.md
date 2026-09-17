# Fresh synthetic checkout smoke preparation after listener correction

Preparation-only evidence for source pin
`0298edc455257e656d94e3bde66bd5cb9b0cd6b5`. No init, database,
server, browser, supervisor, preflight, application bootstrap, source activation,
outbound request, install or download ran. This report does not authorize or
assume a subsequent smoke.

## Candidate

Direct temporary directory:
`C:/Users/ThinkPad/AppData/Local/Temp/oncam-checkout-49c23c96866b46ddb7d426dbe713c15d`.

This is a new unique directory. The exhausted `88ec...` run was used read-only
only as a verified vendor byte source after full comparison; none of its runtime,
database, baseline, fixture, environment, cache or log files were read or copied.
It was not rearmed or reused. The older `7b2a...` run was not accessed.

| Evidence | Actual |
| --- | --- |
| Current immutable root pin | `0298edc455257e656d94e3bde66bd5cb9b0cd6b5` |
| Source files from pinned git archive | 378 |
| Independently copied vendor files | 21,767 |
| Manifest total | 22,145 |
| Composer packages including dev | 175/175 exact name/version/source/dist reference |
| Root working bytes vs pinned source blobs | 0 differences |
| Old vendor bytes vs old manifest | 21,767/21,767 exact |
| Old vendor bytes vs current root vendor | 21,767/21,767 exact |
| New vendor bytes vs verified inputs | 21,767/21,767 exact |
| New/old filesystem identity | all distinct |
| Env/reparse/symlink/hardlink/database/log files | 0 |
| Critical harness files | 71/71 present and hash-matched |
| Reviewed assets | 4/4 hash-matched |

Vendor was copied as ordinary files after verification and then hashed again.
No junction or shared dependency directory exists. Package parity is not a
security advisory audit.

Source allowlist is unchanged: PHP under app, bootstrap, config,
database/migrations, database/factories, routes, resources/views and
tests/Support; exact composer files, artisan, TestCase, two public assets and six
browser tooling files. Bootstrap cache, database/schema, seeders, active data,
.env files and runtime caches are excluded.

Manifest SHA-256:
`6f6d1af196480364f1c5165bf728c8f73af6fbc07ce36fda7eafa2fd78f2bafd`.

Pinned source archive SHA-256:
`05ad143b8f4588a1392ddcfa984badf775f3436846cda310dad3b0e4d6544e78`.

Supervisor config SHA-256:
`935385c6d07b1fbb50412a5ff2fdfe09ee37c2469e34a4b70df5a5cc89858912`.

## Reviewed source hashes

| Source-relative path | SHA-256 |
| --- | --- |
| tools/testing/tests/Browser/checkout-supervisor.py | `a30335e2e717472f8539b159618333e7b3ac5ac8b31f86824bc36632faab5ac0` |
| tools/testing/tests/Browser/test_checkout_supervisor.py | `c7a3e95d5035d24bc2e7ce009a5b18e070fe67210843f18d73560df7f6aef2c6` |
| tools/testing/tests/Browser/serve-checkout-session.php | `48103ec2b8e6f0a78836028e2c60b4fef2134cbb880cde39b3c9ca5e442adecf` |
| tools/testing/tests/Browser/checkout-session.browser.mjs | `e6d8475aa494c1fc7270683b77e1f9a4f742a45462be3c3f234edfd67fa71cd6` |
| tools/testing/tests/Browser/https-loopback-proxy.py | `cd66a224abe8c10c3e5e5742a5f2e354a3fe717cc0c429c083b66e6f0dc71fa7` |
| public/css/checkout-summary-v1.css | `4f480f3a9fba460a272d7835144f007def3f5817fcaab1b3b513c1fa0e115890` |
| public/brand/oncam-logo-full-color.png | `3d9925178da1f7ed0af8046340b721f58c57e8e475e580427baceadbd00e7597` |
| resources/views/checkout/summary.blade.php | `8958dae1d5b45cbd8651aa8963abe21c454a7a485d36f2b8fbb7d030d9e2dce2` |

The supervisor source explicitly contains the accepted typed
`listener_inspection_failed` behavior and the listener-only six-second
allowance. Other runtime deadlines are unchanged.

## Runtime configuration

`supervisor-config.json`, `runtime.ini` and `browser-config.json` are fresh
run-local files. Browser config remains isolated, headless, offline by default,
blocks service workers, disables proxy/background networking, pins only the two
loopback host mappings, and uses 30-second action/navigation limits. Empty
`.playwright/` bounds cached CLI discovery to the candidate.

| Key | SHA-256 |
| --- | --- |
| php | `bd9082be692fe9153758c11bf85f726ea84a8b4f8a5f3a270c25b7064f82a7c4` |
| python | `fda7026477256845afab371e354c4d512896665f1761939cb5887d0a9dec257a` |
| node | `b7d912484d42e7a0d0cb5b26a86410ec973a79ece7d61ad535e2d1a97a9026e1` |
| powershell | `7600ffe12da441fe89d035b13801e8e91d064bc544a27b19a5cf49f6ab8b18f5` |
| cli | `fda252270793401d2856530a5f503adf7fc326fc07bed97e413138f67c50662b` |
| browser | `409805a16d6416087e6b2f778df1cf8f7bbb267d6b99f6b5bb0a618eace234f2` |
| ini | `97ac524e1d6782681a9f22a4590e5ca6de2e61424a1aee1a95eb47827388adf3` |
| browser_config | `e79132b7675072bed6dada982c569a69e649bd868155c5f40ddb2ee53354e899` |
| cert | `d5905fbd05b2e3f1f9db3108ecfbc8407c74186d6df7903650464453bc677072` |
| key | `b65f481c9178412bc06a9f6d880a985f6028d74a9bfa93077bd537caf67b7846` |

Exact tool and file paths are stored in the reviewed supervisor config; all ten
hashes were independently verified. No tool was installed or refreshed.

The synthetic certificate/key use RSA-2048, SHA-256, CA=false, server-auth, and
SAN exactly `psikotes.oncam.id` plus `oncam.id`. Public key matching passed.
Validity is `2026-09-04T18:31:09+00:00` through `2026-09-06T18:32:09+00:00`, and the final check at
`2026-09-04T18:33:09.741711Z` confirmed more than 24 hours remained. Private-key
contents are not included here; no host trust or DNS setting changed.

## Static verification and boundary

- Fresh copied harness `--self-test`: **64 pure checks PASS**, before bootstrap.
- Browser driver Node syntax: PASS.
- Supervisor and TLS proxy Python AST: **2 PASS** without import/runtime.
- Static inventory: 22,145; critical files 71; tool/config hashes 10; asset hashes
  4; listener correction present; runtime artifacts absent: PASS.
- No `browser.sqlite`, `baseline.json`, `fixtures.json`, `storage/`,
  `supervisor.json`, integrity evidence or invalid latch exists.
- No HTTP request, port inspection, listener, owned process, PHP application,
  browser, migration, database or supervisor lifecycle was exercised.
- No PHPUnit/Pint/PHPStan/PG or browser result is claimed by this report-only
  preparation. The accepted listener tests belong to the prior reviewed commit.
- Git cached diff check passed with only this report staged.

A future smoke would require coordinator review of this exact path, pin,
manifest, supervisor config, tool hashes and still-valid certificate, followed
by separate explicit runtime authorization. Stop after this report.
