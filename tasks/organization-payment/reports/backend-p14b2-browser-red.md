# P14b2 browser evidence and proposed ADR-012 amendment

## Status — RED, pending coordinator review

Date: 2026-09-04. Worker baseline remains `a49354f`; harness commit `04f92fe`.
The coordinator explicitly requested this RED handoff after reviewing the native
form finding. No production middleware, view, route, config, canonical ADR, or
session lifecycle changed. This document proposes a decision; it does not accept
or implement one. P14 acceptance remains open, and P15 has not started.

## Actual browser result

Chromium **151.0.7922.34**, cached Playwright CLI, PHP **8.3.30**, SQLite in a
fresh `oncam-checkout-<32 hex>` OS temporary directory, and an ephemeral TLS
bridge bound to **127.0.0.1:443** forwarding only to **127.0.0.1:8126**.
The browser maps the two destination hostnames to loopback, denies other DNS,
blocks service workers, and intercepts synthetic source HTML. No OS hosts-file
or trust-store modification was needed. TLS certificate errors are ignored only
inside these disposable browser contexts; this does not validate production TLS.

The completed run's final failure is:

```text
Error: Native no-JS logout must be 303; got 419, Origin=null; preceding browser cases passed
```

All assertions before that final native mutation passed:

- Real Laravel `Auth::login` and encrypted file session; actual `web` + `auth`
  probe returns exactly `AUTH:1` before and after both source exchanges.
- Native body-only POSTs from `seleksi.beasiswajepang.id` and
  `seleksi.serbaindo.com`; Chromium omits the Lax Laravel login cookie. Checkout
  does not emit a login Set-Cookie; the browser retains its exact encrypted byte.
- Exact host-only Secure/HttpOnly/Lax checkout cookie pair on `/checkout`,
  replacement of fixed attacker cookies, fresh expiry, and matching hidden/meta
  CSRF delivery. The path-scoped pair does not reach the Laravel auth probe.
- Replay fails while the prior valid checkout still hydrates; refresh, back,
  token-free URLs, two-tab principal sharing, desktop 1280px and mobile 390/320px
  without horizontal overflow, and keyboard access to logout.
- Missing, wrong, and duplicate explicit CSRF return 419 without losing the
  valid session. Progressive header logout returns 303 and clears only checkout
  cookies while preserving the current Laravel login cookie byte.
- Persisted expiry and scope revoke clear the pair; delivery-loss simulation
  plus P13 recovery rejects old cookies and establishes the new generation.
- Foreign Origin returns generic 403; wrong destination host returns generic
  404. Observed checkout responses have the complete privacy header set; no
  credential occurs in URL/Referer. No unexpected console or network request.
  Five Chromium resource-error messages correspond to the deliberately tested
  three 419s, foreign 403, and wrong-host 404, rather than application exceptions.

The ninth POST to the canonical exchange endpoint also succeeds in a separate
JavaScript-disabled context. Its subsequent native logout fails. The wrong-host
POST is additionally exercised and is not counted in those nine.

Sanitized native-logout probe (also reproduced a second time):

```json
{
  "origin": "null",
  "referrerPresent": false,
  "explicitCsrfCanonical": true,
  "selectorSent": true,
  "csrfCookieSent": true,
  "status": 419
}
```

Fetch Metadata fields were absent in this disposable TLS/intercepted run. No
positive Fetch Metadata support claim follows from this evidence.

The synthetic database after the run and repeated denied native logout contains
seven sessions: three ACTIVE, one EXPIRED, three REVOKED. Revocation reasons are
one LOGOUT, one RECOVERY_REISSUED, one SCOPE_REVOKED. There is one successful
progressive logout; native retries do not revoke their session. Orders, bills,
bill items, charges, entitlements and outbox messages each contain **zero rows**.
Scanning the disposable PHP/proxy/application logs found **zero raw handoff,
selector or CSRF credential matches**. No screenshot, HAR, trace or cookie-state
export containing credential material was produced.

## Harness corrections and limits

The root expression `dirname(__DIR__, 4)` is correct for
`tools/testing/tests/Browser/serve-checkout-session.php`. The working run uses an
absolute router path and the disposable empty public directory as document root.
A stale PHP 8.3.26 server was also competing for port 8126; only the lane's exact
matching stale process was stopped. PHP 8.3.30 uses a fresh minimal ini with
SQLite, mbstring, OpenSSL, fileinfo, intl, curl and sodium extensions. Its init
completed against the new synthetic database; no active application DB or .env
was read or copied. Each retry uses a new temporary fixture, never resets an old
database or reuses an already-issued alias.

Laravel's installed `Illuminate/Http/Middleware/TrustProxies.php::handle` resets
Symfony's direct trust settings. The harness therefore configures
`TrustProxies::at` / `withHeaders` for loopback. The TLS bridge supplies the
forwarded scheme/host/port. Browser Cookie, Origin and Referer remain unchanged;
the old cookie deduplication/custom-header tunnel is removed. The probe keeps
real `web` + `auth`, rather than accepting a fabricated success response.

