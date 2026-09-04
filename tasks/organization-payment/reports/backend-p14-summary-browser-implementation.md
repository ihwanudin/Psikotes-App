# P14 — bounded summary browser harness implementation

2026-09-04. Implementation candidate for guard/source-copy review, **not browser
GREEN**. Based on worker a4ea371 and accepted proposal/root 074a9fc. Exactly three
owned files: serve-checkout-session.php, checkout-session.browser.mjs and this
report. Application/view, proxy, shared middleware/config/routes, baseline overlays
and old scratch/build artifact are unchanged. No source copy, server, browser,
database init/migration, provider/notifier or dependency installation was run.

## Implemented serving guards

- Only a canonical direct OS-temp child named oncam-checkout- + 32 lowercase hex;
  root must be its independent source/ copy. Workspace execution cannot init/serve.
  Refuse .env* at run/source root, source bootstrap/cache files, run config/routes
  cache and linked/noncanonical paths before application bootstrap. Recursive
  realpath checks include source/vendor and scratch; no symlink/junction accepted.
- PHP 8.3.x with pdo_sqlite/mbstring/openssl/dom/fileinfo; modes init/verify only for
  CLI, serve only cli-server. HTTP requires 127.0.0.1, port8126, exact existing
  Host allowlist. Listener/process ownership and TLS443 bind still require the
  reviewed launch procedure; these guards do not themselves start or own ports.
- source-manifest.json is an exact sorted-independent path-to-SHA256 object for
  every source file, including independently copied vendor; no unlisted extra
  files. Relative paths reject traversal/absolute/backslash forms. Required
  controller/view/lifecycle/composer/DTO/harness/fixture files must be present.
  ONCAM_CHECKOUT_BROWSER_MANIFEST_SHA256 must equal the separately reviewed full
  manifest digest. This is local attestation to a reviewed copy, not a signature
  or authorization from an untrusted browser.
- All runtime env is process-local synthetic testing, APP_DEBUG=false, SQLite
  browser.sqlite, no DB_URL, isolated storage/file login session/cache paths.
  LOG_CHANNEL=null and instrumentation flags false. Before provider boot bind
  existing FakePaymentProvider/FakeNotifier and Mail::fake; keep preventStrayRequests.
  Assert actual fake bindings, then refuse any later payment/notifier resolution.
  Violations latch a fixed code in the run directory for verifier failure.
- Global bootstrap/control/CLI exceptions print only a fixed refusal. Reinstall
  that handler after Laravel bootstrap so console SQL/credential exceptions cannot
  escape via the framework's global CLI rendering. Normal HTTP pipeline still
  uses real framework error rendering; its report callback latches a fixed code,
  without exception text. No raw SQL bindings, provider payload or cookie logging.

GET /checkout now calls the real summary method with only the existing boundary
and named limiter. Exchange and logout protections are retained; old application
methods and templates remain unchanged. Login/auth probes still use real web/auth.
Driver controls are denied for browser TLS/proxy requests, any Origin, Fetch Mode,
Cookie, non-POST or missing synthetic marker. Driver JSON is bounded to 256 bytes,
exactly one alias key from the preseeded registry. Browser interception also aborts
control-path requests. No arbitrary SQL/model/field/value input was added.

## Fixed cohorts and immutable postconditions

All eleven aliases are seeded on init using existing AssessmentAccessFixture:
first, second, expiry, revoke, orphan, foreign-origin, wrong-host, no-js, price,
peer and same-person. same-person shares first's participant and organization but
has a separate attempt/package/client; price+peer share a two-member paid bill.
Others have no charge and partial profile. Price snapshot retains own amount100
and original package label while catalog becomes999 and parent total200. Separate
peer/invoice markers must never appear in summary. Synthetic closing-script,
markup and Unicode are included in own profile and required DASS document text.
The consent text override is harness-only before fixture creation, not legal
document/config file modification. DASS pending remains independent of IST access.

Concrete fixture constraint found during inspection: both IssueCheckoutHandoff
and ConsumeCheckoutHandoffTransaction require PROVISIONED. Therefore price cannot
be seeded READY and exchanged. It starts PROVISIONED/paid: first browser summary
must be paid/locked. A fixed, one-shot driver control prepare-price then changes
only that attempt's assessment_status to READY after exactly one ACTIVE checkout
session exists. Browser reload must show paid/partial (IST ready, DASS required).
This is explicit synthetic fixture preparation, **not a real activation/finalizer
proof**. No caller-selected status/id is accepted; repeat preparation fails. The
earlier idea of preparing on issue was rejected because consume also checks status.

Immutable baseline files are created with exclusive fopen(x) only on successful
init; init refuses existing DB/baseline/registry. No API overwrites the baseline.
Capture every non-internal SQLite table, with explicit required-table assertions
and full ordered row serialization (including consent/identity/engine/clinical
tables when present in current schema, not just billing counts). Table-set changes
fail. Baseline remains private synthetic scratch, never browser output or commit.

