# P17c browser harness summary-v2 refresh

Date: 2026-09-06  
Baseline observed: `5c3abd6`  
Status: static harness refresh complete; runtime browser acceptance remains open.

## Scope and safety

This increment changes only the checkout browser driver, its process-free static
contract test, and this report. It did not start the application, a service, a
database, a browser, or a provider transport. It did not read active environment
values or change any feature flag. The synthetic runtime remains expected to run
with payment gates default OFF and with the existing fake provider plus stray HTTP
request prevention in `serve-checkout-session.php`.

## Contract refreshed

- Every inert summary lookup now targets `checkout-summary-v2`; no v1 DOM selector
  or v1 contract-version assertion remains in the driver.
- Payment has exact ordered keys including `action`. `actionAvailable` must equal
  `action !== null`. Actions are limited to `/checkout/payment`, `IDR`, and exact
  `select`/`continue` choices with safe nonnegative integer arithmetic, canonical
  consultation ordering, and payer/state/snapshot correlations matching the
  current v2 contract.
- The process-free positive corpus covers default-off, self select, self continue,
  and organization zero-price action shapes. The negative corpus covers missing or
  mismatched capability, unsafe action path/currency, bad arithmetic/order, and
  snapshot mismatches.
- DASS consent accepts only `accepted` or `required`. Canonical fixtures contain
  DASS-21 plus at least one non-DASS psychotest; missing DASS, `not_applicable`, and
  DASS-only compositions are rejected.
- Runtime assertions still retain the privacy-marker exclusions, private response
  headers, IDOR/cross-origin fences, stale-session/CSRF checks, and keyboard/mobile
  coverage. In addition, every delivered synthetic summary must expose no payment
  action, and the run must observe zero POSTs to `/checkout/payment`.

## Static evidence

- `node --check tools/testing/tests/Browser/checkout-session.browser.mjs`: passed.
- Pure Node `run(null)` probe: 17 positive and 59 negative probes passed;
  `browserStarted=false`.
- `php tools/testing/tests/Browser/checkout-integrity-tests.php --contract-tests`:
  15 static source assertions passed; browser/service/child-process flags false.
- PHP lint and scoped Pint test: passed.
- Scoped `git diff --check`: passed.

The pure validator and source-contract checks are preparation evidence only. They
are not P17c browser acceptance and do not prove rendered UI or runtime transport.

## Residual gates

1. The reviewed disposable P17c supervisor/browser run is still required to prove
   v2 rendering, CSP/escaping, keyboard/mobile behavior, session/IDOR fences, and
   zero provider transport together. It was intentionally not run in this lane.
2. TypeScript parser, exported tuple type, compile probes, and runtime tests were
   aligned in `3f0acc2`: DASS-only, duplicate, and noncanonical compositions are
   rejected, while every canonical DASS-21 plus non-DASS subset remains valid.
3. No P15/P16/P17c acceptance checkbox should be closed from this static refresh.