Laravel deliberately re-encrypts its login cookie on an intentional web probe.
The harness compares its byte across checkout traffic, then takes the new byte
after the web probe for the later logout comparison. Every checkout response is
also checked independently for absence of login Set-Cookie.

The synthetic source initially used `no-referrer`, which also produced literal
`Origin: null` on its cross-site POST. The source fixture now uses
`strict-origin-when-cross-origin`, so its token-free source origin is preserved.
Checkout's production `no-referrer` header remains unchanged. Real source-site
referrer policy and deployment must still be reviewed before cutover.

## Reproduce without active services

Use a fresh directory under the OS temp root named `oncam-checkout-` followed by
32 lowercase hex characters. It must contain no `.env`. Set
`ONCAM_CHECKOUT_BROWSER_DIRECTORY` to that directory and
`ONCAM_CHECKOUT_BROWSER_PORT=8126` for the PHP processes. Ensure ports 443 and
8126 are free first; do not terminate unrelated listeners. Use a PHP ini with
the extensions above and a one-day disposable self-signed certificate whose
SANs include `psikotes.oncam.id` and `oncam.id`; keep its private key in that temp
directory, never in the repository. Python's already installed `cryptography`
was used to generate the synthetic certificate (no download or real certificate).

From the worker root, substituting absolute runtime paths:

```text
php -c <temporary-ini> tools/testing/tests/Browser/serve-checkout-session.php init
php -c <temporary-ini> -S 127.0.0.1:8126 -t <temporary-dir>/storage/public <absolute-worker-root>/tools/testing/tests/Browser/serve-checkout-session.php
python tools/testing/tests/Browser/https-loopback-proxy.py <temporary-dir>/cert.pem <temporary-dir>/key.pem 443 8126
playwright-cli -s=p14b2 open --config <temporary-dir>/playwright.json
playwright-cli -s=p14b2 run-code --filename tools/testing/tests/Browser/checkout-session.browser.mjs
```

Run servers hidden on Windows and retain their exact process IDs for cleanup.
The Playwright JSON uses `browser.browserName=chromium`, `isolated=true`, the
installed cached Chromium `executablePath`, `headless=true`, context options
`ignoreHTTPSErrors=true` and `serviceWorkers=block`, and launch arguments:

```text
--host-resolver-rules=MAP psikotes.oncam.id 127.0.0.1, MAP oncam.id 127.0.0.1, MAP * ~NOTFOUND
--no-proxy-server
--disable-background-networking
```

The committed acceptance retains a strict native **303** expectation. It is
last so the independent assertions run, not skipped or converted into a pass.
Close only this named browser and stop only the recorded lane server/proxy PIDs.

## Proposed ADR-012 amendment — native mutation with suppressed Origin

### Context and evidence

