# PR #91 UI/UX review — 360px header layout — 2026-09-21

Verdict: **PASS**, 4/4 checks. Evidence for Lead's `ui-ux-pro-max` (domain
`ux`, "Layout & Responsive") review item 5 on PR #91: the header must
show the timer, camera status badge, and (when present) the "Coba
aktifkan kamera lagi" retry button at 360px without horizontal scroll
or clipping.

## What this found

The layout was broken before the fix in this PR: the header's outer
flex row (`justify-between`, no `flex-wrap`) and the camera-status
group (`flex items-center gap-2`, no `flex-wrap`) both forced their
children onto one line, so the retry button was clipped off the right
edge at 360px instead of wrapping. Manual verification in the browser
caught this directly (screenshot showed "Coba aktifkan ka…" cut off
mid-word). Fixed by adding `flex-wrap` to both the outer header row and
the inner camera-status group in `session-runner-shell.tsx`.

## Scope and method

- Fixture: `tests/Frontend/CameraCapture/` (`canvas.captureStream()`
  provides a real `MediaStream` — no camera hardware needed), dev
  server on `127.0.0.1:8097`.
- Harness: `run-browser.mjs`, an independent Playwright CLI script
  (`@playwright/cli run-code`), not the desktop app's browser pane —
  chosen because the pane was unreliable for this check while hidden in
  this session (screenshots worked, but `find`/`get_page_text` returned
  stale text; a real headless Playwright run has no such dependency).
- Flow driven: activate camera → simulate track `ended` (interrupted)
  → simulate the participant returning via a `focus` event, scripted to
  fail (`reactivation_failed`, showing the retry button) → measure
  geometry via `getBoundingClientRect()` and take the screenshot.

## Checks (4/4 PASS — full detail in `result.json`)

1. No horizontal overflow at 360px (`document.documentElement.scrollWidth`
   and `document.body.scrollWidth` both equal the 360px viewport).
2. The retry button is fully within the viewport (`right <= 360`,
   `left >= 0`) — not clipped off the right edge.
3. The retry button meets the 44px touch-target minimum.
4. The retry button does not overlap the timer — it wrapped onto its
   own line below, not stacked on top of it.

## Commands and observed results

```text
npx --yes --package @playwright/cli playwright-cli -s=camera-shell-360 open about:blank
PASS — browser opened

npx --yes --package @playwright/cli playwright-cli -s=camera-shell-360 run-code --filename tasks/evidence/camera-shell-360px-2026-09-21/run-browser.mjs
PASS — 4/4 checks, full result in result.json

npx --yes --package @playwright/cli playwright-cli -s=camera-shell-360 close
PASS — session closed
```

## Evidence files

- `run-browser.mjs`: reproducible independent browser harness.
- `result.json`: structured outcome and raw geometry measurements.
- `session-runner-shell-header-reactivation-failed-360.png`: the header
  at 360px in the `reactivation_failed` state (worst case for wrapping
  — timer, badge, and retry button all present at once).
- `checksums.sha256`: SHA-256 for the screenshot.
