# PR #83 UI/UX review — mobile readability — 2026-09-21

Verdict: **PASS**, 3/3 checks. Evidence for Lead's `ui-ux-pro-max`
(domain `ux`) review items 1 and 3 on PR #83.

## Item 1 — Responsive / Readable Font Size (High)

Before this PR, every policy line, note, and notice used `text-sm`
(14px) unconditionally — consent text a participant must actually read
was smaller than the skill's 16px-minimum guidance for mobile body
text. Fixed by switching the base classes to `text-base` (16px) with
`sm:text-sm` only above the `sm` breakpoint, so desktop keeps the
original 14px while mobile gets the larger, more legible size.

## Item 3 — List readability (Lead's own assessment, no direct skill match)

Tailwind's preflight strips default `<ul>` bullet markers, so the
seven policy points (camera use, face matching, screen-departure
logging, who reviews the data, fullscreen behavior, anti-copy) were
rendering as a single visually undifferentiated block. Fixed with
`list-disc pl-5` on the `<ul>`.

## Item 2 — Loading feedback (not independently browser-verified here)

Covered by `proctoring-consent-copy.test.ts`'s new test asserting the
`'requesting'` status gets its own `'Menunggu izin kamera…'` label
(distinct from the generic in-progress fallback), plus direct source
review of the `aria-busy`/spinner/delayed-hint JSX in
`proctoring-consent-screen.tsx`. This fixture's simulated
`getUserMedia` resolves or rejects immediately, so the `'requesting'`
status is not independently observable in a live browser run without
adding fixture-only scaffolding for a single transient state — judged
not worth the added fixture complexity for this review.

## Scope and method

- Fixture: `tests/Frontend/ProctoringConsent/`, dev server on
  `127.0.0.1:8098`.
- Harness: `run-browser.mjs`, an independent Playwright CLI script
  (`@playwright/cli run-code`).
- Measurements taken via `getComputedStyle()` directly on the rendered
  DOM — not inferred from class names — so a Tailwind config or
  breakpoint change that silently broke the intended sizes would be
  caught.

## Checks (3/3 PASS — full detail in `result.json`)

1. Mobile (360px): policy list item body text computes to exactly
   `16px` (not just `>= 14px` — the intended `text-base` value).
2. Mobile: the policy `<ul>`'s computed `list-style-type` is `disc`,
   not `none`.
3. Desktop (1024px, above the `sm` breakpoint): policy text computes
   to `14px` — confirms the `sm:text-sm` responsive override actually
   applies at that breakpoint, not just that the class is present in
   the source.

## Commands and observed results

```text
npx --yes --package @playwright/cli playwright-cli -s=consent-ux-review open about:blank
PASS — browser opened

npx --yes --package @playwright/cli playwright-cli -s=consent-ux-review run-code --filename tasks/evidence/consent-screen-uxreview-2026-09-21/run-browser.mjs
PASS — 3/3 checks, full result in result.json

npx --yes --package @playwright/cli playwright-cli -s=consent-ux-review close
PASS — session closed
```

## Evidence files

- `run-browser.mjs`: reproducible independent browser harness.
- `result.json`: structured outcome and raw computed-style measurements.
- `consent-screen-policy-list-360.png`: the full consent screen at
  360px, `granted` scenario.
- `checksums.sha256`: SHA-256 for the screenshot.
