# Backend P16-pay-e: zero-price and payment HTTP design audit

Date: 2026-09-05

## Scope and conclusion

This is a report-only audit. It introduces no writer, route, configuration,
provider call, schema change, or active feature.

The paid self-payment core can reach the ADR-014 HTTP response without another
billing validator, but it needs a final session-authorized read of the persisted
bill after provider issuance. The zero-price path cannot safely run from the
current confirmation payload: that payload has no `consultationRequested`, while a
zero-price package can become payable when consultation is selected. The narrow
recommendation is therefore:

1. keep confirmation responsible for profile and both current consents;
2. let the fixed payment command, whose sole product input is the explicit
   consultation boolean, select either the zero-price or positive self-payment
   path under freshly locked checkout-session authority; and
3. amend the one sentence in ADR-014 that says the zero writer is called by
   confirmation. It should instead say that the payment command may call it only
   after verifying both current consents in the same transaction as free
   settlement and activation.

This avoids silently defaulting consultation to false, preserves the existing
confirmation HTTP contract, and supports already-accepted consent without
re-presenting a form. No database migration is needed.

## Evidence from the current implementation

### Confirmation and session authority

- `ConfirmIntegratedCheckout` receives an
  `IntegratedCheckoutConfirmationInput` that contains a hydrated principal
  (`app/Data/Integrations/IntegratedCheckoutConfirmationInput.php:21-27`). It
  rejects ambient context/transactions, then opens a service transaction
  (`app/Actions/Registration/ConfirmIntegratedCheckout.php:41-57`).
- It reloads and locks the organization, client, source, package/items, attempt,
  participant, handoff history, session, consent rows, and confirmation audit
  (`ConfirmIntegratedCheckout.php:59-100`). It validates current persisted scope
  and status rather than trusting payer or IDs from JSON
  (`ConfirmIntegratedCheckout.php:161-190`).
- Exact replay is recognized by one persisted audit plus matching profile and
  current unwithdrawn consent records; changed or damaged state conflicts
  (`ConfirmIntegratedCheckout.php:101-114`). First execution writes missing-only
  profile fields, both consents, and one audit (`ConfirmIntegratedCheckout.php:116-148`).
- Activation currently runs only after that transaction commits, in a second
  service transaction (`ConfirmIntegratedCheckout.php:149-157`). The existing test
  proves exact confirmation replay is write-idempotent
  (`tests/Feature/Registration/IntegratedCheckoutConsentTest.php:227-256`) and
  settled activation does not create another bill or duplicate activation output
  (`IntegratedCheckoutConsentTest.php:305-321`).
- The request body is exactly `profile` plus both consents and has no consultation
  choice (`app/Http/Requests/ConfirmIntegratedCheckoutRequest.php:23-44`). It builds
  its DTO from the middleware principal (`ConfirmIntegratedCheckoutRequest.php:61-74`).
  Both accepted consents remove the confirmation form entirely because the
  presenter only accepts two `required` consent states
  (`app/Services/Integrations/CheckoutConfirmationFormPresenter.php:42-68`).
- The canonical mutation seam already solves the authority race. It accepts raw
  selector/CSRF credentials only inside the caller's service transaction
  (`app/Actions/Integrations/CheckoutSessionLifecycle.php:177-197`), reloads the
  canonical graph and current payer policy (`CheckoutSessionLifecycle.php:211-297,
  358-368`), and returns a capability whose principal becomes inaccessible after
  commit or rollback (`app/Data/Integrations/CheckoutSessionMutationScope.php:11-36`).
  The PostgreSQL test at
  `tests/Postgres/CheckoutSessionMutationScopeConcurrencyTest.php:81` covers a
  policy writer winning the lock and the mutation rejecting stale authority.

### Zero price and settlement

- A server price snapshot explicitly supports a zero base and zero total, while a
  requested consultation must have a positive add-on
  (`app/Services/Payments/AssessmentPriceSnapshot.php:15-41,64-91`).
- Preview derives payer/policy and price from persisted rows. It classifies total
  zero as `free`, includes the canonical snapshot and policy snapshot, and makes
  the selection non-reservable (`app/Actions/Payments/PreviewAssessmentBill.php:55-127`).
  Reservation consequently rejects all-free input, and its persistence loop never
  creates a bill item for a free item
  (`app/Actions/Payments/ReserveAssessmentBill.php:88-96,129-149`).
