# F2 — Timed segments: subtest/phase/column state machine plan (2026-09-22)

**Status: plan only, no code.** Covers IST's subtest/phase timing gap and
generalizes the mechanism so Kraepelin's column-by-column administration can
reuse it later. Lead sign-off on direction, 2026-09-22, with four revisions
folded in below.

## Why this is needed

Server-side, `GET /sessions/:id` carries exactly one `remaining_seconds`/
`ends_at` for the whole session (`AssessmentSessionResource`). IST is
administered as a sequence of independently-timed subtests (SE, WA, AN, GE,
RA, ZR today; ME with a mandated two-phase timer; FA/WU pending #73); nothing
server-side tracks which one is "current" or when it ends. Autosave
(`AssessmentAutosavePolicy`) only checks `item_no` against the whole
instrument's flat item count and the session's single deadline — a participant
could submit an answer for any item at any point in the session today. `POST
/sessions/:id/subtest/next` doesn't exist (route, controller, or action).

This is forward infrastructure, not an active exploit: IST cannot `start` at
all yet on `main` (no reader registered in `RegistryAssessmentItemContentAuthority`;
even on the unmerged reader branch, `ist_items.json`'s top-level `status` stays
`"draft"` while ME is draft). But `SessionDefinition` already carries a
`subtests[]` array with `duration_seconds`/`item_count` per subtest, and its
sum is already required to equal `total_duration_seconds` — the data shape
this design extends already exists; only the runtime state and gating do not.

Kraepelin has the identical unmet need from the opposite direction: 50
columns × 15 seconds, auto-advance on timeout, is already spec'd in CLAUDE.md,
but `POST /sessions/:id/events` (the batch per-column answer endpoint) isn't
built either, and nothing tracks "current column" server-side. Per Lead's
explicit instruction, **this plan generalizes the mechanism as "timed
segments"** rather than an IST-only concept, so Kraepelin's eventual `/events`
work can sit on the same foundation instead of inventing a second, divergent
one. Implementation for Kraepelin is explicitly out of scope here — only the
mapping is described (see "Kraepelin mapping" below).

## Core concept: a timed segment

A **timed segment** is the smallest server-tracked timed unit within a
session. Every instrument's timing decomposes into an ordered list of
segments:

- **IST**: one segment per subtest, except ME, which is two segments
  (`memorize`, `answer`) that share the `ME` subtest identity for scoring and
  item-delivery purposes.
- **Kraepelin**: one segment per column (50 total), each a fixed 15 seconds,
  no reading gap, no early finish (see mapping below).
- **PAPI/RMIB/DASS21**: today, one segment per instrument (the existing
  single-window behavior) — nothing changes for them; they simply have a
  `subtests`/segment list of length 1 that reproduces current behavior
  exactly.

Each segment carries, as **data** (not code constants):

```
{
  code: string,               // e.g. "SE", "ME_MEMORIZE", "col_07"
  duration_seconds: int,      // the timed window once the segment starts
  reading_cap_seconds: int,   // default 0 -- see "the reading gap" below
  allow_early_finish: bool,   // default false -- see "finishing early" below
}
```

`reading_cap_seconds` and `allow_early_finish` are optional per-segment data,
defaulting to `0`/`false`. When every segment in an instrument has
`reading_cap_seconds = 0`, the whole mechanism degenerates exactly to "the
segment starts the instant it becomes current" — i.e. today's single-window
session behavior, reproduced as the zero case of this design, not a special
case requiring separate code.

## Revision 1 (Lead): the reading gap and its effect on session `ends_at`

If P8 resolves to "instruction-reading time is outside the subtest's own
timer" (the model this design calls out), that reading period still needs an
upper bound — SPEC's phrasing for this kind of gate is generally "paling lama
____ menit," so the cap is a real, psychologist-set number per segment, read
from data (`reading_cap_seconds`), never a hardcoded constant.

This directly changes what the session-level deadline has to cover. Today,
`ends_at = started_at + total_duration_seconds`, and that sum is enforced by
both the `SessionDefinition` validator and a Postgres trigger/CHECK
constraint. If reading gaps exist and aren't counted, the session could expire
mid-segment through no fault of the participant's pacing. So:

```
session.ends_at = session.started_at
    + sum(segment.duration_seconds for every segment)
    + sum(segment.reading_cap_seconds for every segment)
```

This formula is unconditional — it does not branch on which P8 answer is in
effect. When every `reading_cap_seconds` is `0` (today's behavior, and P8
answer "reading counts toward the timer"), the second sum is `0` and
`ends_at` is exactly what it is today. When some segments carry a real
`reading_cap_seconds`, the ceiling widens by exactly that much, and no more.

**If the participant never confirms readiness within the cap, the segment
starts automatically once the cap elapses** — this is not a separate rule,
it falls out of the sweep mechanism below (revision 2) applied uniformly:
`reading_cap_seconds = 0` means the segment's own deadline for "must have
started" is the instant it becomes current; `reading_cap_seconds > 0` means
that deadline is `became_current_at + reading_cap_seconds`. Either way, the
sweep enforces it the same way.

This unifies what earlier drafts of this plan treated as two different
"trigger policies" (auto-start vs. participant-triggered) into one
data-driven number. There is no swappable policy class needed — just this one
field, defaulting to `0`.

## Revision 2 (Lead): the lazy sweep must never grant extra time

Server state per session (new, nullable columns on `test_sessions`, exact
migration deferred — see "Explicitly not designed here"):

- `current_segment_index` (smallint) — position in the flattened segment list
  for this session's instrument.
- `current_segment_became_current_at` (timestamptz) — when this segment
  became the active one (server-computed, always set, never null once
  `in_progress`).
- `current_segment_started_at` (timestamptz, nullable) — when the TIMED
  window actually began; null while waiting out a reading gap.

`current_segment_ends_at` is never stored — always derived:
`current_segment_started_at + segment.duration_seconds` once started; while
waiting on a reading gap, the relevant boundary is
`current_segment_became_current_at + segment.reading_cap_seconds`.

**The sweep** (evaluated lazily, on the next `GET /sessions/:id` or the next
autosave — the same "evaluate expiry on read" pattern
`AssessmentSessionDeadlinePolicy` already uses for the whole-session deadline,
not a new mechanism) walks forward through **scheduled boundaries**, never
`now()` at evaluation time:

```
loop:
  deadline = current_segment.started_at !== null
      ? current_segment.started_at + current_segment.duration_seconds   // timed window elapsed
      : current_segment_became_current_at + current_segment.reading_cap_seconds  // reading cap elapsed

  if now() < deadline: stop (this is genuinely still the current segment)

  // advance -- using the SCHEDULED deadline as the new anchor, not now()
  next_became_current_at = deadline
  if next_segment.reading_cap_seconds == 0:
      next_started_at = next_became_current_at   // starts immediately, no gap
  else:
      next_started_at = null                     // waits for confirm-or-cap

  current_segment_index += 1
  current_segment_became_current_at = next_became_current_at
  current_segment_started_at = next_started_at

  if no next_segment exists: session transitions to Expired (existing whole-
      session expiry path); stop
```

Because every anchor in this loop is a previously-scheduled boundary and never
the wall-clock time the loop happens to run at, a participant who goes offline
for five minutes gets caught up to wherever the schedule says they should be —
never a fresh full segment window. This must be covered by a test that
reproduces exactly this: start a session, let two segments' worth of
scheduled time pass without any request, then make one request and assert the
resulting `current_segment_index`/`started_at`/`ends_at` reflect the
scheduled boundaries, not `now() - duration`.

## Revision 3 (Lead): finishing a segment early is a separate, data-driven question

Lead is taking this to the psychologist as **P10**: may a participant advance
past a segment before its timer expires? The design accommodates either
answer without rearchitecting, via the `allow_early_finish` field per
segment (default `false`).

`POST /sessions/:id/subtest/next` (naming discussion below) has two distinct
meanings depending on current state, and only one of them is gated:

- **`current_segment_started_at IS NULL`** (still inside a reading gap):
  calling it sets `started_at = now()` — the participant confirms they've
  read the instructions and the timed window starts. This is **always
  permitted** regardless of `allow_early_finish` — it only shortens the
  *untimed* reading buffer, never graded/timed time, so there's nothing to
  gate.
- **`current_segment_started_at IS NOT NULL`** (currently inside the timed
  window): calling it means "end this segment now, before its timer
  expires." If `allow_early_finish` is `true` for the current segment,
  it transitions immediately, using **real `now()`** as the next segment's
  `became_current_at` (this is a genuine early transition, not a sweep
  catching up — no time is being granted, time is being saved). If
  `allow_early_finish` is `false`, the request is rejected with
  `422 INVALID_SESSION_TRANSITION` and no state changes.

`subtest/next` stays idempotent and monotonic as originally planned: calling
it again on an already-current, already-started segment before its deadline
and with `allow_early_finish=false` is exactly the rejection case above (not
a silent no-op) — there is no ambiguous "no-op vs. reject" case once the flag
is read from data.

## Revision 4 (Lead): generalize as timed segments, not IST-only

Everything above is written in terms of "segment," not "subtest," precisely
so Kraepelin (and anything else with more than one timed sub-unit later) can
reuse it. Concretely, the reusable pieces are:

- The segment data shape (`code`, `duration_seconds`, `reading_cap_seconds`,
  `allow_early_finish`) as an addition to `SessionDefinition` — see "Data
  model" below.
- The four-column session state (`current_segment_index`,
  `current_segment_became_current_at`, `current_segment_started_at`, plus the
  derived `ends_at`).
- The lazy sweep algorithm (revision 2).
- The two-meaning `.../next` semantics (revision 3).

### Kraepelin mapping (description only — not implemented here)

Kraepelin's 50 columns map to 50 segments, one per column:
`code: "col_01".."col_50"`, `duration_seconds: 15` (CLAUDE.md's fixed
per-column timing), `reading_cap_seconds: 0` (no separate reading gap between
columns — CLAUDE.md's "pindah kolom otomatis saat waktu habis" describes pure
timed drill, not a confirm-to-start gap), `allow_early_finish: false` (nothing
in the existing Kraepelin administration rules describes a participant-
triggered early column advance; advancing is always time-driven).

Under this mapping, the sweep algorithm in revision 2 already produces exactly
CLAUDE.md's required behavior ("pindah kolom otomatis saat waktu habis") with
zero Kraepelin-specific code — `reading_cap_seconds=0` means every column
starts the instant it becomes current, and the sweep advances columns purely
on scheduled 15-second boundaries. `POST /sessions/:id/events`, when it's
eventually built, would consult `current_segment_index` (mapped back to a
column number) the same way IST's autosave will consult it to reject writes
for closed segments — i.e. an events batch containing rows for a column other
than the current one (or a past one within some grace tolerance, a detail for
that endpoint's own design) is rejected on the same principle as IST's
out-of-range `item_no` rejection.

## Response shape and consuming-endpoint changes (additive)

`GET /sessions/:id` gains (existing fields unchanged):

```
"current_segment": {
  "code": "SE", "index": 0,
  "started_at": null | rfc3339, "ends_at": null | rfc3339,
  "remaining_seconds": null | int
}
```

`null` `started_at`/`ends_at`/`remaining_seconds` together mean "waiting on
the reading gap" — a real, observable client state, not an error.

`GET /sessions/:id/items` becomes segment-aware for any subtest with more
than one segment (today, only ME): which sub-slice of that subtest's item
payload is returned depends on `current_segment`, following the same
per-reader field-whitelisting pattern each instrument reader already owns
(confirmed in `GetAssessmentSessionItemsController`'s own doc comment — this
controller does no field-shaping itself). For ME specifically: the word list
is only present in the response while `current_segment.code == "ME_MEMORIZE"`
(name illustrative); the recall/answer items only while
`current_segment.code == "ME_ANSWER"`.

`AutosaveAssessmentAnswersController`/`AssessmentAutosavePolicy`: `maxItemNo`
today is a flat sum across the whole instrument
(`AutosaveAssessmentAnswers::maxItemNo()`). This becomes a **cumulative range
per subtest**, computed once from `SessionDefinition::subtests` (e.g. SE:
1-20, WA: 21-40, ...). An answer for an `item_no` outside the current
subtest's range is rejected — both a past subtest (already closed) and a
future one (not yet reached) — as a new, explicit error (naming deferred to
implementation; likely alongside the existing `SESSION_CLOSED`/
`DEADLINE_EXCEEDED` family).

## Data model changes (described, not built)

`SessionDefinition`'s per-subtest shape gains two optional fields
(`reading_cap_seconds: int = 0`, `allow_early_finish: bool = false`), and,
only for subtests with more than one segment, a `phases`-equivalent list —
naming to settle at implementation time, described here as `segments: list
<{code, duration_seconds, reading_cap_seconds, allow_early_finish}>` — whose
`duration_seconds` sum must still equal the parent subtest's own
`duration_seconds` (mirrors the existing subtest-sum-equals-total invariant
one level down). Every field flows through the existing checksum
(`SessionDefinition::checksumFor()` canonicalizes the whole payload already;
no special-casing needed for new fields).

`test_sessions` gains the three nullable columns described in revision 2.
Both the Postgres trigger (`guard_test_sessions_identity_revision`) and the
`test_sessions_lifecycle_check`/`test_sessions_contract_check` CHECK
constraints currently encode single-window invariants directly in SQL and
will need a parallel extension to stay consistent with segment state — this
is real schema/security work, reviewed separately at implementation time
(Lead's explicit agreement), not designed here.

## Explicitly not designed here

- The exact Postgres trigger/CHECK extension for the new columns.
- `POST /sessions/:id/events`'s own request/response shape for Kraepelin
  (only the segment-state mapping it would consult is described).
- The exact error code for autosave's out-of-range-subtest rejection.
- Whether the HTTP route stays `POST /sessions/:id/subtest/next` (IST-shaped
  naming, already referenced in `API_CONTRACT.md` and an earlier contract
  sketch) or is renamed to something instrument-neutral to match the
  underlying "timed segment" concept — a naming call for whoever implements
  this, not a structural one.
- FA/WU's own `/items` shape (blocked on PR #73 merging) — this design makes
  no assumption about it beyond "some subtests deliver `asset_id` references
  instead of inline item text," already true today independent of segments.

## Open psychometric questions, forwarded, not answered here

- **P8** (still open): does instruction-reading time count toward a
  segment's own timer? Answered by `reading_cap_seconds` being `0`
  (counts-toward) or a real per-segment value (excluded, capped separately)
  — the plan accommodates either answer as data, not a rearchitecture.
- **P10** (new, this plan): may a participant finish a segment before its
  timer expires? Answered by `allow_early_finish` per segment.
