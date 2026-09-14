# Participant assessment wireframes v1

Status: **annotated text wireframes; artifact-only; no runtime or visual readiness claim**

Companion flow: `docs/ux/participant-assessment-flow-v1.md`

Token source: `resources/design-tokens/oncam.tokens.json`

All question and instruction copy is synthetic. Result and post-submit content is provisional and blocked until the result-read consumer/DTO contract is frozen.

## 1. Shared page anatomy

```text
+------------------------------------------------------------------+
| Skip to main content                                              |
| Product identity                     Session / connection status   |
+------------------------------------------------------------------+
| Main                                                              |
|  Journey heading                                                   |
|  Context / approved instructions                                   |
|                                                                    |
|  [Question or state panel]                    [Progress summary]    |
|                                                                    |
|  [Secondary action]                            [Primary action]     |
+------------------------------------------------------------------+
| Persistent status: timer | save state | support/help if approved  |
+------------------------------------------------------------------+
```

- DOM and reading order always follow the mobile single column: identity, main heading, context, timer/status, question, choices, actions, secondary progress/help.
- Visual side-by-side placement at 768/1280 does not change DOM order or keyboard order.
- The persistent status region stays in normal document flow or reserves an equal inset if sticky; it never obscures focused content at 200% zoom.
- Product identity follows the approved ONCAM logo rules. A compact header uses accessible product text rather than cropping the tall lockup.

## 2. Lobby and instructions

```text
320 / 390
+------------------------------+
| ONCAM / Ruang psikotes       |
| Participant identity         |
|------------------------------|
| Tes yang tersedia            |
| [Name]  [Status text + icon] |
| Why unavailable, if needed   |
| [Mulai / Lanjutkan]          |
+------------------------------+

768 / 1280
+--------------------------------------------------------------+
| Approved identity header                                      |
|--------------------------------------------------------------|
| Participant summary   | Assessment list                       |
|                       | [Name] [status text + icon] [action]   |
|                       | eligibility explanation               |
+--------------------------------------------------------------+
```

Annotations:

- Current `lobby.tsx` is a read-only reference, not a file to modify. The future action appears only after the start/read contract and authority gates close.
- A status chip uses `component.{mode}.status.*` and includes visible text. “Ready” is never green alone.
- Selecting an assessment opens I0 Resume check. The action becomes busy and unavailable to duplicate activation.
- Instructions use one `h1`, an approved instruction section under `h2`, and one primary start/resume action. No guessed duration, item count, subtest, or copyright-restricted content appears.

## 3. Active question shell

```text
320
+------------------------------+
| Assessment name              |
| Question placeholder 04/10   |
| Time: 12:34  Saved [icon]    |
|------------------------------|
| Synthetic prompt / legend    |
|                              |
| ( ) Placeholder option A     |
|                              |
| ( ) Placeholder option B     |
|------------------------------|
| [Previous if authorized]     |
| [Next — waits for receipt]   |
+------------------------------+

390
+------------------------------------+
| Assessment name       Time: 12:34  |
| Question placeholder 04/10         |
| Saved [icon + text]                |
|------------------------------------|
| Synthetic prompt / legend          |
| [ ( ) Placeholder option A       ] |
| [ ( ) Placeholder option B       ] |
|------------------------------------|
| [Previous]              [Next]     |
+------------------------------------+

768
+----------------------------------------------------------+
| Assessment name            Time: 12:34 | Saved [icon]     |
|----------------------------------------------------------|
| Question placeholder 04/10                                |
| Synthetic prompt / legend                                 |
| [ ( ) Placeholder A ]  [ ( ) Placeholder B ]             |
|----------------------------------------------------------|
| Progress/help in flow       [Previous] [Next]             |
+----------------------------------------------------------+

1280
+------------------------------------------------------------------+
| Assessment name                        Time: 12:34 | Saved [icon] |
|------------------------------------------------------------------|
| Main question column (readable measure) | Progress / session facts|
| Question placeholder 04/10              | Current position         |
| Synthetic prompt / legend               | Connection text + icon   |
| [ ( ) Placeholder option A ]            | Approved help only       |
| [ ( ) Placeholder option B ]            |                          |
| [Previous]                    [Next]     |                          |
+------------------------------------------------------------------+
```

Annotations:

- The question is a `fieldset`; synthetic prompt is the `legend`. Radio labels contain the complete visible option text. Source order equals display and keyboard order.
- Options use a single column at 320/390. At 768, two columns are allowed only when each full label remains readable and source order is unambiguous; otherwise retain one column. At 1280, the question column stays bounded by `semantic.shared.size.contentMeasure` rather than stretching.
- Timer uses tabular figures, is text-labeled, and never changes document title every second. Threshold announcements are sparse and approved separately.
- “Previous” appears only if authoritative navigation permits it. “Next” is unavailable while saving and becomes available after receipt or reconciliation.
- Active selection uses radio state plus shape/border/text, not color alone. Hover is supplementary; keyboard and touch do not require it.
- No gesture-only navigation, question carousel, drag interaction, or hidden future question is used.

## 4. Saving, offline, and conflict overlays-in-flow

These states do not replace the current question unless continuing would be unsafe.

```text
Saving
[spinner decorative] Menyimpan jawaban…
[Next unavailable; selected radio remains visible]

Offline / retrying
+------------------------------------------+
| Warning icon | Belum tersimpan           |
| Menunggu koneksi. Waktu tetap berjalan.  |
| [Coba lagi]                               |
+------------------------------------------+

Conflict (blocking)
+------------------------------------------+
| Error summary (focus target)             |
| Jawaban terbaru perlu dimuat.            |
| Data yang lebih baru tidak akan ditimpa. |
| [Muat jawaban terbaru]                   |
+------------------------------------------+
```

