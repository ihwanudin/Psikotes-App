# F2 vertical instrument UI readiness

Status: read-only implementation-readiness report

Assigned audit baseline: `a522f47`

Observed main HEAD while reporting: `84f5ed3`
Scope: participant UI for generic IST, PAPI, RMIB, and Kraepelin sessions. DASS-21 remains outside this boundary.

## Decision

The first thin vertical instrument slice should be **PAPI session start/resume -> render one fixed-order forced-choice item -> idempotent autosave -> server-timer close**. It must stop before final submit, scoring, proctor capture, or production activation.

PAPI is the lowest-complexity generic instrument UI: it has 90 fixed-order forced-choice items and no approved need for figural assets, accessible drag-ranking, a dual timer, or a 15-second column grid. This does not make it implementation-ready today. The slice remains blocked until the authority and HTTP gates below are closed.

Do not start with:

- IST: nine locked subtests, missing approved ME split, two-timer behavior, and unresolved FA/WU participant assets.
- RMIB: accessible unique ranking 1-12 needs a keyboard-equivalent reorder interaction and authoritative duration/items.
- Kraepelin: 50 auto-advancing columns, 15-second server cadence, seeded generation, batched event ingest, poor-connection recovery, and load evidence make it a specialized slice.
- DASS-21: `SPEC.md`, ADR-0030, ADR-0031, and the F2 session contract require its lifecycle, responses, and retention to stay separate from generic sessions.

## Evidence and current state

- `resources/js/pages/participant/lobby.tsx` is the only participant page. It reads `/api/me` and `/api/me/entitlements` and intentionally exposes no start control.
- `tests/Frontend/ParticipantLobby/**` verifies loading/ready/error states, missing identity fields, 320/390/1280 layouts, no write requests, and no controls. There is no assessment/instrument UI or browser harness to extend without adding new files.
- `PRD_Sistem_Psikotes_CPMI.docx` requires a server-authoritative timer (FR-05), a 50-column/15-second Kraepelin grid with buffering/offline queue (FR-06), unique RMIB ranking 1-12 (FR-07), locked sequential IST subtests including ME dual timing and FA/WU assets (FR-08), and fixed item/option order for IST/PAPI/RMIB with seeded generation only for Kraepelin (FR-08b). These are instrument-specific acceptance constraints, not interchangeable UI decoration.
- `routes/api.php` exposes only `POST /api/sessions/{testType}/start` for the generic assessment flow. No participant-safe session read, answers, events, subtest-next, or submit HTTP route is present.
- `StartParticipantSessionController` still returns `SESSION_ENGINE_PENDING` and still uses the pre-ADR-0030 RLS-controller shape. The accepted ADR requires a sealed service-transaction command, no generic request RLS wrapper on this route, no controller database access, and rejection of DASS.
- `AutosaveAssessmentAnswers` and `SubmitAssessmentSession` actions exist and have focused Feature/PostgreSQL coverage, but they are not participant HTTP boundaries yet.
- The current start result carries lifecycle/timer/revision fields but no participant-safe item projection. Existing seeder JSON is scoring/configuration material and can expose answer keys or dimension mappings; it must never be serialized directly to the browser.
- The definition-authority recovery audit in `tasks/parallel-work.md` remains at 0/4 import-ready. Every generic instrument lacks an approved version/provenance manifest; PAPI and RMIB durations are unapproved; IST ME split and assets remain unresolved; Kraepelin source authority conflicts remain unresolved.

## Hard gates before implementation

### 1. Approved authority manifest

The psychologist-approved PAPI entry must freeze, at minimum:

- instrument code `papi`, definition version, non-local provenance reference, and checksum;
- total duration, subtest code/count, and exactly 90 items;
- fixed item and option order (`randomization = fixed`, no seed);
- approved participant-visible prompt and option text/identifiers;
- an explicit separation between participant projection and scoring/dimension keys.

No active catalog row, fallback duration, locally invented text, or UI hardcode is acceptable before this entry exists. ADR-0030's definition authority must resolve this exact active version inside the start transaction.

### 2. ADR-0030 runtime boundary

The coordinator/backend lane must replace the 501 placeholder with the accepted sealed command and demonstrate:

- participant identity and exact case-bound ready entitlement are resolved server-side;
- one service transaction owns definition resolution plus allocation/replay;
- retry returns the same active attempt and does not reset `started_at` or `ends_at`;
- DASS is rejected by the generic command and route;
- ambiguous/missing multi-case authority fails closed per ADR-0031.

### 3. Frozen participant-safe HTTP contract

Before UI code begins, the coordinator must freeze and test the start/resume/read and autosave DTOs. The UI needs these semantics; field spelling belongs to the shared contract owner:

| Need | Required semantics |
|---|---|
| Session identity | opaque session ID, `instrument=papi`, attempt number, status |
| Server time | server timestamp, `started_at`, `ends_at`, write deadline, remaining seconds |
| Concurrency | current `answers_revision`; exact next-revision and mutation replay rules |
| Definition identity | public version/checksum and fixed-order declaration; never an internal file path |
| Current item | item number, display position/total, approved prompt, fixed ordered options, selected value |
| Navigation | authoritative current/next item and whether the answer is writable |
| Errors | stable unauthenticated/forbidden/not-found/conflict/closed/deadline codes |

