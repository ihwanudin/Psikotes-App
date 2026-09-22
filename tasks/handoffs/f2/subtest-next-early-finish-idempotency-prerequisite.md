# `SubtestNext` early-finish idempotency — blocking prerequisite for P10

Written 2026-09-22 during timed-segments stage 4 (PR #109), flagged by Lead as a **mandatory
blocking prerequisite**, not an FYI. If you are about to answer P10 (may a participant finish a
segment before its timer expires?) with "yes" and set `allow_early_finish: true` on any real
segment data, **read this first.**

## The gap

`POST /sessions/:id/subtest/next` (`app/Actions/AssessmentSessions/SubtestNext.php`,
`app/Domain/AssessmentSessions/TimedSegmentTransitionPolicy.php`) carries **no client-supplied
idempotency key** — unlike autosave, which uses `mutation_id` + a stored receipt to detect and
safely replay an exact-duplicate retry without re-applying it.

With `allow_early_finish: false` — the only configuration any real segment data uses today (P10
is still open) — this doesn't matter: two near-simultaneous calls are already idempotent by
construction, proven in `tests/Postgres/SubtestNextConcurrencyTest.php`. The first call advances
(or confirms), `lockForUpdate()` serializes the second behind it, and the second's own fresh
sweep+policy evaluation correctly *rejects* (`INVALID_SESSION_TRANSITION`) rather than repeating
the first call's effect — because the segment it now finds is either already started (nothing
left to "confirm") or mid-window with nothing it's allowed to do.

**Once any segment has `allow_early_finish: true`, this safety property breaks.** Two genuine,
indistinguishable "next" requests (a real double-click, a client retry after a dropped response,
etc.) racing on that segment can both legitimately mean "end this segment now" — and once
`lockForUpdate()` serializes them, the *second* one is no longer rejected: it finds a fresh
current segment (the one the first call just advanced into) and, if early-finish is allowed
there too, advances *again*. The participant loses a segment's worth of time/content they never
actually asked to skip.

## What must exist before `allow_early_finish: true` ships for real data

A client-supplied idempotency key for `subtest/next`, checked and stored the same shape autosave
already uses: a request-level identifier (e.g. a `mutation_id`), a receipt table or column
recording "this exact request already committed this exact transition," and a lookup at the top
of `SubtestNext::withinTransaction()` that returns the stored result instead of re-deciding when
the same key is seen twice. This needs its own design pass (endpoint request shape, storage,
whether it reuses `assessment_autosave_mutations`' pattern or needs its own table) — not
designed here, and not needed for `allow_early_finish: false`, which is everything currently
shipped.

## How to apply

- If P8/P10 answers come back and someone starts authoring real segment data (via
  `tools/extract/` + review, per CLAUDE.md) with `allow_early_finish: true` anywhere: stop and
  implement the idempotency key first. Do not ship real `allow_early_finish: true` data without
  it, even for a single segment.
- `allow_early_finish: false` (or the field simply absent, defaulting false) remains fully safe
  and idempotent as-is — no blocker for continuing to use timed segments generally.
