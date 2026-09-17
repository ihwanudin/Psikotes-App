# P16 test-only HTTP payment presentation matrix

Date: 2026-09-05. Frontend test-only increment after mandatory-DASS HTTP evidence
`202be08` was integrated as root `063c3b2`. Production Blade, CSS and P14/P15
server code are unchanged.

## Covered server projections

The existing `CheckoutMandatoryDassHttpPresentationTest` now drives six additional
persisted payment arrangements through the actual private summary GET:

| Fixture arrangement | Expected private projection |
| --- | --- |
| Organization, pending bill | Dibayar lembaga; Menunggu pembayaran; Rp 100 |
| Self, paid own allocation | Bayar sendiri; Pembayaran lunas; Rp 100 |
| Organization, rejected bill | Dibayar lembaga; Pembayaran ditolak; Rp 100 |
| Self, expired bill | Bayar sendiri; Pembayaran kedaluwarsa; Rp 100 |
| Base package Rp0, no consultation, explicit free settlement | Dibayar lembaga; Gratis — tercatat oleh server; Rp 0; consultation Tidak |
| Base package Rp0 plus requested Rp50.000 consultation | Bayar sendiri; Menunggu pembayaran; Rp 50.000; consultation Ya |

The P14 summary contract exposes own-attempt total `amountIdr` plus
`consultationRequested`; it does not expose a separate base amount. The test
therefore constructs the accepted price snapshot with baseAmount 0 and verifies
the server-projected totals 0/50.000 without inventing a new response field or
computing a displayed price in the view.

For payer/amount changes, the fixture follows existing composite-FK order: remove
the allocation, change the persisted funding/charge/bill tuple, then create the
matching self allocation. Amount changes likewise remove the old item before
changing charge/bill and recreating it. This is synthetic setup, not a payment
mutation endpoint or browser authority.

Every case asserts the exact payer, state, amount, consultation flag and localized
HTML copy, plus `actionAvailable=false`. Payment payload keys remain allowlisted;
organizationName appears only for organization payer. A bill carries synthetic
invoice URL, provider reference and proof key sentinels, none of which appears in
HTML or inert JSON. No Total batch or Invoice copy is rendered. The DOM retains
only the Keluar button, with no anchor, checkbox or radio; access stays locked,
`startAvailable=false`, and there is no payment/start action.

DASS remains `required` in all six price/payment cases. The page continues to say
that DASS-21 is mandatory for the package and its result does not affect
eligibility. Neither payment state nor amount alters that consent/access copy.

## TDD and verification

First matrix run: organization cases passed, but the three self cases errored on
the database composite FK because the test changed payer while the organization
allocation still existed (**7 tests, 144 assertions, 3 errors**). The test setup
was corrected to use the canonical delete/change/reinsert order. The next run left
only the Rp50.000 consultation case RED because it changed charge amount before
removing the old Rp100 allocation (**7 tests, 194 assertions, 1 error**). Applying
the same FK-safe order to amount changes made the final focused run GREEN:

```text
PHPUnit 12.5.33; PHP 8.3.26
OK (7 tests, 219 assertions)
Time: 00:02.553; Memory: 66.00 MB
```

The run used the previously prepared inactive OS-temp clone of accepted root
`dd3a82c`, whose production source includes `910e5c6`; only this updated test was
copied into it. Environment remained the explicit Windows allowlist with
copy-local APP_BASE_PATH/storage/view cache, null logging, no `.env`, and
`phpunit.organization-payment.xml` forcing SQLite `:memory:`, array cache/session,
fake services and stray-request prevention. It is not the blocked browser exact
run. All PHPUnit processes exited.

Focused PHP syntax, Pint `--test` and `git diff --check` for the two owned files
also passed. Browser/server/supervisor, active DB, external provider and outbound
network were not used. This does not prove provider integration, live checkout
mutation, browser rendering or P16 completion.

## Owned delta and stop

- `tests/Feature/Integrations/CheckoutMandatoryDassHttpPresentationTest.php`
- `tasks/organization-payment/reports/frontend-payment-http-matrix.md`

No Blade/CSS/controller/middleware/request/action/route/config/catalog/canonical
docs, portal/backend source, active data, `.env`, dependency or lockfile changed.
No deploy, push or feature activation. The retained inactive temp copy remains
subject to the cleanup limitation documented in the prior frontend HTTP report;
it is not authorized for future reuse without fresh ownership/preflight review.
Stop for coordinator review.
