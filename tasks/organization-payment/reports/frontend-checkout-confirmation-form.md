# Frontend — checkout confirmation form presentation checkpoint

Date: 2026-09-05
Reviewed server baseline: `c1bbc6d` (`ConfirmIntegratedCheckout` P15 writer)

## Scope and result

This increment adds an optional, server-injected presentation contract to the
existing private Blade checkout summary. Without `confirmationForm`, or when its
action, missing-field set, consent versions/hashes, or legal-review state is not
valid, the page remains the existing readonly summary. No controller, route,
configuration, writer, schema, entitlement, payment, or portal source changed.

When the synthetic contract is valid, the view renders only the required profile
fields whose P14 summary state is `missing`. Locked profile values, optional email,
identity, branch, package, payer, amount, payment state, and access state remain
readonly and are absent from the form payload. Text/date/tel/select controls and
their options come from the injected presentation descriptor; they are usability
hints only and do not replace P15 validation or server authorization.

Both psychotest and DASS consent checkboxes are mandatory, separate, and initially
unchecked. The hidden document version and SHA-256 hash come from the injected
server fixture; the version must match the current P14 document before the form is
shown. There is no DASS decline control. `legalReviewPending` blocks the form.
Payment facts and access remain unchanged, including zero-price and paid states.

The form action is restricted to one relative `/checkout/<lowercase-hyphen-name>`
path and is injected only by tests in this increment. The HTTP test callback makes
no business write and verifies the assessment and consent state remain unchanged.

## Verification actually run

- TDD RED: standalone real-Blade test reported 1 failure of 6 cases because the
  injected form did not yet exist.
- Standalone real-Blade render: 6 cases, 0 failures. It covers all six required
  profile controls, select options, optional-email omission, both mandatory
  unchecked consents, no DASS decline, readonly-field omission, and fail-closed
  absent/foreign/stale/legal-pending inputs.
- Isolated HTTP test on detached baseline `c1bbc6d`: 8 tests, 247 assertions,
  all passed using `phpunit.organization-payment.xml` with SQLite `:memory:`.
  It covers private headers, XSS escaping, payment variants, actual synthetic
  callback payload, and absence of business writes.
- Pint focused on the two PHP test files: passed.
- PHP syntax for Blade and both tests: passed. `git diff --check`: passed.

The first isolated HTTP attempt failed before assertions because a clean Git clone
does not contain Laravel cache directories. After creating ignored runtime cache
directories, the same lane passed. Dependencies were copied physically from this
worktree only after identical `composer.lock` SHA-256 values were confirmed:
`44AA7EA181ECF0ACCDD18A05AE5DA39BC8D9016C88431BFE9AEBFCB536720E16`.
No `.env` was present in the isolated checkout.

## Boundaries and unresolved wiring

This is not a production response contract or P16 endpoint. P15 currently accepts
strict JSON with boolean `true`, while a no-JavaScript HTML form encodes checkbox
values as strings. The synthetic callback proves native form shape only; it does
not claim compatibility with `VerifyCheckoutSessionJsonMutation` or authorize a
production confirmation route. Final P14/P15 HTTP adapter, canonical options,
error rendering, conflict/retry behavior, and progressive-JavaScript submission
remain review dependencies.

No browser run was performed because this checkpoint explicitly retains the known
host/browser blocker. Therefore responsive CSS, keyboard traversal, native browser
constraint messages, literal Origin behavior, and JS-disabled end-to-end delivery
are not claimed. No active database, login, external network, payment, invoice,
WhatsApp, deployment, push, or gate activation was used. P16 remains incomplete
end-to-end and stops here for review.

No lane process remains running. Cleanup of the isolated checkout at
`C:/Users/ThinkPad/AppData/Local/Temp/oncam-p16-form-57d9c59d9cd84ce5b8e7eed29ad0a3dc`
was attempted only after resolving and checking that it was under the OS temp
directory, but the execution policy rejected recursive deletion. The inactive
copy contains synthetic/in-memory test artifacts and no `.env`; it is retained as
an explicit cleanup limitation.

## Progressive JSON transport increment

Date: 2026-09-05

Follow-up to lane checkpoint `337d22e` adds one local external module,
`/js/checkout-confirmation-v1.js`. The server-rendered submit button now starts
disabled and the page includes an explicit `noscript` explanation. The module
enables submission only after it validates the same-origin relative action, exact
dedicated CSRF format, allowlisted missing-profile controls, two complete consent
version/hash groups, required initially-unchecked consent checkboxes, and absence
of unknown/duplicate form names.

The submit listener always prevents native encoding. It uses `FormData` only as a
DOM value reader, rejects unknown, duplicate, empty, optional-email, and readonly
entries, then constructs the exact JSON top level `profile` + `consents` with both
`accepted` values as literal booleans. Fetch uses the injected relative action,
`credentials: same-origin`, `redirect: manual`, `Content-Type: application/json`,
`Accept: application/json`, and `X-Checkout-CSRF` copied from the validated hidden
field. It does not read or display response bodies.

The form and button expose a pending state and suppress a second submit. Status
200 keeps submission disabled and asks for a reload. Conflict 409 and expiry 419
also stay disabled and require a reload. Validation 422, throttle 429, unexpected
status, and network failure show fixed nonsensitive messages and permit retry;
all non-success outcomes focus the polite live status. The client does not alter
payment, access, profile-lock, or consent authority.

Verification actually run after the transport increment:

- TDD RED: Node could not resolve the not-yet-created module and the real-Blade
  test failed its disabled-submit assertion.
- `node --test tests/Frontend/IntegratedCheckout/confirmation-transport.test.mjs`:
  **4 tests passed**. Covered DOM-shape allowlist, exact JSON/literal booleans,
  same-origin fetch options, all requested statuses, network sanitization,
  enable-after-validation, native-submit prevention, pending/double-submit,
  retry and error focus.
- `php tests/Frontend/IntegratedCheckout/summary-mandatory-dass-render.test.php`:
  **6 cases, 0 failures** using real Blade and synthetic props.
- Detached isolated baseline `c1bbc6d`, no `.env`, SQLite `:memory:`:
  `CheckoutMandatoryDassHttpPresentationTest` **8 tests / 247 assertions passed**.
- `eslint --no-ignore` on the module and Node test, Prettier check on both,
  `node --check` on the module, focused Pint on both PHP tests, and
  `git diff --check`: passed.

No real browser was run because the assigned host blocker remains. Consequently
native browser FormData/constraint UI, keyboard focus rendering, mobile geometry,
actual cookie/Origin delivery, CSP enforcement, and a real P15 response are not
claimed. The prior synthetic PHP callback remains a presentation-only proof; the
new Node fetch seam proves the requested JSON request contract without registering
a route. Final controller wiring, response schema, reload/navigation behavior,
and end-to-end P16 acceptance remain review dependencies. No lane process is left
running; the previously retained inactive temp checkout remains the same cleanup
limitation described above.
