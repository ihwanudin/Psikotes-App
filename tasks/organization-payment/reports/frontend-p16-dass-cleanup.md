# Frontend P16 DRAFT — mandatory DASS-21 cleanup

Date: 2026-09-05

## Result

The React DRAFT checkout consent presentation now matches the canonical Blade
rule: DASS-21 is a mandatory part of the psychotest package and has a separate
mandatory consent. Its required state renders one native checkbox, initially
unchecked, with a visible label, `required`, and an associated explanatory text.
The checkbox passes the DOM `checked` boolean directly to `onDass`, so checking
and unchecking produce literal `true` and `false` values under the existing typed
callback.

The former optional/reject copy, two radio choices, and declined-state wording
were removed. An accepted server state remains readonly text. Legacy
`declined`/`not_applicable` props no longer hide or disable the DASS section;
because those variants do not contain a current consent document, presentation
fails closed with an accessible alert that the mandatory consent is unavailable.
This does not reinterpret that legacy state as acceptance.

Psychotest and DASS controls remain separate. The component still states that
DASS results do not determine work eligibility and that clinical data is not
shared with the paying branch. Synthetic fixture package/document copy was also
corrected from optional to mandatory, and synthetic declined states were changed
to accepted where those scenarios were intended to represent completed consent.
No server/legal text or canonical Blade was edited.

## TDD and verification

- RED: the dedicated SSR fixture build succeeded, then **26/28 tests passed**.
  The two new/changed DASS assertions failed on the old optional heading, reject
  radio, and hidden `not_applicable` behavior.
- GREEN: `npx vite build --config tests/Frontend/IntegratedCheckout/vite.config.ts
  --mode test` transformed **12 modules** and built the isolated SSR fixture in
  **32 ms** on the final run. Running its output passed **28/28 tests** with no
  skips or failures.
- Focused TypeScript: `npx tsc --noEmit -p
  tests/Frontend/IntegratedCheckout/tsconfig.json` passed.
- Focused ESLint on the component, fixture, and SSR test passed.
- Prettier check on those three files passed after formatting the two changed
  TSX files. `git diff --check` passed.
- A focused search found no DASS line containing optional/reject wording in the
  three lane files.

No browser was run as required, so native constraint-validation bubbles, actual
Tab/Space behavior, focus-ring rendering, screen-reader announcements, and mobile
geometry are not claimed. No DTO, checkout form, Blade, payment transport,
controller, route, configuration, backend, database, `.env`, provider, deploy,
or push changed. This is DRAFT presentation cleanup only and stops before DOM or
production binding review.
