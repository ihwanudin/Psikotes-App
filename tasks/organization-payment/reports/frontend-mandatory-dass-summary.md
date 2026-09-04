# P16 checkout summary — mandatory DASS presentation

Date: 2026-09-05. Frontend-only increment following root `80590cf` and the
coordination checkpoint `d4f727a`. This updates the accepted private readonly
checkout summary; it does not implement P15 mutation wiring or activate P16.

## Result

The summary now groups outstanding participant requirements before the detail
sections. It counts and names only profile fields that the server projection marks
both `missing` and `required`; optional email remains visible in the profile list
but is not described as required. A participant whose profile is complete sees no
duplicate completion prompt and retains every locked display value.

The existing `consents.dass.state` union is the only authority used for DASS:

- `required` presents DASS-21 as a mandatory package component, says explicit
  consent has not been recorded, and explains that its result does not affect
  eligibility and remains separate from psychotest assessment;
- `accepted` keeps the recorded version and still labels the applicable component
  as mandatory;
- `not_applicable` retains the existing fail-closed copy and does not invent a
  mandatory-package assertion.

No new boolean, client mapper or server response shape was invented. The page
remains readonly: no consent checkbox/radio, no yes/no choice, no prechecked
control, and no profile/payment/start action. The only form and button remain the
existing CSRF-bound logout. Payer, package, amount, payment state and access copy
are rendered from the server summary unchanged. DASS presentation does not alter
`access.state`, `startAvailable`, test readiness or eligibility.

CSS adds a responsive requirements panel using the existing ONCAM palette and
spacing. Long server-provided profile labels retain the existing global wrapping;
the panel spans both desktop columns like the assessment/access/consent sections.
No global stylesheet or shared configuration changed.

## TDD and actual checks

New standalone real-Blade test:
`tests/Frontend/IntegratedCheckout/summary-mandatory-dass-render.test.php`.
It uses seven synthetic profile fields, synthetic legal text/versions, a readonly
paid amount and server-projected access. It bootstraps only Blade from the existing
vendor tree and writes compiled view cache under ignored
`storage/app/private/verification`; no Laravel application, HTTP, DB or environment
is loaded.

RED before view changes:

```text
FAIL missing required profile and required DASS are explicit
4 cases, 1 failures
```

GREEN after the minimal view/CSS delta:

```text
PASS missing required profile and required DASS are explicit
PASS accepted DASS and complete profile do not request data again
PASS not applicable remains fail closed without mandatory claim
PASS DASS consent presentation never changes server access or payment
4 cases, 0 failures; real Blade, synthetic props, no HTTP/browser/DB
```

The first case also asserts the optional email is absent from the required list,
there are no checkbox/radio inputs, and logout remains the only action. The last
case deliberately supplies `access.state=ready` alongside required DASS and proves
the view does not recompute that state or add a start action.

Focused verification also passed: PHP syntax for the new test and Blade view,
Pint `--test` for the new PHP test, and `git diff --check` for the four owned
files. Browser/server/supervisor and the old exact run were deliberately not
used because the host-process blocker remains open. Therefore this increment does
not claim visual geometry, live HTTP integration, mutation behavior, browser
accessibility, backend P15 completion or end-to-end P16 completion.

## Files and boundaries

- `resources/views/checkout/summary.blade.php`
- `public/css/checkout-summary-v1.css`
- `tests/Frontend/IntegratedCheckout/summary-mandatory-dass-render.test.php`
- `tasks/organization-payment/reports/frontend-mandatory-dass-summary.md`

The canonical `tasks/organization-payment/reports/frontend.md` is an untracked
baseline snapshot in this worker and was not staged wholesale. This focused report
is the lane-owned handoff instead. No controller/request/action/route/config/schema,
portal, canonical docs/checklists, `.env`, active data, dependency or generated
artifact is part of the commit. No outbound request, deploy, push or gate change.
Stop for coordinator review before any P15/P16 wiring.