- Saving uses a polite atomic status. It does not move focus.
- Offline is persistent until resolved and uses warning icon, border, and text. A same-tab in-memory pending response may be retained; no durable browser storage is implied.
- Conflict blocks navigation and automatic posting. On refresh, keep focus on the error summary until the participant activates reconciliation; then focus the current item heading/legend and announce the reconciled selection.
- At 320/390, alerts are full-width above the question actions. At 768/1280, they remain in the main reading column; they do not move into a visually convenient sidebar if that would separate the error from its cause.

## 5. Deadline and closed

```text
+------------------------------------------+
| Closure icon | Waktu pengerjaan berakhir |
| Jawaban tidak dapat diubah.              |
| [Periksa status]   [Kembali ke lobby]    |
+------------------------------------------+
| Current question remains read-only       |
| Selected response, if known, is visible  |
+------------------------------------------+
```

- When the local display reaches zero, choices and write/navigation actions become unavailable immediately. One assertive atomic announcement states closure; ticking never uses an assertive region.
- The current selection remains visible but read-only so the transition is understandable. Server refresh determines whether the session is expired, submitted, scored, or void.
- Focus moves to the closure heading only once after server-confirmed transition. If a closure banner appears while a control is focused, preserve focus until the in-flight event completes unless the control has become invalid; then move focus predictably to the closure heading.
- At all widths, actions wrap vertically before labels truncate. No fixed footer covers content.

## 6. Submit and processing

```text
Submit confirmation
+------------------------------------------+
| Submit answers?                          |
| Answers become read-only after submit.   |
| [Continue reviewing] [Send answers]      |
+------------------------------------------+

Processing
+------------------------------------------+
| Sending answers…                         |
| Keep this page open while status checks. |
| [disabled: Send answers]                 |
+------------------------------------------+

Unknown outcome / recovery
+------------------------------------------+
| Submission status could not be confirmed.|
| Checking will not create a second attempt|
| [Check status]                           |
+------------------------------------------+
```

- Confirmation is a semantic dialog only if focus trapping, Escape/cancel, background inertness, and trigger-focus restoration are implemented. A dedicated confirmation page is equally valid and simpler at 320.
- “Send answers” is visually separated from “Continue reviewing.” The irreversible effect is stated before the action.
- Any unanswered count or completeness statement is omitted unless supplied by an authoritative frozen projection.
- Processing retains a textual label alongside any spinner, prevents duplicate activation, and performs idempotent recovery. It does not claim scoring stages or ETA.
- At 320/390, buttons stack with the safe/cancel action first in DOM order and primary irreversible action last. At 768/1280 they may share a row without changing DOM order.

## 7. Result pending — provisional and blocked

```text
+------------------------------------------+
| Received [status icon + text]            |
| Jawaban telah diterima.                  |
| Informasi hasil belum tersedia di alur   |
| ini.                                     |
| [Return to lobby]                        |
+------------------------------------------+
```

This is a holding-state wireframe only. It renders no score, band, dimension, recommendation, report URL, finalization state, release time, ETA, or staff-review detail. Its heading, fields, actions, endpoint, and navigation remain **provisional/blocked** until the result-read consumer/DTO contract and approved post-submit content are frozen.

## 8. Cross-width behavior

| Width | Layout | Actions and status | Text/reflow acceptance |
|---:|---|---|---|
| 320 | One column; compact text identity; no sidebar; full-width panels | Stack actions; timer/save state wrap onto separate lines; minimum target token retained | No page-level horizontal scroll; labels wrap; no clipped prompt/control at 200% zoom |
| 390 | One column with slightly larger gutter/rhythm | Two short actions may share a row only when target size and 8px separation remain; otherwise stack | 35–60 character readable line target; long tokens wrap safely |
| 768 | One main column; optional auxiliary block below or beside when reading order stays clear | Status may share header row; alerts stay adjacent to their cause | Orientation changes preserve content/action access; no nested scrolling |
| 1280 | Bounded main question column plus secondary session facts | Primary actions align at end of main column; persistent status stays visible without obscuring focus | Main prose remains at content-measure token; unused width becomes whitespace, not stretched text |

At browser zoom up to 200%, layouts reflow as if at a narrower viewport. At 400% zoom and a 1280 CSS-pixel viewport, essential content and controls remain available in one dimension without two-dimensional page scrolling, except a component with a documented essential exception (none is currently expected for PAPI forced choice).

## 9. Token annotations

- Canvas, surface, and text: `semantic.{mode}.color.surface.*`, `semantic.{mode}.color.text.*`.
- Layout rhythm and measure: `semantic.shared.space.*`, `semantic.shared.size.contentMeasure`, `semantic.shared.size.pageMaximum`.
- Question/state panels: `component.shared.card.*` and `component.{mode}.card.*`.
- Buttons: `component.shared.button.*` and the appropriate `component.{mode}.button.primary.*`, `component.{mode}.button.secondary.*`, or `component.{mode}.button.destructive.*` family.
- Radio/choice fields: `component.shared.input.*`, `component.{mode}.input.*`, and selected-state semantic aliases.
- Inline state: `component.shared.status.*` and `component.{mode}.status.*`.
- Blocking feedback: `component.shared.alert.*` and `component.{mode}.alert.*`.
- Motion: `semantic.shared.motion.duration`, `semantic.shared.motion.easing`, with `semantic.shared.motion.reducedDuration` under reduced motion.

These paths are references to the accepted source, not copied values or a second token definition.
