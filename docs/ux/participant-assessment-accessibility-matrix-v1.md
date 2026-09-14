# Participant assessment accessibility matrix v1

Status: **testable artifact acceptance; no runtime or visual readiness claim**

Applies to: lobby -> instructions/resume -> question/save/recovery -> close/submit -> provisional result pending

Token source: `resources/design-tokens/oncam.tokens.json`

Question and instruction fixtures must be synthetic. Result and post-submit behavior remains provisional/blocked pending the frozen result-read consumer/DTO contract.

## 1. Test conventions

- Test the four target widths at 100% zoom: 320, 390, 768, and 1280 CSS pixels.
- Test keyboard-only interaction in the browser, a screen reader/browser combination supported by the project, touch emulation plus at least one real coarse-pointer device when implementation exists, 200% zoom at every target width, and 400% zoom at 1280.
- Run light and dark token modes independently if both are shipped. A candidate token contrast claim is not runtime proof; inspect computed foreground/background/border/focus pairs.
- Simulate slow response, transport failure, offline/reconnect, stale revision, revision gap, mutation mismatch, invalid batch, deadline, session closed, and uncertain submit outcome.
- Inspect DOM and network fixtures for scoring keys, dimensions, answer keys, source paths, hidden questions, real instrument content, secrets, PII beyond the synthetic fixture, and unintended outbound requests.

## 2. Acceptance matrix

