# F2 — Kraepelin randomization mode: seeded → fixed (2026-09-21)

Prerequisite fix for Kraepelin sessions to become creatable at all, per the
owner decision "Kraepelin numbers are fixed, not seeded" (2026-09-21,
`29f3f84`): the answer numbers come from the official sheet, identical for
every participant, not generated per session. Assigned directly by Lead as
independent of PR #46 (Kraepelin grid extraction, still unmerged) —
confirmed true: nothing here depends on the grid data itself, only on the
structural contract around it.

## Scope discovered while fixing, not assumed upfront

The bug was reported as "`SessionDefinition.php:270-271` still requires
`randomization === 'seeded'`". Reading the code turned up three places, not
one, each with a different actual defect:

### 1. `app/Domain/AssessmentSessions/SessionDefinition.php`
`kraepelinConfiguration()` required `'seeded'` + a non-blank seed string.
Now requires `'fixed'` + `seed === null`, exactly like `fixedConfiguration()`
already requires for ist/papi/rmib. `generator` (columns=50, seconds_per_column=15,
numbers_per_column=28, answer_slots_per_column=27) is untouched — it's
structural grid shape/timing, not a randomization seed.

### 2. Two database-level trigger functions, with genuinely different defects
- `assessment_session_definitions` (the catalog/template table,
  `2026_09_10_000200_create_assessment_session_definitions.php`): the
  Kraepelin branch already required `seed` to be JSON null
  **unconditionally**, in a check shared by every instrument above the
  per-instrument branch — only the mode *label* was stale (`'seeded'`
  instead of `'fixed'`). This table's contract was already consistent with
  "no seed", just misnamed.
- `test_sessions.session_definition_*` (the per-session snapshot table, S3,
  `2026_09_10_000100_add_test_session_definition_snapshots.php`): here the
  Kraepelin branch required `seed` to be a **non-blank canonical string**.
  This is the real, load-bearing contradiction — the one that actually
  blocked a real Kraepelin session from ever being created, not a label
  typo.

Both corrected the same way (`randomization = 'fixed'`, `seed` must be JSON
null), matching the existing ist/papi/rmib branch. Neither of the two
original migrations was edited in place (both already shipped, already run
in various environments) — a **new** migration,
`database/migrations/2026_09_21_000100_fix_kraepelin_randomization_mode.php`,
corrects both trigger functions instead:
- PostgreSQL: `CREATE OR REPLACE FUNCTION` on both
  `app_private.guard_assessment_session_definitions()` and
  `app_private.guard_test_session_definition_snapshot()` — the existing
  triggers, grants, and RLS policies stay attached to the function by name,
  untouched, since the function's OID doesn't change under `REPLACE`.
- SQLite: the affected triggers are dropped and recreated (no `CREATE OR
  REPLACE TRIGGER` in SQLite).

