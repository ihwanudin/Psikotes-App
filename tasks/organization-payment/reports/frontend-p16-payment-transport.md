# Frontend P16 — pure payment transport

Date: 2026-09-05

## Scope

This increment follows accepted ADR-014 and contains only a static JavaScript
transport module plus its pure Node test. It adds no DOM binding, navigation,
controller, route, configuration, backend contract, payment provider, logging, or
active feature. Existing worktree changes were retained without reset.

`requestCheckoutPayment(consultationRequested, adapters)` accepts one product
input, a strict boolean, and an exact adapter object containing only the dedicated
checkout CSRF and fetch implementation. The request path is fixed internally to
the relative `/checkout/payment`; same-origin behavior follows the active browser
environment without duplicating its hostname. A caller cannot supply origin,
path, payer, method, amount, currency, URL, ID, bill, participant, package,
attempt, or idempotency data.

The request uses POST, `credentials: same-origin`, `redirect: manual`, JSON
Content-Type/Accept, and `X-Checkout-CSRF`. Its body is exactly
`{"consultationRequested":boolean}`. Nonboolean input, malformed CSRF, nonfunction
fetch, or any extra adapter key is rejected before fetch. A review correction
after `e49ebea` removed the redundant production-origin constant and now rejects
caller-supplied `origin` instead.

HTTP 200 is parsed as exact `data.paymentState` plus `data.paymentUrl`, with no
extra top-level or nested key. Pending requires a valid HTTPS URL without username
or password; paid requires null. Redirected/opaque, malformed JSON, extra keys,
unknown state, HTTP/userinfo URL, pending-null, and paid-URL responses return one
fixed malformed result. Status 409/419/422/429/503 and unexpected status map to
fixed generic results without reading the response body. Network and AbortError
details are discarded. The module never logs or reflects a response/error/CSRF.

## TDD and verification

- RED: `node --test tests/Frontend/IntegratedCheckout/payment-transport.test.mjs`
  failed with `ERR_MODULE_NOT_FOUND` before the transport module existed.
- GREEN: the same command passed **4 tests / 4 groups / 0 failures**. Coverage
  includes both boolean request bodies, exact fetch arguments and CSRF header,
  all rejected inputs/adapters (including origin, URL, path, amount, and payer),
  exact success variants, malformed and extra-key matrices, HTTPS/userinfo checks,
  redirect checks, all required status mappings,
  network error, AbortError, no error-body read, and redaction.
- `node --check public/js/checkout-payment-v1.js`: passed.
- ESLint with `--no-ignore` on both lane files: passed after applying the repo's
  blank-line rules.
- Prettier check on both lane files: passed.
- `git diff --check`: passed.
- `npm.cmd run build`: attempted, but failed in the pre-transform Wayfinder plugin
  (`php artisan wayfinder:generate --with-form`) with **0 modules transformed**.
  The new static `public/js` module is not a Vite input. No generated file or
  unrelated baseline source was changed to bypass this old-worktree limitation.

No browser, server, `.env`, active database, outbound network, provider, deploy,
push, or feature activation was used. This proves only the isolated transport and
strict response parser. UI binding, navigation to a returned HTTPS URL, lifecycle
refresh, production endpoint availability, provider behavior, and end-to-end P16
remain outside this increment. Stop for review before any UI binding.