The answer write remains `POST /sessions/:id/answers` with one opaque `mutation_id`, `revision = current_revision + 1`, and `items: [{item_no, value}]`. The same mutation ID is reused only for an identical retry. A revision conflict must refresh authoritative state; it must not silently overwrite a newer answer.

The DTO must not include answer keys, PAPI dimensions, scoring weights, source workbook paths, DASS fields, or unrestricted definition JSON.

## Exclusive first-slice ownership

Assign one UI worker only these new files:

1. `resources/js/pages/participant/assessment/papi.tsx`
2. `resources/js/components/participant-assessment/session-shell.tsx`
3. `resources/js/components/participant-assessment/papi-question.tsx`
4. `resources/js/components/participant-assessment/session-client.ts`
5. `tests/Frontend/ParticipantAssessment/papi.browser.mjs`

No existing lobby file, route, controller, API contract, migration, catalog/manifest, lockfile, shared UI primitive, or other instrument file is part of this ownership. A coordinator-owned synthetic route/fixture must make the page reachable for the browser test; the UI worker must not add a production route or broaden its file set.

The slice should have four bounded states in `session-shell.tsx`: loading/resuming, active, recoverable connection/conflict, and closed. `papi-question.tsx` renders exactly one current item. `session-client.ts` is limited to typed start/read/autosave calls and a single in-flight plus one coalesced pending mutation. It must not contain scoring, submit, proctor capture, or instrument authority.

## UI behavior to freeze

### Timer

- Derive display time from the server timestamp/deadline and a measured local offset; never restart or pause from component lifecycle, refresh, visibility change, or connectivity loss.
- Resynchronize on every authoritative response and resume/read.
- At zero or server `closed/deadline` response, immediately disable choices, cancel new writes, announce closure, and refresh authoritative state.
- Never treat the client countdown as permission to accept a late answer.

### Autosave and poor connection

- Save after selection with one in-flight request and one coalesced latest pending value; do not emit parallel writes.
- Preserve the same mutation ID for byte-equivalent retry and allocate a new ID/revision only after an accepted receipt.
- Show `saving`, `saved`, `offline/retrying`, `conflict`, and `closed` states without claiming success early.
- The first thin slice may keep a bounded same-tab in-memory retry only. Durable offline storage of sensitive answers needs a separate security/retention decision before IndexedDB or local storage is used.
- Navigation cannot advance until the current answer has an accepted receipt or an authoritative resume proves it already exists.

### Accessibility and mobile

- Render the forced choice as a semantic `fieldset`/`legend` radio group with programmatic labels and preserved source order.
- Support Tab plus native arrow/Space selection; move focus to the item heading after authoritative navigation and to an error summary after a blocking failure.
- Provide at least 44x44 CSS-pixel targets, visible focus, readable contrast, and no state conveyed by color alone.
- Use a polite live region for save-state changes and timer thresholds only; do not announce every timer tick.
- At 320, 390, 768, and 1280 CSS pixels, keep the question, choices, timer, save state, and watermark readable without horizontal overflow or obscured controls.
- Respect reduced motion. Do not shuffle choices, render multiple hidden questions, enable copy/paste affordances, or put answer/scoring data in DOM attributes.

## Synthetic browser acceptance

The new browser test must use synthetic identity/session/items and intercept or use a test-only local server. It must make no payment, provider, notification, camera, or other outbound request.

Required checks:

1. Start and reload/resume return the same session/attempt and the countdown does not reset.
2. Exactly one fixed-order item and its options are present; no DASS field or scoring key/dimension appears in DOM or network payloads.
3. Keyboard-only selection works, focus is visible, and save-state announcements are not emitted every second.
4. One selection produces the exact next revision and mutation payload; an identical retry reuses the mutation ID and produces no duplicate navigation.
5. A newer authoritative revision causes refresh/reconciliation, not last-write-wins overwrite.
6. Simulated offline then online coalesces to the latest pending selection and never sends concurrent revisions.
7. Reload, visibility change, and local clock changes do not extend the server deadline; deadline closure disables choices and blocks further writes.
8. 320/390/768/1280 viewports have no horizontal overflow, clipped choice, or obscured timer/save state.
9. No unexpected console error, unhandled rejection, external request, final submit, scoring call, or proctor action occurs.

Run only frontend/static/synthetic gates for this lane: TypeScript build/typecheck, scoped lint, the new PAPI browser test, and existing ParticipantLobby regression. PostgreSQL and real `.env` data are outside this slice.

## Dependency handoff and next order

1. Psychologist/authority owner approves the four-entry manifest; PAPI is the first required entry.
2. Backend/coordinator accepts ADR-0030 runtime start plus participant-safe session read/item projection and autosave HTTP boundary.
3. Coordinator freezes the additive DTO/error contract and supplies a synthetic test route/fixture.
4. UI worker implements the five-file PAPI slice above behind inactive/test-only reachability.
5. Coordinator reviews source/network leakage, browser evidence, and existing lobby regression before assigning submit/proctoring or another instrument.

After PAPI, implement IST only when ME split/assets/two-timer authority is approved, RMIB only when duration/items and accessible ranking semantics are frozen, and Kraepelin last after seeded generator/event-ingest/load/offline contracts pass. DASS remains a separate lane throughout.

## Acceptance conclusion

The PAPI slice is **recommended but currently blocked**. UI implementation should not begin from the current main history because the approved manifest, ADR-0030 runtime start, participant-safe item/read projection, and autosave HTTP controller are not yet accepted. The existing lobby and its browser evidence should remain unchanged until those gates close.