| ID | Area / states | Testable acceptance | Evidence expected |
|---|---|---|---|
| A01 | Landmarks / all | Exactly one `main`; a visible or focus-revealed skip link is first; repeated header/nav/status areas have accessible names where needed. | Accessibility-tree snapshot and keyboard recording at 320 and 1280. |
| A02 | Headings / all | One page `h1`; sections descend without skipped levels; state changes do not create duplicate hidden headings; current question has a stable heading/legend target. | DOM assertion for every rendered state. |
| A03 | Focus order / L1, I1, I0, Q0, U0, R0 | Tab order follows mobile DOM order: page identity -> context -> timer/status if interactive -> question choices -> navigation/actions -> auxiliary help. CSS rearrangement at 768/1280 does not alter meaning. | Keyboard trace at all four widths. |
| A04 | Route/state focus / L1->I1->I0->Q0 and L1/I0->Q0 resume, navigation Q0->Q0, D0, R0 | Pre-start instructions receive heading focus before start. After the sole start action or an authoritative resume/read, focus moves directly to the current item heading/legend; no timed interstitial receives focus. Routine save/resume announcements never steal focus. Server-confirmed closure moves focus to the closure heading only when the prior control is invalid. | Focus event log for new-start, replay/resume, deadline, and question-navigation paths. |
| A05 | Focus restoration / U0, conflict, mobile menu if present | Cancel/Escape from submit confirmation restores focus to its trigger. After conflict reconciliation, focus returns to the current item heading/legend. Closing any menu restores focus to its trigger. | Automated active-element assertions plus manual keyboard check. |
| A06 | Focus visible / all interactive | Every keyboard-operable control displays a non-obscured focus indicator using `component.{mode}.*.focusRing` or `semantic.{mode}.color.state.focusRing`; focused content is not hidden by sticky status/footer. | Screenshots in both modes and computed-style contrast inspection. |
| A07 | Radio semantics / Q0 | One `fieldset` with a descriptive `legend`; each native radio has a complete programmatic label; group source order is fixed; checked and disabled states are exposed. Tab enters the group; arrows change selection; Space selects according to native behavior. | Accessibility tree, keyboard test, and DOM assertions. |
| A08 | Choice feedback / Q0/S0/S1 | Selected state has native checked semantics plus visible shape/border/text; saving and saved are not encoded only by control color. A visual selection change is available within 100ms without implying receipt. | Interaction recording under throttled network. |
| A09 | Touch/pointer targets / all | Every control hit area is at least `semantic.shared.size.touchTarget`; adjacent targets have at least 8 CSS px separation; no action depends on hover, swipe, drag, or precision tapping. | Bounding-box assertions at 320/390 plus real/coarse-pointer check. |
| A10 | Save live region / S0/S1/O0 | One atomic polite status region announces meaningful transitions such as “Saving,” “Saved,” and “Not saved—waiting for connection.” It does not announce radio focus, every timer tick, or repeated identical retries. | Captured accessibility announcements under slow/offline tests. |
| A11 | Timer announcements / Q0/D0 | Visible timer has a persistent text label and tabular figures. Screen reader output is throttled to approved named thresholds; per-second DOM changes are not live. Closure is announced once using an assertive/alert mechanism. | Announcement log over a simulated threshold and deadline. |
| A12 | Offline semantics / O0/O1 | Offline status remains visible until resolved, says whether the answer/submit outcome is unknown, states that time continues, and provides a keyboard/touch retry or check-status action when safe. It never claims success early. | Offline/reconnect browser test and copy assertion. |
| A13 | Conflict recovery / C0 | A blocking conflict creates a focusable error summary with the cause and “Load latest answer” recovery. Newer server data is not overwritten. After refresh, the reconciled selection is programmatically and visibly exposed. | Stale/gap revision test, request log, focus assertion. |
| A14 | Errors / E0 | Error summary uses `role="alert"` only for immediate blocking errors, is focusable when multiple/actionable errors exist, links to an invalid field/group when applicable, and retains an inline group error via `aria-describedby`. Cause and recovery are both stated safely. | Accessibility tree and invalid-batch/error-code tests. |
| A15 | Closed controls / D0/D1 | Deadline/closed disables radios and all write/navigation actions with native semantics. Read-only selected value remains perceivable. No mouse, keyboard, touch, reconnect, or client-clock change can re-enable it without authoritative state. | Deadline race, reload, clock-change, and keyboard tests. |
| A16 | Submit confirmation / U0 | Confirmation describes the irreversible sealing effect before “Send answers.” If modal: labeled dialog, focus enters it, background is inert, Tab is contained, Escape cancels, and focus restores. If page: predictable Back/cancel restores context. | Keyboard/screen-reader trace at 320 and 1280. |
| A17 | Processing / P0/P1 | Submit control becomes unavailable with `aria-disabled`/`disabled` as appropriate, retains an accessible text label, exposes busy state, and prevents duplicate action. Unknown outcome offers “Check status” without asserting success/failure. | Double-activation and timeout tests; request count assertion. |
| A18 | Semantic status cues / all | Ready, saving, saved, warning, conflict, error, closed, and received each use at least two cues among explicit text, icon, border/shape, and position; color alone is never necessary. Decorative icons are hidden; meaningful standalone icons have a text alternative. | Grayscale screenshot review plus accessibility-tree assertion. |
| A19 | Text contrast / all | Computed normal text contrast is at least 4.5:1; large text at least 3:1. Disabled controls are not claimed as contrast passes but remain clearly disabled semantically and retain stable labels. | Automated contrast scan plus manual computed-pair record in each shipped mode/state. |
| A20 | Non-text contrast / all | Focus indicators, active control boundaries, meaningful icons, and selected/error boundaries meet at least 3:1 against adjacent colors. Gold is never used for normal text, primary action, or the sole warning cue. | Computed-style contrast record for default/focus/selected/error/closed. |
| A21 | Zoom/reflow / all | At 200% zoom no text/control clips, overlaps, disappears, or becomes unreachable at any target width. At 400% on 1280, content reflows to one dimension without page-level two-dimensional scrolling; labels wrap before truncation. | Screenshots and horizontal-overflow assertions for every major state. |
| A22 | Narrow widths / 320/390 | Question, complete choices, timer, save state, alert, and actions fit without page-level horizontal scroll. Actions stack before target sizes or labels shrink. Minimum body role remains `semantic.shared.font.body.md`. | Viewport screenshots and element bounding-box checks. |
| A23 | Wide widths / 768/1280 | Main text is bounded by `semantic.shared.size.contentMeasure`; auxiliary content does not break reading/focus order; visible whitespace is preferred to overlong lines. | Screenshot plus DOM-order comparison. |
| A24 | Reduced motion / all | With `prefers-reduced-motion: reduce`, nonessential animation uses `semantic.shared.motion.reducedDuration`; no meaning, focus, save correctness, or closure depends on animation end. Spinners retain text and may use a non-moving busy alternative. | Reduced-motion test for save, alert, navigation, and processing. |
| A25 | Motion/default / all | Any transition uses `semantic.shared.motion.duration` and `semantic.shared.motion.easing`, animates transform/opacity rather than layout, remains interruptible, and does not block input. | Computed animation audit and rapid-state-change test. |
| A26 | Status persistence / S0/O0/C0/D0 | Persistent status is adjacent to the affected workflow, does not auto-dismiss while actionable, and is not only a short-lived toast. Routine toast/status updates never steal focus. | Timed observation and focus log. |
| A27 | Accessible names / all | Icon-only controls have explicit action-oriented names; visible button labels and accessible names agree; logo/link naming follows the ONCAM logo alt decision tree; no filename or color is used as alt text. | Accessible-name computation snapshot. |
| A28 | Language/copy / all | Page language is declared; copy is concise, avoids unexplained technical codes, gives a next step, and does not reveal tenant, case, entitlement, definition, SQL, or exception detail. Synthetic fixtures are clearly non-production. | DOM copy review against stable error mapping. |
| A29 | Session privacy / Q0-S1 | Only the current participant-safe item is in the DOM. No future/hidden item, key, dimension, scoring weight, definition JSON, source path, JWT, mutation secret, or real instrument content appears in DOM attributes, logs, URLs, or accessible descriptions. | DOM/network/console snapshot and secret/PII scan. |
| A30 | Offline storage / O0 | Sensitive answers are not placed in `localStorage` or IndexedDB. Same-tab memory may retain only the bounded pending value needed for retry, and is cleared when authoritative state closes or reconciles. | Storage inspection before/after offline and close. |
| A31 | Mutation/revision recovery / S0/O0/C0 | An identical retry reuses the mutation ID; a changed payload never reuses it; only one request is in flight; one latest pending selection is coalesced; revision advances only after receipt; conflict refreshes instead of overwriting. | Deterministic network request/receipt trace. |
| A32 | Deadline authority / Q0-D1 | Reload, visibility change, reconnect, and local clock changes do not reset or pause the displayed server-derived deadline. At local zero, writes stop; a server response determines terminal state. | Browser test with controlled server time and clock changes. |
| A33 | Submit idempotency / P0/P1 | Repeated activation is blocked; uncertain transport outcome checks/retries the same submission intent; authoritative `submitted`/`scored` never reopens answers. | Request count/state trace under timeout and replay. |
| A34 | Result pending / R0 | The holding state contains receipt-oriented text only and no result field, score, interpretation, recommendation, ETA, report link, or staff-review claim. It is labeled provisional/blocked in design evidence until the result-read contract freezes. | DOM snapshot and contract review. |
| A35 | Instrument authority / I1/Q0 | No UI fallback invents instruction, duration, subtest, item count, prompt, option, version, seed, or asset. Missing participant-safe authority enters B0 and returns to lobby. | Negative fixture with absent/invalid definition projection. |
| A36 | External effects / all | Synthetic acceptance emits no payment, provider, notification, camera, analytics, or other unexpected outbound request. No proctor action or final report fetch is implied by this flow. | Network allowlist assertion. |
| A37 | Forced colors / all | With a forced-colors mode active, native radio state, boundaries, focus, buttons, alerts, disabled state, and status text remain perceivable; authored backgrounds or icons do not erase system focus/selection cues. | Forced-colors screenshots and keyboard trace for Q0, C0, D0, and U0. |
| A38 | Atomic start and replay / I1/I0/Q0 | Approved pre-start instructions are rendered before any start call. The one “Mulai” activation invokes start once; new and replayed `in_progress` success both render Q0 immediately with the server-derived timer. Replay/resume status is polite and non-blocking; no second confirmation/continue action appears while time runs. | Ordered request/focus trace proving instructions precede start, one start request per activation, and direct Q0 entry for new and replayed success. |

