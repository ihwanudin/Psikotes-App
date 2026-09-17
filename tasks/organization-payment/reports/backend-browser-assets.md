# Synthetic checkout asset delivery prerequisite

2026-09-05. One implementation slice, not a source-copy refresh or HTTP/browser run.
Only serve-checkout-session.php, its existing pure-test helper, and this report
changed. Root UI740f236 is prior accepted HTTP evidence, not retested here.

## Route and integrity contract

The synthetic PHP router now handles exactly GET /css/checkout-summary-v1.css and
GET /brand/oncam-logo-full-color.png. Dispatch occurs after the existing runtime,
environment/source-root and full/cooperative integrity guards, but before DB
initialization, environment bootstrap/autoload, Laravel, authentication or sessions.
Unrelated URLs return to the unchanged existing application routing. No generic
public-file fallback, document-root escape, production route or static server was
added.

Request strings never become filesystem paths: two literal URI -> source/public
path -> MIME mappings are the only successful routes. Query strings (including
empty ?), fragments, encoded/traversal/case/double-slash alternatives and methods
other than exact GET are refused for asset paths, with generic404. Decoding is
used only to recognize requests to refuse, never to select an asset. Other unknown
URLs cannot retrieve files through this helper and remain normal application URLs.

Each successful read pins manifest bytes to the reviewed digest, requires the
literal asset entry and valid SHA-256, validates canonical run/source/file and
all parent paths, and reads at most262145 bytes. Assets over262144 bytes fail.
The SHA is computed from the exact returned bytes; readfile does not reopen the
path after validation. PNG bytes must identify as image/png/IMAGETYPE_PNG. CSS
must be nonempty UTF-8 text without NUL or leading HTML markup. This is basic
fixed-file type sanity, not a general CSS parser or sanitizer; reviewed manifest
content remains authoritative.

Successful MIME is fixed text/css; charset=UTF-8 or image/png. Both success and
asset-level404/500 responses use no-store, private; nosniff; no-referrer. No Set-Cookie,
ETag/304 flow, session or auth lookup occurs in the asset handler. Incoming Accept,
Cookie and Authorization cannot change its MIME/body/authority. Asset exceptions
are sanitized; cooperative failures latch invalid and return a generic500 before
framework bootstrap. Failures in the earlier common guards retain their existing
opaque behavior; this slice does not claim a new global error-header boundary.

Critical set changes explicitly from69 to71 with exactly:

- public/css/checkout-summary-v1.css
- public/brand/oncam-logo-full-color.png

All original69 entries remain. Default mode still runs the original full-tree
verifier; cooperative pre/post lifecycle and accepted=false are unchanged. The
lighter guard now hashes both assets on every request as well as the handler's
exact-byte check when delivering one. Old cooperative source copies without these
entries cannot be reused by silently repairing their manifests.

## Actual verification

TDD: --asset-tests first exited1 because the asset response primitive did not exist.
GREEN:98 asset assertions on NEW random synthetic OS-temp fixtures. Coverage includes
both exact bodies/MIMEs/headers, spoofed incoming headers, seven wrong methods,
queries/encoding/traversal/case/unknown asset paths, wrong hash, missing file,
manifest/digest mismatch, mismatched MIME and oversized content. Four native
Windows junction fixtures (source/public/CSS/brand parents) are rejected by both
asset reads and critical checks, then junctions are removed nonrecursively/restored.
Targets stay inside each newly created fixture. Both asset hashes are also tested
through the lighter critical guard. The PNG test is a distinct synthetic1x1 fixture;
it is not a recreation or copy of the public ONCAM brand image.

Existing64 pure checks PASS; existing52 lifecycle fixture cases/161 assertions
PASS with71 critical files. Two PHP syntax checks, Pint --test on exactly the two
owned PHP files, and git diff --check PASS. No server/browser/DB, real inspector
PID test, authenticated HTTP, or image rendering was run. Tests only call helpers;
header assertions are pure response-plan checks, not observed wire headers.
Native junction setup uses the already bounded inspector-command helper, without
inspecting/killing an owner/browser/server PID. No dependencies installed.

Root public logo was read-only checked:1200x1027 image/png,53281 bytes. Root CSS is
2505 bytes. No root source was changed; no image was downloaded/generated/replaced.
No active .env/data/DB, provider/notifier, production route, timeout/TTL or auth/gate
changes. Existing exact run and its stored manifest were not accessed/refreshed.

## Required future overlay and hashes — not automatic approval

The supervisor's asset_delivery_review requires exactly the two public paths,
resources/views/checkout/summary.blade.php and this harness. Contract keys match.
The current worker lacks the new CSS and still cannot represent the root UI overlay.
A future NEW approved copy must receive the reviewed root view/CSS/logo plus the
reviewed harness/driver/proxy/supervisor/helper revisions, then a NEW reviewed full
manifest. Do not update the existing run or turn these file hashes into an
automatically accepted manifest.

Read-only root file SHA-256 values at this review:

| File | SHA-256 |
| --- | --- |
| resources/views/checkout/summary.blade.php | 8958dae1d5b45cbd8651aa8963abe21c454a7a485d36f2b8fbb7d030d9e2dce2 |
| public/css/checkout-summary-v1.css | 4f480f3a9fba460a272d7835144f007def3f5817fcaab1b3b513c1fa0e115890 |
| public/brand/oncam-logo-full-color.png | 3d9925178da1f7ed0af8046340b721f58c57e8e475e580427baceadbd00e7597 |

Worker reviewed candidate raw-byte SHA-256 (root must independently reconcile
its integrated file bytes/line endings before constructing a new manifest):

| Script under tools/testing/tests/Browser | SHA-256 |
| --- | --- |
| serve-checkout-session.php | 48103ec2b8e6f0a78836028e2c60b4fef2134cbb880cde39b3c9ca5e442adecf |
| checkout-integrity-tests.php | ab4f9eb98f0d0a4c23b4e77ce46d89cc136152ba90063c41ede627496d8ac799 |
| checkout-session.browser.mjs | 1558a132824fc326cf4d8a1abbcc8afa4c8ebdbe2f7a9a4a5d19976625702b13 |
| https-loopback-proxy.py | cd66a224abe8c10c3e5e5742a5f2e354a3fe717cc0c429c083b66e6f0dc71fa7 |
| checkout-supervisor.py | 0ce775a3a6ee3ac048fba84e700e194277077c259ea21dcc408c8f083a106e54 |

Those hashes identify separate candidate files, not an asserted common baseline.
The next runtime decision remains bounded fresh-copy preflight plus at most3 smoke
request permits, target<=5seconds, currentTLS10/browser30, owned cleanup and
incomplete/no acceptance. No such run or copy refresh was performed or authorized
by this report. Commit only the three lane files, preserve unrelated baseline,
and STOP for coordinator review.