Final verifier compares all rows with exactly TWO expected fixture cell changes:
revoke's own integration client enabled=false; price's own attempt status=READY.
Expected values are applied to an in-memory comparison copy, never baseline file.
No whole table is omitted for those changes. Only checkout handoffs/sessions/audit
are compared as lifecycle separately; baseline for these must have been empty.
Expected final sequence: 11 handoffs, 9 sessions, 34 audits with exact action counts,
per-alias session status/scope and exactly one LOGOUT for second/same-person/no-js.
These are assertions awaiting runtime execution, not observed counts. Unknown
audit action/subject/tenant or credential/foreign-marker context fails. No audit
spam exemption. CLI verify and driver verify both reject any latched violation.

Additionally each normal HTTP request takes before/after business snapshots;
summary GET200 requires one real DB-clock query and one checkout-session update.
Context/transaction must return empty, no HTTP/mail sent. These checks happen in
the disposable harness after handling response and latch failures for final
verification; they are not new application authorization or transactional rollback.
The result field is businessMatchesFixedPlan, not a claim that fixture controls
never wrote anything. No summary/payment/consent/identity/entitlement writer added.

## Browser assertions implemented but NOT executed

The existing driver keeps the two controlled source origins, actual encrypted
Laravel login cookie and byte-preservation checks, real browser headers, protocol
cookie flags, fixation/replay, denied transport, native JS-disabled/logout and
hostile forms. Descriptor data-checkout-session comparison is replaced with exact
summary data; no internal ID is added to the application.

New checks include recursive v1 object keys, profile order/types, payment shape,
consent/document shape, access flags, one inert JSON script, no executable scripts,
injected img/onerror nodes or credential/foreign/invoice markers. New price case
asserts frozen own allocation, partial access and exact legal-text roundtrip.
Existing response observer enforces the real CSP and privacy headers. Actual
Chrome behavior and JS-disabled rendering remain pending runtime verification.

Same-person exchange in a second tab overwrites the shared cookie pair. Already
delivered first DOM is observed without calling it current authority; its old CSRF
must fail419 against the new pair. Reload must show the new attempt's package;
fresh logout succeeds. Tabs on the same current pair must yield identical summary.
After logout and recovery, history records only whether a new document response
was observed and whether summary DOM is visible; forced GET checks current server
authority. No immediate DOM removal or general BFCache eligibility claim. No
application pageshow/history script was introduced. Cross-attempt orphan ACTIVE
session is allowed explicitly in the expected per-alias state.

Network/console evidence uses fixed messages and safe path/status aggregates;
unexpected console text is no longer copied verbatim. No HAR/trace/storageState,
raw page/JSON export or credential screenshot is added. Assertions may inspect
protocol cookies and DOM in memory only. Existing known opaque-frame network and
instrumentation outcomes remain counted separately, never relabeled server denial.
New measured exchange count is eleven, pending the actual driver run.

## Checks actually run

- TDD RED: pure --self-test failed because the new path validator was absent.
  No autoloader/application/database was reached.
- GREEN pure self-test initially 9, expanded to **16 checks passed**: valid versus
  traversal/absolute/backslash paths; changed/missing/extra snapshot tables; direct
  marker POST versus GET/missing marker/Origin/proxy/Fetch-Mode/Cookie controls.
- Negative workspace invocation of verify with this real workspace as directory:
  exit1, exactly `Checkout browser harness refused.`, before bootstrap. No DB or
  source-copy directory created. This is a refusal test, not successful verifier
  execution or proof of every filesystem edge case.
- PHP lint passed. Pint formatting and subsequent --test passed. Node --check
  passed. git diff --check passed. Local node_modules ESLint executable absent;
  no dependency install or ESLint pass claimed.
- No PHPUnit/PG, successful init/verify, HTTP, browser, TLS, build or cleanup run.
  Thus fixtures, counts, reparse behavior on a copied tree, snapshot equality and
  browser sequence are implemented assertions awaiting the explicitly gated run.

## Setup review still required before launch

Prepare and review the independent source copy/manifest from the current worker
and accepted overlays, not old .scaffold identity artifact or git-archive alone.
Keep .env*, storage, bootstrap cache and old output excluded; independently copy
vendor matching composer.lock. New Blade and literal auth probes need no Vite.
Source inventory/hash checking includes vendor on every request: correctness is
fail-closed, but latency must be measured before claiming the 30s driver timeouts
are adequate. Do not silently weaken hashing to mask a slow run.

Review exact source-manifest digest and processes/ports before allowing init and
server/browser. Reuse unchanged TLS loopback proxy443->8126, isolated Chromium,
DNS overrides, abort allowlist and disposable certificate. No live hosts or global
trust-store/host-file edits. Successful future run must include CLI postcondition,
redacted log scan, closing its named browser, exact PID/listener verification and
path-validated native cleanup; do not touch old scratch. If blocked, report remaining
scratch rather than retry a forbidden deletion through another shell.

STOP for coordinator review of this candidate and the explicit prepare-price
fixture delta. No P15/public route/source/gate activation, real payments/messages,
active DB/.env, new agent/task, deploy/push, baseline reset/merge or proxy edits.