- The schema already permits exactly one charge per attempt, non-negative zero
  money, a nullable `free_settled_at`, and constrains a non-null free marker to a
  zero amount (`database/migrations/2026_08_31_000200_create_assessment_billing.php:20-42,
  83-97`). The model already casts the marker
  (`app/Models/AssessmentCharge.php:12-30,57-61`).
- Settlement is explicit: a zero charge is settled only when the marker is at or
  before the evaluation instant and no bill item exists
  (`app/Services/Payments/AssessmentSettlementReader.php:23-37`). The checkout
  reader likewise reports zero without the marker as unsettled and reports a valid
  marker as `free` (`app/Services/Integrations/CheckoutPaymentFactsReader.php:122-130`;
  `tests/Feature/Integrations/CheckoutPaymentFactsTest.php:112-121`).
- Root's accepted zero-price isolation test demonstrates the ambiguity that blocks
  confirmation-time defaulting: no consultation yields total zero and no bill,
  while the same package with consultation yields a server-snapshotted positive
  total (`D:/LSI/Web/Psikotes/tests/Feature/Payments/ZeroPriceDassPayerIsolationTest.php:35-78`).

### Activation atomicity

- `ActivateSettledAssessment` requires service context and uses a database
  transaction. It follows the organization-first billing lock order, reloads the
  charge/attempt/participant, validates the checkout-v2 graph and snapshot, and
  uses the canonical settlement reader
  (`app/Actions/Payments/ActivateSettledAssessment.php:32-75`).
- It evaluates prerequisites independently per test, so missing identity or consent
  leaves that entitlement locked without failing the other test
  (`ActivateSettledAssessment.php:76-103`). Newly ready entitlements, attempt status,
  activation outbox, and audit are written together
  (`ActivateSettledAssessment.php:104-118`).
- The outbox primitive itself requires an active service transaction and uses a
  stable attempt-scoped deduplication key
  (`app/Actions/Notifications/EnqueueAssessmentActivation.php:13-33`).

### Positive self-payment and URL presentation

- `PrepareCheckoutSelfPayment` is already credential-bound and calls
  `lockMutation()` before it derives the persisted payer and reservation intent
  (`app/Actions/Integrations/PrepareCheckoutSelfPayment.php:41-78`). Its single
  canonical validator checks bill/item/charge scope, positive IDR amount, immutable
  request and policy snapshots, state, and persisted HTTPS provider identity
  (`PrepareCheckoutSelfPayment.php:121-170,201-239`).
- `CoordinateCheckoutSelfPayment` commits preparation before Claim, bypasses Claim
  for pending/paid, and returns only an internal issuance routing result
  (`app/Actions/Integrations/CoordinateCheckoutSelfPayment.php:27-56`).
- `IssueCheckoutSelfPayment` invokes the canonical P10 issuance outside transaction
  and RLS context, maps only exact issuance success to pending, and exposes only
  pending/paid (`app/Actions/Integrations/IssueCheckoutSelfPayment.php:26-58`;
  `app/Data/Integrations/CheckoutSelfPaymentIssuanceResult.php:9-17`). Tests prove
  one create plus exact lookup outside transaction, no provider call on pending or
  paid replay, and no second create after unknown outcome
  (`tests/Feature/Integrations/CheckoutSelfPaymentPreparationTest.php:444-478,
  483-569`).
- The current payment facts DTO deliberately has `actionAvailable=false` and no
  URL (`app/Data/Integrations/CheckoutPaymentFacts.php:11-39`). The summary HTTP
  contract is likewise inert (`tests/Feature/Integrations/CheckoutSummaryHttpTest.php:102-125`).
  The production routes contain exchange, summary, logout, unavailable, and
  confirmation only; there is no payment route (`routes/web.php:30-48`). All are
  globally inert because checkout and confirmation switches default false
  (`config/assessment_integration.php:11-35`).

## Recommended zero-price contract

### Entrypoint and state machine

Add an internal `SettleZeroPriceCheckout` with input only:

```text
CheckoutSessionMutationCredentials credentials
bool consultationRequested
```

