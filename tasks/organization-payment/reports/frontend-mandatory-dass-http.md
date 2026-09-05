# Mandatory DASS checkout summary — focused HTTP evidence

Date: 2026-09-05. Test-only frontend follow-up after `b5bfb04` was accepted as
root `910e5c6` and root independently passed real Blade 4/4 plus checkout HTTP
40/40. This increment adds HTTP evidence only; no production source changed.

## New focused scenario

`CheckoutMandatoryDassHttpPresentationTest` establishes a synthetic private
checkout session through the existing test-only routes and real P14 lifecycle.
It then projects a paid attempt with DASS in its frozen test types, unaccepted
current DASS consent, two missing required fields (`fullName`, `phone`), missing
optional email, and hostile current DASS title/text.

The response proves:

- the payload and rendered page retain server state `consents.dass=required`;
- the requirements panel says two required profile fields are missing, names only
  Nama lengkap and Nomor telepon, and does not include optional Email;
- DASS is presented as mandatory for the package and the page states its result
  does not affect eligibility;
- there is no checkbox, radio, link, start action or payment action; the existing
  logout form/button remains the only action;
- hostile DASS markup is preserved as text in the inert JSON/DOM, while no injected
  image or event handler appears and raw hostile markup is absent from HTML;
- no-store/private, Pragma, no-referrer, DENY, nosniff and the existing CSP remain;
- CSRF is the current verified cookie value and appears only in meta plus the
  logout hidden input; a native-shape logout returns 303, persists session
  `REVOKED` with reason `LOGOUT`, then reuse redirects to unavailable.

No client-side consent authority, eligibility calculation or mutation was added.
Package, payment, payer, amount, profile required flags and access remain server
projections. The test changes no P15 request/action and does not activate routes.

## TDD and actual verification

The first worktree attempt stopped before assertions because the lane snapshot
lacks root P14 HTTP classes. A fresh isolated clone of root `dd3a82c` was therefore
created under OS temp. Its `composer.lock` SHA-256
`44aa7ea181ecf0accdd18a05ae5da39bc8d9016c88431bfe9aebfcb536720e16`
matched this lane before vendor was copied.
The copy contained no `.env`. Only the new test was overlaid.

The first complete HTTP RED reached 40 assertions and exposed a test expectation,
not an application defect: the established lifecycle records status `REVOKED`,
not literal `LOGOUT`. A second RED showed the correct column is
`revocation_reason`; asking SQLite for the nonexistent `terminal_reason` produced
that identifier as a value. The assertion was corrected to the existing server
contract. The first GREEN focused run:

```text
OK (1 test, 44 assertions)
```

The existing summary suite then passed together with that GREEN version in the
same fresh copy:

```text
CheckoutSummaryHttpTest + CheckoutMandatoryDassHttpPresentationTest
OK (26 tests, 707 assertions)
```

Both used PHP 8.3.26, `phpunit.organization-payment.xml`, SQLite `:memory:`, array
cache/session, fake services and `Http::preventStrayRequests` from the existing
test base. The spawned PHPUnit environment was rebuilt from the documented
Windows allowlist plus explicit copy-local APP_BASE_PATH, storage/view cache and
null logging; inherited APP/DB/service credentials were not passed. That combined
run took 35.830 seconds. Cookie-clear attributes were then added to the focused
scenario; the final owned test passed **1 test / 57 assertions** in 3.150 seconds.
PHP syntax, focused Pint and `git diff --check` for the new test/report also passed.

## Boundaries and files

Owned files:

- `tests/Feature/Integrations/CheckoutMandatoryDassHttpPresentationTest.php`
- `tasks/organization-payment/reports/frontend-mandatory-dass-http.md`

The existing `CheckoutSummaryHttpTest.php` in this lane is byte-identical to root
SHA-256 `94af51ddf416bb11e77f36f6a46aae1bd777e562a5bce7c9033b42268df68eaf`
but is an untracked baseline file, so it was not staged or recommitted. The new
separate class avoids hiding a whole baseline snapshot inside this delta.

No browser, server, supervisor, exact-run, active DB, `.env`, outbound request,
controller/request/action/route/config/view/CSS/canonical-doc/portal/backend
change, deploy or push. HTTP feature tests do not prove browser geometry, native
keyboard, asset retrieval or P15 mutation wiring. Stop for coordinator review;
P16 remains incomplete.

All PHPUnit processes started by this lane exited. Cleanup of the newly created OS-temp clone was
attempted only after resolving it under the temp root, validating its exact random
name and `.git` marker. The execution policy rejected recursive deletion, so the
inactive copy remains at
`C:/Users/ThinkPad/AppData/Local/Temp/oncam-frontend-http-e760d0f052aa48b1aeace8fda9040ac7`.
Two unrelated ambient PHP processes were observed and not inspected, interrupted
or killed. This retained copy is test evidence only and is not an exact-run or an
authorized source for future execution without a new ownership/preflight check.
