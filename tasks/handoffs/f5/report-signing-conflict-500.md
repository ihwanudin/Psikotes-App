# `ReportSigningService::sign()` unique-conflict safety net — known unresolved 500 on PostgreSQL

For whoever next touches `app/Security/RlsContextRunner.php` or revisits this. Written
2026-09-22 during the F5 report-signing takeover (PR #97), after Lead's review found the
original TOCTOU fix (`7afe332`) was incomplete.

## What's fixed and confirmed working

`ReportSigningService::sign()` now calls `->lockForUpdate()` on the `assessment_cases` row
before reading the latest signing snapshot. This is the **primary** fix, and it genuinely
closes the concurrent-signing race Lead described: two real transactions both trying to sign
the same case now serialize at that lock — the second one's own `latest` read only happens
after the first has committed, so it correctly computes the next version instead of racing.
Confirmed against real PostgreSQL (`tests/Postgres/ReportSigningConcurrencyTest::
test_sign_locks_the_case_row_for_update` — the query log actually contains `FOR UPDATE`;
SQLite's grammar compiles `lockForUpdate()` to an empty string, so this can only be proven on
Postgres).

## What's NOT fixed: the unique-constraint safety net still 500s on Postgres

The insert into `report_signing_snapshots` is also guarded by the `(assessment_case_id,
version)` unique constraint, as a **second, independent** safety net for if the lock is ever
bypassed (a bug elsewhere, not normal operation — a real concurrent second signer blocks at
the lock and never reaches this path at all). Lead asked for this to return a clean 409
`SIGNING_CONFLICT` instead of an unhandled 500.

Three things were tried, in order, each confirmed against real PostgreSQL via
`run-org-postgres.ps1` (not assumed):

1. **Catch the unique violation inside the closure, return normally.** Fails: a failed
   statement marks the *whole* Postgres transaction as aborted (`SQLSTATE 25P02`) until
   rolled back. Returning normally doesn't undo that, so the very next statement on the
   connection — including `RlsContextRunner::runAsService()`'s own `finally` block, which
   restores the previous RLS role via `applyDatabaseContext()` — fails too, and *that*
   exception is what actually surfaces.

2. **Catch outside the `runAsService(...)` call instead** (the pattern already used in
   `ProvisionSelectionParticipant::handle()`, and what Lead's review suggested). Still fails,
   for the same reason as (1): `runAsService()`'s `finally` block runs its cleanup query
   *before* the exception ever reaches this outer catch, so what actually reaches it is a
   *different* exception (`25P02`, about the `set_config()` cleanup call) — not the original
   `23505` unique violation. `isVersionConflict()` correctly doesn't recognize it (different
   message) and re-throws, so it's still an unhandled 500.
   
   `ProvisionSelectionParticipant`'s own version of this pattern works because it calls
   `$this->runner->run(...)` as its own outermost call — a genuinely fresh
   `connection->transaction()` each time, including in its `catch` block's retry. F5's
   `sign()` is different: in production it's *always* called from inside an admin RlsContext
   that `ApplyRlsContext` middleware already opened, so `runAsService()` takes the "elevate"
   branch (`RlsContextRunner.php:61-82`), which does **not** own a transaction of its own —
   it just switches `app.role` within whatever transaction the caller already has open.
   There's no equivalent "call `run()` again fresh" recovery available from inside `sign()`.

3. **Catch inside the closure, call `DB::rollBack()` before returning.** Avoids the exception
   (confirmed — no more cascading failure), but is *actively unsafe*: it desyncs Laravel's own
   transaction-nesting bookkeeping. `runAsService()`'s "elevate" branch doesn't own the
   transaction it's running in — the *outer* `run()` call does (in this test, `run(new
   RlsContext('psychologist'), ...)`; in production, `ApplyRlsContext`'s own `run()` call
   wrapping the whole request). That outer `connection->transaction()` call still thinks it
   owns whatever savepoint level is current when its own callback returns, and tries to commit
   it — but a manual `DB::rollBack()` from deep inside already popped that level out from under
   it. Confirmed empirically: the `assessment_cases` row created by an *earlier, separate,
   already-committed* transaction level (the test fixture, seeded before the `sign()` call
   even started) came back `null` afterward — this "recovery" discarded more than the failed
   insert, not less.

## Current state

`ReportSigningService::sign()` ships with approach (2) — catch outside `runAsService(...)`,
matching the established codebase pattern and Lead's suggestion. It's the *safest* of the
three (no data-corruption risk, unlike (3)), even though it doesn't fully close this specific
gap. On SQLite, which has no aborted-transaction poisoning, this catch works correctly and
the SQLite-side test (`ReportSigningPersistenceTest::
test_version_conflict_at_insert_returns_409_not_500`) gets a clean 409. On PostgreSQL, the
real production database, it still 500s — pinned deliberately (not silently) by
`tests/Postgres/ReportSigningConcurrencyTest::
test_version_conflict_on_postgres_currently_still_surfaces_as_an_exception`, which asserts
the *current* exception rather than the desired 409, specifically so it breaks loudly if this
is ever fixed (a reminder to update the test, not evidence it's already fine).

## What a real fix probably needs

This isn't fixable from within `ReportSigningService.php` alone — it needs
`RlsContextRunner::runAsService()`'s "elevate" branch to either:

- Not attempt its cleanup query (`applyDatabaseContext` restoring the previous role) when the
  connection is already in an aborted-transaction state, since that role gets reset anyway
  once the outer transaction that's aborting eventually rolls back; or
- Expose some way for a caller to know the transaction is unrecoverable-from-here, so it can
  choose to let the *outer* `run()` call's own rollback happen instead of trying to recover
  locally.

`RlsContextRunner` is shared infrastructure used well beyond F5 (Checkout, Billing, Assessment
sessions, etc.) — a change there needs someone who owns that surface, not a unilateral edit
from this lane. Given the row lock already makes the underlying race exceedingly unlikely in
real operation (it would take a genuine lock bypass, not normal concurrent signing, to reach
this path), closing this specific gap can reasonably wait for a coordinator decision on
priority rather than blocking this PR.