**Safety guard (Lead's explicit requirement)**: before installing the
corrected triggers, the migration counts any existing Kraepelin row in
either table that would violate the new rule
(`randomization = 'seeded'` OR a non-null `seed`) and **aborts with a clear
message** if any exist, rather than rewriting or skipping them — the
migration runs in environments this session cannot see (staging, other
local databases), so "no real Kraepelin session could exist yet" is a
reasoned expectation, not a fact the migration is allowed to assume
unchecked. Proven by
`test_migration_aborts_when_an_offending_row_exists_instead_of_rewriting_it`.

**`down()` restores the exact original trigger definitions** (Lead's
explicit requirement) — proven byte-exactly via `pg_get_functiondef()`
comparison before/down/up in
`test_down_then_up_restores_the_exact_original_trigger_definitions`, not
just behaviourally.

### 3. `app/Services/AssessmentSessions/DatabaseAssessmentSessionDefinitionAuthority.php`
Found while fixing, not part of the original bug report — but a direct,
unavoidable consequence of it. This service (the one that actually issues a
`SessionDefinition` when a session starts) had a live `$seedIssuer` closure
that generated a **fresh cryptographically random seed per Kraepelin
session** (`bin2hex(random_bytes(32))` by default) and injected it into the
template before validating it. Left alone, this PR's SessionDefinition/
trigger fix would have made every Kraepelin session start **crash** instead
of silently failing to validate — moving the failure point, not fixing the
underlying problem. Removed entirely (explicit sign-off from Lead,
2026-09-21, per CLAUDE.md's "don't delete existing functions without
explicit permission"):
- The `$seedIssuer` property, constructor parameter, and default
  `bin2hex(random_bytes(32))` closure are gone — dead code once Kraepelin no
  longer needs a per-session seed.
- `decodeTemplate()`'s Kraepelin-only exemption from `SessionDefinition::fromArray()`
  validation (previously needed because the catalog template didn't have a
  real seed yet until injected at allocation time) is gone — the catalog
  template is now complete and valid on its own, exactly like ist/papi/rmib.
- **Test replaced, not just edited**: `test_seeded_template_issues_a_fresh_server_seed_without_storing_it_in_the_catalog`
  tested the old mechanism directly (asserting a real random seed came back
  on the issued definition) — that behaviour is now itself the bug. Replaced
  with `test_active_kraepelin_template_issues_an_exact_session_definition_with_no_seed`,
  proving the corrected behaviour: a Kraepelin template issues its
  definition straight from the catalog, seed always null, no per-session
  injection of any kind — the same shape as every other instrument.

## Test fixture fixes (fixture-correctness, not behaviour changes)

Every test file building a Kraepelin `SessionDefinition`/catalog
row/snapshot row with `'randomization' => 'seeded'` (and, for the snapshot
table, a non-null `'seed'`) needed its fixture updated to `'fixed'`/`null`
to keep constructing a *valid* definition under the corrected contract.
Two test **premises** were inverted, not just relabeled, since they tested
the old (now-wrong) rejection direction:
- `tests/Unit/AssessmentSessions/SessionDefinitionTest.php`: `invalidKraepelinDefinitions()`'s
  `'fixed mode'`/`'missing seed'` cases asserted `'fixed'`/`null` were
  *rejected* — now the valid values, so these became `'seeded mode'`/`'non-null seed'`
  cases asserting the *opposite* (now-invalid) values are rejected. One case
  (`'unicode separator in seed'`) was removed outright — seed can no longer
  be a string at all for Kraepelin, so there is no string-canonicalization
  behaviour left to test there.
- `tests/Feature/Database/TestSessionDefinitionSnapshotSchemaTest.php`:
  `test_kraepelin_snapshot_accepts_only_the_canonical_seeded_matrix` renamed
  to `..._fixed_matrix`; its `$badSeed` case (a malformed seed *string*) no
  longer applies (seed isn't a string anymore) and was replaced with
  `seeded`-mode and non-null-seed rejection cases.

Files touched for fixture-only reasons (behaviour of the test itself
unchanged, just the definition it builds):
`tests/Postgres/AssessmentSessionDefinitionCatalogSecurityTest.php`,
`tests/Postgres/TestSessionDefinitionSnapshotSecurityTest.php` (fixture),
`tests/Feature/AssessmentResults/LoadSealedGenericAnswerSetTest.php`,
`tests/Feature/AssessmentResults/PersistSealedKraepelinResultTest.php`,
`tests/Feature/AssessmentResults/SealPrecomputedKraepelinFactorsTest.php`,
`tests/Feature/Database/AssessmentSessionDefinitionCatalogSchemaTest.php`.

## A second, separate fix forced onto a pre-existing PostgreSQL test

`tests/Postgres/TestSessionDefinitionSnapshotSecurityTest::test_owner_down_up_preserves_historical_null_session_without_backfill`
calls `2026_09_10_000100_add_test_session_definition_snapshots.php`'s own
`down()`/`up()` directly and asserts the trigger/function definitions are
byte-identical before and after. That migration's `up()` recreates
`guard_test_session_definition_snapshot()` via a plain `CREATE FUNCTION`
with its own hardcoded (pre-correction) body — it has no knowledge of this
PR's later migration. So `$before` (captured against the fully-migrated,
corrected function) could never again equal `$after` (that migration's own,
now-stale, recreate) once this PR's migration exists. Fixed by re-applying
`2026_09_21_000100_fix_kraepelin_randomization_mode.php`'s `up()` right
after the older migration's `up()`, before the comparison — this mirrors
what `artisan migrate` actually does on a real rollback-and-remigrate
(replay every later migration in order), not a workaround specific to the
test.

## Docs/notes still saying "seeded" for Kraepelin — listed, not rewritten

Per Lead's explicit scope limit: application code is corrected in this PR;
documents/handoffs that still describe Kraepelin as seeded are **listed
here as a finding**, not edited (out of scope):

- `tasks/handoffs/f2/session-http-autosave-submit.md:250,254`
- `tasks/handoffs/f2/s5-http-cutover-classification.md:289`
- `tasks/handoffs/f2/generic-instrument-result-field-mapping.md:157`
- `tasks/parallel-work.md:232,310`
- `tasks/handoffs/f2-vertical-instrument-ui-readiness.md:20,27,142`
- `tasks/handoffs/f2-four-instrument-authority-manifest-audit.md:37,62,86,91`
- `tasks/handoffs/f2-adr0030-start-flow-readiness.md:118`
- `tasks/handoffs/authority-pack/kraepelin.md:35`
- `docs/ux/participant-assessment-flow-v1.md:13`
- `SPEC.md:218`
- `PANDUAN-EKSEKUSI.md:83`
- `KICKOFF_PROMPT.md:48`

`CLAUDE.md` itself already states the corrected decision ("Kraepelin: angka
TETAP ... BUKAN dibangkitkan/seeded") and needs no change.

## Test coverage

- `tests/Unit/AssessmentSessions/SessionDefinitionTest.php`: 40 tests, green.
- `tests/Feature/AssessmentSessions/DatabaseAssessmentSessionDefinitionAuthorityTest.php`: 6 tests, green.
- Broader SQLite sweep (`tests/Unit/AssessmentSessions`,
  `tests/Feature/AssessmentSessions`, `tests/Feature/AssessmentResults`,
  plus the two touched `tests/Feature/Database` files run directly): 369
  tests total, green. (The full `tests/Feature/Database` directory run as
  one batch hits a pre-existing, unrelated OOM in
  `GenericEntitlementCaseRequirementMigrationTest` — confirmed unrelated by
  running the two files this PR actually touches directly, both green.)
- `tests/Postgres/KraepelinRandomizationModeMigrationTest.php` (new): 5
  tests — new-contract accept/reject in both tables, ist/papi/rmib branch
  unchanged, the offending-row abort guard, and the byte-exact down→up
  cycle.
- `tests/Postgres/AssessmentSessionDefinitionCatalogSecurityTest.php` and
  `tests/Postgres/TestSessionDefinitionSnapshotSecurityTest.php`: re-verified
  green against the real disposable harness after their fixture/cascading
  fixes.
- Combined PostgreSQL run: 27 tests, 216 assertions, green.
- Full `phpstan` gate (level 7): 0 errors. `pint --test`: clean.