## 3. State coverage traceability

| Journey state | Primary accessibility acceptance IDs |
|---|---|
| Lobby loading/ready/unavailable | A01–A06, A18–A23, A27–A28, A37 |
| Pre-start instructions/opening/resume | A02–A06, A16, A18–A25, A27–A28, A35, A37–A38 |
| Active question | A02–A11, A18–A25, A27–A32, A35, A37–A38 |
| Saving/saved | A04, A06, A08, A10, A18, A24–A26, A29–A32, A37 |
| Offline/retrying | A04, A06, A10, A12, A18, A21–A26, A30–A32, A37 |
| Conflict | A04–A06, A13–A14, A18–A26, A31, A37 |
| Deadline/closed | A04, A06, A11, A15, A18–A26, A32, A37 |
| Submit/processing/recovery | A03–A06, A14, A16–A18, A21–A28, A33, A37 |
| Result pending (provisional/blocked) | A01–A06, A18–A24, A27–A29, A34, A37 |

## 4. Token traceability

| Acceptance concern | Token paths to inspect |
|---|---|
| Focus | `semantic.{mode}.color.state.focusRing`, `component.{mode}.input.focusRing`, `component.{mode}.button.*.focusRing`, `component.{mode}.alert.focusRing` |
| Touch/control size | `semantic.shared.size.touchTarget`, `component.shared.button.height`, `component.shared.input.height` |
| Text and surface contrast | `semantic.{mode}.color.text.*`, `semantic.{mode}.color.surface.*` |
| Selected/error/success boundaries | `semantic.{mode}.color.state.selected*`, `component.{mode}.input.error*`, `component.{mode}.input.success*` |
| Status and alerts | `component.shared.status.*`, `component.{mode}.status.*`, `component.shared.alert.*`, `component.{mode}.alert.*` |
| Readable text/reflow | `semantic.shared.font.*`, `semantic.shared.size.contentMeasure`, `semantic.shared.size.pageMaximum`, `semantic.shared.space.*` |
| Motion/reduced motion | `semantic.shared.motion.duration`, `semantic.shared.motion.easing`, `semantic.shared.motion.reducedDuration` |

The paths above point to the existing ONCAM source; they are not replacement values. Runtime acceptance requires resolved aliases, computed-state inspection, and test evidence.

## 5. Exit criteria and known blockers

Accessibility acceptance is not complete until every applicable A01–A38 row has passing runtime evidence across its named states and widths. This matrix does not approve a participant-safe pre-start instruction/item DTO, real instrument content, start activation, result read, or production release. PAPI and IST authority remain blocked; FE-1 remains blocked; release remains **NO-GO**.