It must reject ambient RLS context or transaction, then own one service
transaction. Within it:

1. call `CheckoutSessionLifecycle::lockMutation(credentials)` and consume the
   transaction-bound principal immediately;
2. use the already established organization-first lock order; load the package
   items under the existing locks and capture one database instant;
3. lock both consent rows, capture the current psychotest and DASS documents, and
   require exact accepted, unwithdrawn evidence at that instant. The package must
   contain DASS plus at least one primary assessment;
4. resolve current payer policy from the persisted funding mode and create the same
   canonical policy-snapshot shape used by Preview;
5. capture or validate the exact price snapshot for the explicit consultation
   choice. Only total `0`, IDR, and no bill-item linkage qualify;
6. if the charge is absent, insert the attempt-scoped charge with the server
   snapshot, payer, policy snapshot, and database `free_settled_at`. If it exists,
   require every immutable field and snapshot to match and require a canonical
   marker; never backfill or rewrite a conflicting charge;
7. insert exactly one non-PII `assessment_charge.free_settled` audit, keyed for
   replay by the unique charge/attempt identity; then call
   `ActivateSettledAssessment` before the outer transaction returns.

The activation action's nested Laravel transaction remains inside the same outer
database transaction. An audit, entitlement, activation audit, or activation
outbox failure must bubble out so the charge, marker, zero audit, ready rights, and
outbox all roll back. Identity incompleteness is the existing intentional no-op:
the charge remains explicitly settled while access stays locked.

Replay is exact and write-free. A canonical existing zero charge/marker rechecks
the current session, policy, consultation choice, both current consents, absence of
bill items, and snapshot; it may invoke activation again so later identity evidence
can unlock access, while deduplication and existing entitlement state prevent a
second outbox/audit. Missing marker, changed snapshot/payer/policy, withdrawn or
stale consent, nonzero total, terminal attempt, or any linkage conflict fails
closed without mutation.

### Why confirmation should not be widened for this increment

`ConfirmIntegratedCheckout` should not receive a silently assumed consultation
choice, and it does not need a credential refactor solely to implement free
settlement. Its current body cannot distinguish these two legitimate states:

```text
base 0 + consultation false => free
base 0 + consultation true  => positive payable amount
```

Creating the first charge at confirmation would freeze `consultationRequested=false`;
the later positive request would correctly conflict against that immutable charge.
Adding the boolean to confirmation would widen the accepted P15 body, request hash,
frontend confirmation form, replay semantics, and documentation, while the accepted
payment command already owns this exact choice. The smaller design is to run the
zero writer from payment orchestration after confirmation.

An already-consented summary intentionally has no confirmation form. That is not a
blocker: the payment control sends the explicit boolean, the zero action reloads
current consent evidence, and a successful free result is returned as
`paymentState=paid, paymentUrl=null`; the next summary read presents the more
specific historical state `free`. A retry after an ambiguous HTTP response repeats
the same payment command and is idempotent. Because checkout/confirmation remain
default OFF and have never been live, there is no legitimate production population
that needs a confirmation-only backfill. Missing or inconsistent pre-release
records must remain recovery-required rather than being inferred during a GET.

If the coordinator requires literal conformance to ADR-014 lines 93-99 instead,
the alternative needs an ADR amendment plus an explicit
`consultationRequested` field in confirmation and a credential-based confirmation
refactor. It is larger and duplicates the product choice across confirmation and
payment; it is not recommended.

## Exact payment HTTP result without a race

The controller must not return a URL from the provider response or from the
middleware principal. After `IssueCheckoutSelfPayment` finishes, a final service
transaction must authenticate the same selector/CSRF again and reload the same
attempt's canonical bill. This closes races with payment finalization, revocation,
policy changes, recovery, or terminalization that occur during the provider call.

Avoid a second validator by extending `PrepareCheckoutSelfPayment` with a read-only,
no-create method that shares its existing private `existing()` and `validated()`
path. It accepts credentials plus the consultation boolean, calls `lockMutation`,
requires an existing intent, and returns a new browser-safe typed projection:

```text
pending => paymentUrl is the exact persisted HTTPS invoice_url
paid    => paymentUrl is null
anything else => CHECKOUT_PAYMENT_UNAVAILABLE
```