Current ADR-012 simultaneously requires `Referrer-Policy: no-referrer`, an exact
destination Origin for every mutation, and no-JS native form logout. The browser
reproduction shows these requirements conflict for this navigation. The Fetch
standard's Origin-header algorithm explicitly serializes the origin as `null`
for relevant non-CORS requests under `no-referrer`. That aligns with the observed
native POST, while the tested progressive fetch-header path succeeds.
[WHATWG Fetch, Origin header](https://fetch.spec.whatwg.org/#append-a-request-origin-header).

This is the **literal HTTP string `null`**, not a missing Origin header, empty
value, PHP null, or proof of a trusted same-origin document. Opaque/sandboxed and
foreign documents can also produce null. Source exchange must continue requiring
one of the two exact trusted origins; no proposed relaxation applies to handoff.

### Alternatives for review

| Option | Consequence | Assessment |
| --- | --- | --- |
| A: retain exact Origin, require JavaScript for mutation | Progressive header path can preserve current server policy and no-referrer; native logout is explicitly unsupported and UI/acceptance must change | Valid if product accepts JS-required mutation; does not meet current no-JS requirement |
| B: allow only a narrowly validated literal-null native form branch | Retains no-referrer and native form UX; relies on authenticated checkout scope and explicit unpredictable CSRF, with Fetch Metadata as additional rejection evidence | Recommended for review to preserve current UX; requires explicit ADR acceptance and security tests |
| C: relax checkout referrer policy | May preserve native exact Origin, but changes the existing privacy contract and could expose checkout URLs as referrers | Not recommended for this bounded fix |

### Recommended proposed rule (not implemented)

Keep exact-Origin progressive/form behavior. For the native POST `/checkout/logout`
only, consider a separate branch for the exact string `Origin: null` after all
of the following hold:

1. Fixed HTTPS destination and method/path match the server contract. Reject
   query identifiers, arbitrary Host, foreign non-null Origin and missing Origin.
2. Existing canonical authentication reloads current tenant, attempt, handoff
   generation and active checkout session, and verifies both selector and CSRF
   delivery-cookie digests. Login/admin roles cannot bypass this check.
3. Exactly one canonical bounded URL-encoded `_checkout_csrf` raw-body field is
   present, matches the parsed field and delivery secret in constant time, and
   therefore matches the already verified persisted digest. Reject header-only,
   simultaneous channels, duplicates, encoded/bracket fields, missing/wrong
   secret, cookie-only and legacy credentials for the literal-null branch.
4. If Fetch Metadata is present, require `Sec-Fetch-Site: same-origin` for this
   exception, and reject contradictory mode/destination (expected native
   `navigate` / `document`). Explicit `cross-site`, `same-site`, `none`, malformed
   or unknown values do not qualify. Do not equate same-site with same-origin.
   For this proposal, absence is handled by the full credential/CSRF proof,
   not by assuming same-origin; making metadata mandatory would instead be a
   separate browser-compatibility decision. The current harness observed absence.
5. Reuse existing logout revalidation/transaction, replay semantics, generic
   failure response, no-store/no-referrer headers and exact cookie clear scope.

Fetch Metadata describes request context, including relationship to the target
origin. It adds rejection signals but does not authenticate a non-browser client
or replace the CSRF secret. Redirect chains must be tested, not classified only
by their final URL. [W3C Fetch Metadata](https://www.w3.org/TR/fetch-metadata/).

**Threat boundary:** neither null nor cookie presence is trusted. A foreign page
or sandbox can submit a logout form but cannot read the private hidden CSRF under
same-origin isolation. Cookie-only logout remains denied even if SameSite sends
cookies. The active matching secret remains mandatory. XSS or prior secret
leakage compromises this proof; CSP, no-referrer/no-store, no credentials in
URLs/logs, and no secret-bearing telemetry remain essential. Metadata can be
forged by a non-browser holding stolen credentials, so it is defense in depth.
Do not emit `Access-Control-Allow-Origin: null`, add CORS trust for null, relax
global Laravel CSRF/session settings, or preauthorize future profile/payment
mutations through this logout-specific proposal.

### Required RED-to-GREEN matrix after decision

| Area | Required future evidence |
| --- | --- |
| Native success | JS-disabled same-origin form, no-referrer, literal null, exact cookies/body; 303, one revoke/audit, exact clear and unchanged real Laravel auth |
| Origin distinction | missing, empty, `NULL`, malformed/list/duplicate, arbitrary/foreign origins rejected; exchange null still rejected |
| Secret proof | absent/wrong/foreign/expired selector or delivery digest; missing/wrong/other-session raw CSRF; header-only/null, double-channel, duplicate/bracket/encoded/extra fields all denied |
| Metadata | same-origin native accepted with full secrets; cross-site/same-site/none or contradictory fields denied; absent fields follow explicit selected policy; metadata alone never authorizes |
| Opaque/foreign attack | controlled foreign no-referrer form, sandboxed opaque form and redirect-tainted form cannot logout with cookie-only or attacker token, including same-site sibling origin |
| Scope/history | revoked client/tenant/attempt, recovered generation, expiry, stale session and IDOR cannot mutate; no fallback to Laravel login or legacy tokens |
| Replay and isolation | double-submit one audit; no billing/entitlement/outbox; native/logout recovery race retains existing transaction rules; negative attempts cannot revoke another session |
| Regression | progressive exact-Origin header path remains valid; all current transport/privacy/500/limiter negatives stay strict; repeat real browser and relevant PHP tests |

## Verification and boundaries

- Focused checkout handoff/session regression with
  `phpunit.organization-payment.xml`, `tests/Feature/Integrations` and filter
  `Checkout(Session|Handoff)`: **53 passed / 1,041 assertions**, zero skipped.
- Full-project PHPStan: **passed, 0 errors**, synthetic testing environment.
- Pint for the PHP harness: passed after formatting; Node `--check` and Python
  AST parse passed; staged `git diff --check` passed for the harness commit.
- Browser: **RED**, with the independent cases and precise final failure above.
  No cross-browser, production TLS or Fetch Metadata compatibility claim.
- PostgreSQL was not repeated: only browser harness/report files changed; the
  accepted transaction/RLS code is unchanged. No new concurrency claim.
- Full unrelated UI regression was not repeated; this worker still has no Vite
  manifest. No fake manifest or relaxed application assertion was introduced.
- No active DB/.env, source/endpoint activation, payment/provider/notifier call,
  production route change, deployment, push or new task/agent.
- Cleanup: the named Playwright browser and recorded PHP/proxy processes were
  stopped; ports 443/8126 have no remaining listener. Automatic execution policy
  rejected the recursive removal of eight explicitly listed, path-validated
  lane-created temporary directories. That removal was not retried through
  another mechanism. Synthetic DB/certificate/log scratch remains outside Git,
  along with the untracked `output-checkout-runtime.txt` pointer; no real secret
  is involved. No unrelated process or older temporary directory was removed.

**STOP for root review of the RED evidence and proposed amendment.**
