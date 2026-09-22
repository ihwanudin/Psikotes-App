# Photo-capture scheduler wired to the live camera — 2026-09-22

Verdict: **PASS**, 10/10 checks. Evidence that the pure periodic
photo-capture scheduler (PR #87, `photo-capture-scheduler.ts`) is now
genuinely connected to the live camera's `captureFrame()`/reactivation
(PR #91) through a new `use-periodic-photo-capture.ts` hook and
`SessionRunnerShell`'s new `photoCapture` prop — per Lead's request
(2026-09-22): the scheduler must run only while `camera.status ===
'active'`, stop the moment the camera goes away, and call `captureFrame`
with the caller-injected size/quality — never an embedded constant.

## What this proves

- **Nothing happens on mount alone**: before the camera is activated,
  the capture log is empty and the scheduler status reads `BERHENTI`
  (check 1–2) — the same "constructing something never acts on its own"
  guarantee every other proctoring piece in this codebase makes.
- **The scheduler actually runs while the camera is active** (check
  3–4): at least 2 periodic captures land within ~4.5s at the fixture's
  1.5–2s interval, and the on-screen status flips to `AKTIF`.
- **Captures use the caller-injected parameters, not a hardcoded
  value** (check 5): the fixture supplies a deliberately distinctive
  `maxWidth: 321, maxHeight: 241, jpegQuality: 0.42` (nothing like the
  manual capture buttons' `480x360, 0.6`). Every periodic capture in the
  log fits that box at `321x181` (`computeContainFitSize` shrinking the
  fixture's 800x450 source), proving the numbers really did travel from
  the prop the hook was given, through the scheduler, into
  `captureFrame()` — not a default. Check 6 additionally proves the
  manual-capture path still produces its own, different size (`480x270`)
  from the same page at the same time, so the two aren't secretly
  sharing one constant.
- **The scheduler genuinely stops, not just "stops capturing
  successfully"**: interrupting the camera (`ended` event) flips status
  to `BERHENTI` (check 7) and — the stronger assertion — no new capture
  lands even after waiting past a full interval (check 8: count stays at
  2 through a 3.5s wait). A failed reactivation attempt (this fixture's
  scripted `getUserMedia` fails on its 2nd call) lands on
  `reactivation_failed`, and the scheduler stays off through that status
  too (check 9) — not just `interrupted`.
- **The scheduler resumes on its own once the camera does** (check 10):
  a second reactivation attempt succeeds (the fixture's 3rd scripted
  call), status returns to `active`, and periodic captures resume
  without any extra wiring — `use-periodic-photo-capture.ts` tracks
  `camera.status` exactly, with no independent "should I be running"
  flag of its own.

## Scope and method

- Fixture: `tests/Frontend/CameraCapture/` (already exists from PR #91;
  extended here with a `photoCapture` config wired into
  `SessionRunnerShell`, a capture-count/status display, and a manual
  "Trigger capture sekarang (session\_submit)" button exercising the
  `captureNow()` extension point). Dev server on `127.0.0.1:8099`.
- Harness: `run-browser.mjs`, an independent Playwright CLI script
  (`@playwright/cli run-code`), not the interactive browser pane.
- A real `MediaStream` from `canvas.captureStream()` — no camera
  hardware needed, same technique PR #91's own evidence used.
- Real time waits (not mocked timers) — the fixture's interval is a real
  1.5–2 real seconds specifically so this harness can observe several
  genuine ticks in a few seconds; this is fixture-only tuning for fast
  verification, never a value baked into application code (see
  `use-periodic-photo-capture.ts`'s module doc — CLAUDE.md forbids
  embedding configurable thresholds in code).

## Commands and observed results

```text
npx --yes --package @playwright/cli playwright-cli -s=photo-capture-wiring open about:blank
PASS — browser opened

npx --yes --package @playwright/cli playwright-cli -s=photo-capture-wiring run-code --filename tasks/evidence/photo-capture-scheduler-wiring-2026-09-22/run-browser.mjs
PASS — 10/10 checks, full result in result.json

npx --yes --package @playwright/cli playwright-cli -s=photo-capture-wiring close
PASS — session closed
```

## Evidence files

- `run-browser.mjs`: reproducible independent browser harness.
- `result.json`: structured outcome, including every periodic capture's
  logged size/type/timestamp.
- `photo-capture-scheduler-wiring.png`: full-page screenshot at the end
  of the run (camera active again after reactivation, capture log and
  scheduler status visible).
- `checksums.sha256`: SHA-256 for the screenshot.