The method must not preview, reserve, claim, issue, reconcile, or repair. It must
also require the same attempt/bill/item/charge, stable key, request hash, current
policy, provider identity, amount, currency, and consultation snapshot already
checked by `validated()`. `IssueCheckoutSelfPayment` should always call this final
read after its current paid/pending/issued decision, and may return the observed
later state: if pending becomes paid during the gap, return paid/null. If the bill
becomes unknown, terminal, corrupt, foreign, or the session is revoked, return the
generic conflict and never expose the old URL.

The HTTP adapter then maps only the typed result to the exact ADR shape:

```json
{"data":{"paymentState":"pending","paymentUrl":"https://persisted.example/..."}}
{"data":{"paymentState":"paid","paymentUrl":null}}
```

For a zero action, map its completed command result to paid/null; summary remains
the source of the `free` label. Organization, unselected, and every unavailable or
recovery case use the same generic 409 body. Validation remains 422, origin/CSRF/
fetch failures 419, disabled config 503, and unexpected exceptions use Laravel's
reported and sanitized 500. `ProtectCheckoutSessionHttpBoundary` already applies
no-store/private and the other privacy headers to every downstream status
(`app/Http/Middleware/ProtectCheckoutSessionHttpBoundary.php:16-25`), so the payment
route must keep it first.

The complete order is:

```text
private boundary -> payment feature gate/throttle -> session authentication
-> strict payment JSON+CSRF -> FormRequest/controller
-> zero preflight/settlement OR self prepare -> commit
-> Claim commit -> provider create+lookup outside context/transaction
-> final credential-bound persisted-result read -> commit -> HTTP mapping
```

No URL may enter logs, audit context, exceptions, telemetry, query parameters, or
cookies. No organization bill URL may pass through this projection.

## Proposed reviewed increments

Each code increment stays at five owned files or fewer; its report can be a
separate documentation commit.

### P16-pay-f — zero writer core

Ownership:

1. `app/Actions/Integrations/SettleZeroPriceCheckout.php` (new)
2. `app/Data/Integrations/CheckoutZeroPriceResult.php` (new, exact `settled` /
   `not_applicable` internal state only)
3. `tests/Feature/Integrations/CheckoutZeroPriceSettlementTest.php` (new)
4. `tests/Postgres/CheckoutZeroPriceSettlementTest.php` (new)
5. this slice's follow-up report

SQLite TDD: both current consents required, exact false/true consultation pricing,
self and organization payer snapshots, absent/create and exact replay, identity
missing remains settled/locked, identity complete activates both tests, stale/
withdrawn consent, policy/revoke/scope/snapshot/bill-item corruption, audit/outbox
failure rollback, and zero provider/bill calls. PostgreSQL: two workers yield one
charge/marker/free audit and one activation set, policy/consent writer serialization,
failure after an activation write rolls back the entire outer transaction, and
runtime non-owner RLS denial outside the service boundary.

Dependency: accepted mutation scope, Preview snapshot/policy shapes, settlement
reader, and P8b activation. No HTTP integration yet.

### P16-pay-g — final persisted self-payment projection

Ownership:

1. `app/Actions/Integrations/PrepareCheckoutSelfPayment.php` (shared validator,
   additive no-create read method)
2. `app/Actions/Integrations/IssueCheckoutSelfPayment.php` (final reread)
3. `app/Data/Integrations/CheckoutSelfPaymentIssuanceResult.php` (exact URL invariant)
4. `tests/Feature/Integrations/CheckoutSelfPaymentPreparationTest.php` (additive)
5. follow-up report

SQLite TDD: create/replay pending URL, paid/null, paid race after issuance, revoked
or policy-changed final read, wrong bill/link/snapshot/consultation, non-HTTPS URL,
organization URL isolation, provider response URL differing from persisted URL,
unknown/terminal state, no-create final read, and no provider call on replay.
Existing P10 issuance/reconciliation regression is mandatory. PostgreSQL is needed
only if implementation changes lock/query order; otherwise cite the accepted P7/
P10 races and do not duplicate them.

Dependency: accepted pay-d. It may proceed independently of pay-f.

### P16-pay-h — payment transport boundary, still route-test-only

Ownership:

