# F5 fixture browser QA review — 2026-09-14

Verdict: **REPAIR-REQUIRED** for the testing fixture/browser gate. This is testing-only evidence and does not establish production F5, T15, T22, or T23 completion.

## Baseline and scope

- Dispatch baseline: `537ae7b41e921e252c2dbef9bc983e23f00cd0d4` (the then-current `codex/f2-wave1-integration`).
- Integration branch observed after dispatch: `4de3fb4d20302ff95b325b1f158978d535b557c1`; this review intentionally remained bound to the dispatch baseline.
- Browser flow: `PsychologistReviewFixture` at `/admin/psychologist-review-fixture`.
- Production files were read-only. All added files are contained in this evidence directory.
- Runtime data was synthetic in disposable SQLite database `psikotes-f5-browser-0922-20260914.sqlite`.

## Outcome

The independent browser harness recorded 21/23 checks passing.

- Access boundary passed: guest was redirected to login; `super_admin`, `branch_admin`, and `staff` received concealed 404 responses with no fixture identifier/content; psychologist access succeeded.
- Projection privacy passed: HPP DOM excluded detailed DASS/raw evidence, while the psychologist-only internal projection contained it.
- Keyboard/focus passed: Enter switched projections and restored focus to the new heading; V2 blocker moved focus to `#procedure-note`.
- Draft behavior passed: the before-unload warning binding existed and a reload discarded the temporary V2 draft.
- Safety gates passed: readiness recalculated, while persistence, signing, and publication remained unavailable; V3 exposed no override/sign/publish actions.
- Responsive geometry passed at requested widths 320, 390, 768, and 1280. The 15px difference between requested and client widths is the visible vertical scrollbar; document width equaled client width at every size.
- Network/write review passed: only login and Livewire fixture POSTs occurred; no unexpected write route was called. The only external requests were expected `ui-avatars.com` GETs, fulfilled locally by the harness.
- Console review passed for positive flows. Three browser 404 console entries were produced solely by the three intentional concealed-denial navigations and are classified as expected.
- Database inspection after the run found only the synthetic seed rows (`admins=4`, `branches=1`) and browser sessions (`sessions=35`); no other business table was non-empty.

The fixture cannot prove production cross-tenant isolation because it has no tenant-bound persistent report reader. It proves only that the synthetic fixture is concealed from every tested non-psychologist role. Production cross-tenant behavior remains not verifiable from this flow.

## Repair findings

1. The synthetic-data banner's intended amber background is overridden by the generic card rule. In `resources/views/filament/pages/psychologist-review-fixture.blade.php`, the generic selector `[class*="rounded-xl"][class*="border"]` has higher specificity than `[data-synthetic-warning]`, so computed background is white (`rgb(255, 255, 255)`) instead of `#fffbeb`. The screenshot confirms orange text but no amber warning surface.
2. The accepted browser gate is not portable to this real Windows Chromium run. It requires `borderWidth >= 1`, while a declared 1px border is reported as `0.666667px` for both the banner and form controls. The controls remain 44px high and their borders are visible, but `tests/Frontend/PsychologistReview/browser.test.mjs` aborts with `Synthetic warning surface styles are not loaded`. The assertion should verify a non-zero visible border (and independently verify the intended color/background) rather than infer missing styles from a `>= 1` computed-width threshold.

These repairs require changes outside the authorized evidence directory and were therefore not applied.

## Commands and observed results

```text
npm ci --no-audit --no-fund
PASS — 533 packages installed

npm run build
PASS — 2317 modules transformed; production assets built

php ... vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php tests/Feature/Admin/PsychologistReviewFixturePageTest.php
PASS — 13 tests, 264 assertions

npx --yes --package @playwright/cli playwright-cli -s=f5qa run-code --filename tasks/evidence/browser-qa-2026-09-14-f5-review/run-browser.mjs
21 PASS / 2 FAIL — complete result summarized in result.json

npx --yes --package @playwright/cli playwright-cli -s=f5qa run-code --filename tests/Frontend/PsychologistReview/browser.test.mjs
FAIL — Error: Synthetic warning surface styles are not loaded

node --check tasks/evidence/browser-qa-2026-09-14-f5-review/run-browser.mjs
PASS
```

The local Laravel server used PHP 8.3 with `APP_ENV=testing`, synthetic `APP_KEY`, SQLite, array cache, database sessions, and sync queue at `127.0.0.1:8014`. Filament assets were published locally before the final browser run.

## Evidence files

- `result.json`: structured outcome, measurements, access matrix, and hashes.
- `run-browser.mjs`: reproducible independent browser harness.
- `guest-login-390.png`: guest redirect at 390px.
- `psychologist-internal-320.png`: internal review at 320px.
- `psychologist-internal-768.png`: internal review at 768px.
- `psychologist-v3-1280.png`: V3 stop state at 1280px.
- `checksums.sha256`: SHA-256 values for all screenshots.
