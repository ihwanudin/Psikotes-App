# `ReportSigningService::sign()` unique-conflict safety net — resolved, but a note for other lanes

Written 2026-09-22 during the F5 report-signing takeover (PR #97), after Lead's review found
the original TOCTOU fix (`7afe332`) was incomplete. **Resolved** — kept as a record of the
underlying `RlsContextRunner` trap, since the same shape of bug (throwing from inside
`runAsService()`'s "elevate" branch on PostgreSQL) can bite any other lane that tries to
catch-and-recover from a `QueryException` the same way.

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

The `(assessment_case_id, version)` unique constraint on `report_signing_snapshots` is kept as
a **second, independent** safety net for if the lock is ever bypassed (a bug elsewhere, not
normal operation — a real concurrent second signer blocks at the lock and never reaches this
path at all). It now correctly returns a clean **409 `SIGNING_CONFLICT`** on both SQLite and
real PostgreSQL — see "The actual fix" below.

## Why this needed three failed attempts first

Three approaches were tried, in order, each confirmed against real PostgreSQL via
`run-org-postgres.ps1` (not assumed) — all of them variations on "catch the QueryException from
the failed insert and recover":

1. **Catch inside the closure, return normally.** Fails: a failed statement marks the *whole*
   Postgres transaction as aborted (`SQLSTATE 25P02`) until rolled back. Returning normally
   doesn't undo that, so the very next statement on the connection — including
   `RlsContextRunner::runAsService()`'s own `finally` block, which restores the previous RLS
   role via `applyDatabaseContext()` — fails too, and *that* exception is what actually
   surfaces.

2. **Catch outside the `runAsService(...)` call instead** (the pattern already used in
   `ProvisionSelectionParticipant::handle()`). Still fails, for the same reason as (1):
   `runAsService()`'s `finally` block runs its cleanup query *before* the exception ever
   reaches this outer catch, so what actually reaches it is a *different* exception (`25P02`,
   about the `set_config()` cleanup call) — not the original `23505` unique violation, so a
   message/constraint-name check correctly doesn't recognize it and re-throws, still an
   unhandled 500.

   `ProvisionSelectionParticipant`'s own version of this pattern works because it calls
   `$this->runner->run(...)` as its own outermost call — a genuinely fresh
   `connection->transaction()` each time, including in its `catch` block's retry. F5's
   `sign()` is different: in production it's *always* called from inside an admin `RlsContext`
   that `ApplyRlsContext` middleware already opened, so `runAsService()` takes the "elevate"
   branch (`RlsContextRunner.php:61-82`), which does **not** own a transaction of its own —
   it just switches `app.role` within whatever transaction the caller already has open.
   There's no equivalent "call `run()` again fresh" recovery available from inside `sign()`.

3. **Catch inside the closure, call `DB::rollBack()` before returning.** Avoids the exception
   (confirmed — no more cascading failure), but is *actively unsafe*: it desyncs Laravel's own
   transaction-nesting bookkeeping. `runAsService()`'s "elevate" branch doesn't own the
   transaction it's running in — the *outer* `run()` call does. That outer
   `connection->transaction()` call still thinks it owns whatever savepoint level is current
   when its own callback returns, and tries to commit it — but a manual `DB::rollBack()` from
   deep inside already popped that level out from under it. Confirmed empirically: the
   `assessment_cases` row created by an *earlier, separate, already-committed* transaction
   level (the test fixture, seeded before the `sign()` call even started) came back `null`
   afterward — this "recovery" discarded more than the failed insert, not less.

**The lesson for any other lane touching `RlsContextRunner::runAsService()`'s "elevate"
branch**: that branch does not own a transaction boundary, so nothing inside a closure passed
to it can safely catch-and-recover from a PostgreSQL statement failure by any of the above
means. The `finally` block's own cleanup query will hit the same aborted-transaction wall
first, and a manual mid-flight rollback corrupts whatever *outer* transaction actually owns
that scope.

## The actual fix: never let the statement fail in the first place

Lead's suggestion, once (1)-(3) had all failed: don't catch the error, avoid causing it.
`->insert([...])` was replaced with `->insertOrIgnore([...])`. On PostgreSQL this compiles to
`INSERT ... ON CONFLICT DO NOTHING` (SQLite: `INSERT OR IGNORE`) — a unique violation on either
of this table's two unique constraints (`(assessment_case_id, version)` or `supersedes_id`)
silently inserts zero rows instead of throwing. `insertOrIgnore()` returns the number of rows
actually inserted; `0` means a conflict was hit, and `sign()` returns `SIGNING_CONFLICT`
*normally*, no exception involved. FK and CHECK violations are unaffected — `ON CONFLICT DO
NOTHING` only ever swallows unique/exclusion conflicts, which is exactly what's wanted here
(both of this table's uniques mean "another signing attempt already won").

This needed no change to `RlsContextRunner` at all. Confirmed on real PostgreSQL
(`tests/Postgres/ReportSigningConcurrencyTest::
test_version_conflict_on_postgres_returns_409_and_leaves_everything_usable`): the same forced
race as before now returns a clean 409, the connection is fully usable immediately afterward,
and — unlike attempt (3) — nothing gets discarded: the simulated competing signer's own row is
still exactly the one row present afterward, proving nothing rolled back at all.

One test-writing trap surfaced while building that PostgreSQL test, unrelated to the fix
itself: verifying "is the row still there" with a plain query under the *unelevated* admin
`RlsContext` (e.g. `run(new RlsContext('psychologist'), ...)`, matching production's own
`ApplyRlsContext` shape) sees nothing, because RLS on `assessment_cases` /
`report_signing_snapshots` only allows the `service` role — the same reason `sign()`'s own
internal reads always go through `runAsService()`. A verification query has to do the same
(`app(RlsContextRunner::class)->runAsService(fn () => ...)`), or it's checking RLS visibility,
not the fix.
