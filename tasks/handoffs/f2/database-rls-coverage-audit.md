# Database-wide RLS coverage audit + ratchet (2026-09-21)

Triggered by PR #78's `assessment_asset_references` RLS gap (Lead's review):
a new table shipped with no RLS/GRANT at all, and nothing caught it except
manual review. Lead's follow-up ask: a catch-all PostgreSQL test that scans
the whole catalog so this class of gap can never ship silently again.

## What shipped: `tests/Postgres/DatabaseRlsCoverageSecurityTest.php`

A **ratchet**, not a bug-fix gate (Lead's explicit call, to avoid reddening
CI for every PR over debt that already exists): scans every `public`-schema
table via `pg_class`/`pg_namespace`, and requires each one to be in exactly
one of three states:

1. RLS `ENABLE`+`FORCE`-protected (the default expectation for any table).
2. Listed in `EXCEPTION_TABLES` with a written reason (Laravel's own
   framework infrastructure — `cache`, `cache_locks`, `jobs`, `job_batches`,
   `failed_jobs`, `migrations`).
3. Listed in `KNOWN_GAPS` with a finding code and reason (frozen, tracked
   debt found by this audit).

A table in none of the three fails the build. Two extra tests keep both
lists honest: `test_every_listed_table_still_exists` (a dropped table can't
leave a stale entry) and `test_known_gaps_are_still_actually_unprotected`
(a fixed gap MUST be removed from the list, or this test fails — the list
can never silently go stale once a fix lands).

## The audit's findings (2026-09-21 catalog scan, 63 public tables)

44 tables already correctly RLS-protected, not listed. The rest:

**`EXCEPTION_TABLES`** (framework infrastructure, confirmed with Lead):
`cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `migrations`.
Separate note (Lead, not RLS-relevant): `jobs`/`failed_jobs` payloads can
carry participant data — that's a retention question, tracked as its own
finding, not by this test.

**`KNOWN_GAPS`** (real app-data tables, no RLS, each with a finding code —
these are genuine gaps, not test-authoring debt):

- `RLS-GAP-01..04` — `generic_assessment_result_versions`, `..._outbox`,
  `..._dispatch_attempts`, `..._callback_schedules`. The Selection-integration
  result-callback pipeline (`GenericAssessmentResultStore`/`Outbox`/
  `Dispatch`/`CallbackOrchestrator`). Currently feature-flag-gated
  (`selection_integration.result_callback_enabled`) but the code and tables
  are real and wired, not stubs.
- `RLS-GAP-05` — `report_number_sequences` (`ReportNumberIssuer`).
- `RLS-GAP-06` — `test_number_sequences` (`MonthlyTestNumberIssuer`, used by
  the scheduled `test-numbers:prepare-month` command).
- `RLS-GAP-07/08` — `packages`/`package_items`: **not just missing RLS** —
  `relrowsecurity=0` but `relforcerowsecurity=1` (FORCE set without ENABLE),
  a broken/incomplete migration, not a deliberate choice either way. Read by
  the public registration page per Lead's note — likely needs a
  non-service-only read policy, not a blanket service-only one like every
  other table this reader has built RLS for so far.
- `RLS-GAP-09..12` — `users`, `password_reset_tokens`, `sessions`,
  `passkeys`: the Fortify/Jetstream default auth-scaffold cluster. Live and
  linked (not dead code — see the dedicated fact-finding report,
  `tasks/handoffs/f2/fortify-users-cluster-investigation.md`), of
  undetermined product intent. Lead is taking this to the project owner;
  not to be fixed or removed without that decision (CLAUDE.md requires
  explicit permission before deleting existing code).

## Deliberately NOT done here

No RLS was added to any `KNOWN_GAPS` table. Lead's explicit instruction:
enabling RLS on a table something reads outside a service context breaks
that read in production — the exact bug class this whole audit started
from (PR #78's own gap, and the earlier "S4" precedent). Each gap's
remediation needs its own plan mapping every read/write path (caller +
RLS context) before any policy is written, then its own PostgreSQL proof
under `psikotes_runtime` — sent as a plan first, per standing convention
for anything touching security/data. Not started yet.
