# F2 item-delivery Stage 2 continuation — real RMIB reader + gender-track lock (2026-09-21)

Scope: the third real `AssessmentItemContentAuthority` implementation
(after Kraepelin and PAPI), the first with a per-participant variant axis.
`ist` still has no reader and still rejects the same way Stage 1 shipped
it. Depends on Lead's opt. (a) decision (2026-09-21): the RMIB gender
track is resolved once, at session allocation, and locked with the
session -- never re-derived from the participant's current profile on a
later `GET /sessions/{id}/items` read.

## Interface change: `AssessmentItemContentAuthority::contentFor()`

Gained two parameters: `int $participantId` and `?string $lockedVariant =
null`. Every existing implementor (Kraepelin, PAPI) and every test fake
(`AlwaysAvailableAssessmentItemContentAuthority`, several anonymous
classes across the item-delivery/start-gate test files) updated to match
-- all of them ignore both new parameters, since only RMIB has a variant
axis. `RegistryAssessmentItemContentAuthority` passes both straight
through to whichever reader it dispatches to.

`AssessmentItemContent` gained `?string $resolvedVariant = null`: set only
when a reader was called with `$lockedVariant === null` (the one
fresh-resolution call, at allocation) and it picked a variant. Null
whenever a locked value was already given, and null for every reader
without a variant axis.

**Contract, documented on the interface itself**: `$lockedVariant`
non-null means "use exactly this, never touch `$participantId` or any
live participant state" -- this is what makes a read stable across a
profile change. `$lockedVariant` is null exactly once per session: the
very first call, at `AllocateAndStartAssessmentSession::allocateNew()`,
before anything is persisted. Every later call (every `/items` read)
passes the value already stored on the session.

## `RmibItemContentReader`

Reads `instrument_versions` (code=`rmib_items`, a separate row from
`rmib`'s scoring `categories`/`rotation` data), same checksum/status
verification as Kraepelin/PAPI. Picks `job_male` or `job_female` per
position based on the variant, returns a neutral `job` field -- the
response never reveals which track was used by field naming.

**Structural bug found and fixed while writing tests**: RMIB's 108
positions are 9 groups of 12, with `position` restarting at 1 every 12
entries (`group`+`position` together is what the scoring rotation
formula `category = ((position + group - 2) % 12) + 1` in `rmib.json`
actually keys on) -- NOT a flat 1..108 sequence like Kraepelin's columns
or PAPI's items. The reader's shape validation re-derives both `group`
and `position` from the array offset (`intdiv(offset,12)+1` /
`(offset%12)+1`) as the structural proof of exact, unshuffled source
order, instead of the flat-sequence check the first draft wrongly copied
from PapiItemContentReader.

**Fail-closed for gender**: null/unrecognized `participants.gender` at
the one fresh-resolution moment throws `AssessmentItemContentUnavailable`
-- never a guessed default. Since this reader is consulted at the START
gate (same as Stage 1), a null gender rejects the START itself, before any
`test_sessions`/`test_session_grants` row is written.

## Migration: `test_sessions.item_content_variant`

New nullable `varchar(32)` column, written once at INSERT by
`AllocateAndStartAssessmentSession::insertCreatedSession()`, read back
verbatim by `GetAssessmentSessionItems::load()` and passed as
`$lockedVariant` on every read. `ist`/`papi`/Kraepelin sessions leave it
`NULL` forever.

Immutability extends the EXISTING `guard_test_sessions_identity_revision`
trigger function (PostgreSQL: `CREATE OR REPLACE FUNCTION`, same
technique as #57's
`2026_09_21_000100_fix_kraepelin_randomization_mode.php` -- existing
trigger/grants/RLS stay attached by OID; SQLite: drop and recreate, no
`CREATE OR REPLACE TRIGGER` there) rather than a second, parallel guard
mechanism for one column. `DB::unprepared()` avoided in favor of
`DB::connection()->getPdo()->exec()` (same as #57) -- PHPStan's
literal-string rule on `unprepared()` rejects the interpolated
conditional trigger body; PDO's `exec()` has no such constraint.

CHECK constraint: `NULL`, or a non-blank, trimmed, whitespace/control-char-free
string up to 32 characters (canonical-identity-string shape, matching the
convention used for `session_definition_version`/`provenance` elsewhere
in this table). `down()` refuses with a clear message if any row has a
non-null value, rather than silently discarding a locked-in decision.

## Test coverage

- `tests/Feature/AssessmentSessions/RmibItemContentReaderTest.php` -- 12
  tests against the real seeded `rmib_items.json`: locked male/female
  variants (including proof a locked call never touches
  `participants.gender` at all, via a nonexistent participant ID), fresh
  resolution from a real participant's gender with `resolvedVariant`
  reported back, instructions delivery, fail-closed for null gender,
  fail-closed for a nonexistent participant with no locked variant,
  fail-closed for an invalid locked variant string, non-RMIB rejection,
  inactive/checksum-mismatch/non-final-status/malformed-instructions
  fail-closed.
- `tests/Feature/AssessmentSessions/RmibItemContentVariantLockTest.php`
  -- 2 tests, the mandatory end-to-end proof: (1) a session started with a
  'male'-gendered participant persists `item_content_variant='male'`;
  the participant's gender is then changed to 'female'; `/items` still
  returns `job_male` values, never `job_female`. (2) a null-gender
  participant's RMIB start is rejected and leaves zero
  `test_sessions`/`test_session_grants` rows. Both go through the real
  `StartParticipantAssessmentSession`/`AllocateAndStartAssessmentSession`/
  `GetAssessmentSessionItems` actions with the real `RmibItemContentReader`
  -- no fakes on the item-content side.
- `tests/Postgres/ItemContentVariantMigrationTest.php` -- 4 tests, 14
  assertions: CHECK constraint accepts null/valid values and rejects
  blank/whitespace/oversized values (33 chars hits the column's own
  `varchar(32)` truncation, SQLSTATE 22001, before the CHECK's own length
  clause -- a second, redundant-by-design line of defense, not the only
  one), immutability after insert (both to a new value and back to null),
  `down()` aborts with a clear message when a populated value would be
  discarded, and an up→down→up cycle restores the exact original trigger
  definition byte-for-byte.
- `InstrumentSeederTest.php` / `InstrumentVersionHistorySecurityTest.php`
  updated for the new `rmib_items` seeded source, following the
  `kraepelin_grid`/`papi_items` precedent.

## Test suite status

- SQLite: `tests/Feature/AssessmentSessions`, `tests/Unit/AssessmentSessions`,
  `tests/Architecture` -- 381 tests, 11096 assertions, green.
- PostgreSQL: `ItemContentVariantMigrationTest` -- 4 tests, 14 assertions,
  green. Full organization suite run separately for the known-7-baseline
  check (see PR description for exact counts at push time).
- Full `phpstan` gate: 0 errors. `pint --test` (full repo): clean.

## CI note

GitHub Actions still down repo-wide (billing issue, unrelated, project
owner notified). Local results above stand in until it recovers; merge
held until then.

## Deferred, still open

`ist` -- item data extraction not final yet, stays fail-closed like Stage
1 shipped it. `POST /sessions/{id}/events` (Kraepelin answer ingestion)
and the generic scoring orchestrator (ADR-0032) are separate, larger
pieces of work tracked independently.