1. `app/Http/Requests/CheckoutPaymentRequest.php` (new exact boolean request)
2. `app/Http/Middleware/VerifyCheckoutPaymentJsonMutation.php` (new)
3. `app/Services/Integrations/CheckoutSessionHttpContract.php` (typed payment
   enable/body-limit/rate classification)
4. `config/assessment_integration.php` (default-false payment switches and bounded
   body size; no new environment variable)
5. `tests/Feature/Integrations/CheckoutPaymentHttpBoundaryTest.php` (new test-only route)

Test 419/422/429/503 and sanitized 500, duplicate JSON keys, exact content type,
query/cookie/header confusion, hostile origin/fetch metadata, config bounds, and
private headers on every outcome. No production route or controller in this slice.

Dependency: the HTTP body contract in ADR-014 only; it can proceed in parallel with
pay-f/pay-g if it does not touch their files.

### P16-pay-i — controller/orchestration and default-off production route

Ownership:

1. `app/Actions/Integrations/ExecuteCheckoutPayment.php` (new: zero-or-self routing)
2. `app/Http/Controllers/CheckoutPaymentController.php` (new)
3. `routes/web.php` (one fixed route in the reviewed middleware order)
4. `tests/Feature/Integrations/CheckoutPaymentHttpTest.php` (new)
5. follow-up report

SQLite HTTP TDD must prove the exact response keys/statuses, persisted-only URL,
free mapped to paid/null, organization generic denial, no internal identifier or
PII, paid/pending replay, unknown no re-create, current credential revalidation,
and full privacy headers. Run related confirmation/summary/route regression.
PostgreSQL should cover the new zero-vs-self command race only if pay-f's core test
does not already execute the same top-level composition.

Dependencies: pay-f, pay-g, and pay-h all reviewed. Route remains default OFF.

### P16-pay-j — frontend binding and browser acceptance

Frontend can already work in parallel on pure fixture-driven behavior because the
request and response shape is fixed by ADR-014 and the accepted transport module:
consultation boolean selection, loading/double-submit lock, pending HTTPS redirect,
paid/null reload, generic conflict/unavailable copy, keyboard focus, and mobile
layout. It must not infer payer/amount, enable action from the current
`actionAvailable=false`, hardcode a host, or claim live/browser completion.

Binding the production page to `/checkout/payment`, changing the server-projected
action capability, and browser tests must wait for pay-i. Browser acceptance needs
the default-off synthetic environment and covers complete/partial/already-consented
profiles, self pending/paid/free with both consultation choices, organization
waiting without URL, reload/back/double click, expired/revoked session, 419/422/409/
503/500, mobile, keyboard, no cross-attempt/batch data, and no stray provider POST.

## Compatibility and decision requests

1. **ADR-014 amendment required:** change only the zero-path caller from
   “confirmation” to the authenticated payment command after current consents.
   The remaining no-bill/no-provider, atomic activation, and default-off decisions
   stand unchanged. This is the only architectural decision needed before pay-f.
2. **No schema change:** the unique attempt charge, zero-valued snapshots,
   `free_settled_at`, audit, entitlements, and outbox already represent the full
   state. A new idempotency column would duplicate the charge uniqueness and audit
   evidence.
3. **Confirmation refactor deferred:** converting
   `IntegratedCheckoutConfirmationInput` from principal to credentials would be a
   useful independent hardening cleanup, but is not necessary for the recommended
   zero command and would touch the accepted P15 contract. Do not mix it into pay-f.
4. **Result DTO compatibility:** adding URL to the current pay-d DTO is safe only
   after the final persisted reread; never populate it directly from
   `PaymentInvoice`. Its constructor must enforce pending+HTTPS and paid+null.
5. **Summary remains read-only:** do not settle free, issue invoices, or repair
   missing markers from GET `/checkout`. `actionAvailable` remains false until the
   final reviewed UI/server binding; changing it earlier would advertise an action
   whose route is still inert.

## Verification boundary

This audit read source, migrations, tests, accepted lane reports, current root
plan/todo/parallel coordination, both root specs, and ADR-014. It ran no PHPUnit,
PostgreSQL runner, browser, server, provider fake, migration, or database command.
Only this report is an owned change. The baseline dirty overlay is intentionally
untouched.
