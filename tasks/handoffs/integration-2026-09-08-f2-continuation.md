# F2 continuation integration checkpoint — 2026-09-08

Branch: `codex/organization-payment-spec`

This checkpoint is the resume authority for the current parallel wave. It does
not replace the acceptance checklists and does not claim completion for F2 or
the complete PRD.

## Accepted increments

### Assessment-session contract and pure domain

- Contract freeze: `9bcee32` (`API_CONTRACT.md` v4.2).
- Pure lifecycle/deadline decisions: `baf152a`, `fea3877`, `263f3ac`, and
  `8a6dd5c`.
- Initial schema history: `0c0415d` and `df9cb91`.
- Coordinator SQLite verification before the PostgreSQL repair: 78 tests and
  282 assertions passed.

The PostgreSQL schema/RLS increment is accepted as `bf72c29` plus the
coordinator-requested ledger/revision repair `2b3937a`. It now includes explicit
lifecycle nullability, timestamp(6), ENABLE+FORCE RLS, operation-specific
policies, server-authoritative participant start/submit timestamps, bounded
answer writes, fully append-only mutation receipts, exact receipt-bound revision
increments, and a locked populated-rollback refusal.

Independent coordinator verification after the repair:

- Full PostgreSQL disposable suite: 411 tests, 4,534 assertions, all passed.
- Runtime role: non-owner, NOSUPERUSER, NOBYPASSRLS.
- Focused SQLite schema/static gate: 9 tests, 107 assertions, all passed.
- Disposable PostgreSQL container/network cleanup: confirmed.

### Pure psychometric scoring

Accepted commits through this checkpoint:

- IST: `c36d49d`, `1e52dd9`, `c9dcc11`, `435e506`, `bf60d23`, `926c188`.
- PAPI raw scoring: `9145c9d`.
- RMIB totals and tie-safe review output: `ffafcfd`, `edbe8a8`, `b09af8f`.
- Kraepelin factor/band work: `5d8390c`, `492a1cf`, `b395458`, `8fdc508`.

Independent coordinator verification after the RMIB/Kraepelin repairs:

- `tests/Unit/Scoring`: 127 tests, 299 assertions, all passed.
- F0 extraction gate: 11 tests, all passed.
- Pint on scoring source/tests: passed.
- Commit-range diff check: passed.

The former IQ/PAPI/RMIB/Kraepelin authority conflicts are resolved by ADR-0028
and accepted implementation `10bc513`. Versioned data now defines IST
IQ-to-level, PAPI white-zone-distance normalization, RMIB rank-to-level with
competition ties, Kraepelin Panker semantics, and half-up three-decimal band
lookup.

Independent coordinator verification for `10bc513`:

- Full scoring suite: 194 tests, 875 assertions, all passed.
- F0 extraction gate: 11 tests, all passed.
- Instrument seeder integration: 3 tests, 47 assertions, all passed.
- Pint and scoped PHPStan: passed with zero errors.
- Staged secret scan and diff check: passed.

DASS-21 pure scoring is accepted as `1bbbb70`; screening follow-up and validity
metadata are accepted as `39836d7`. Coordinator verification after both commits:

- `tests/Unit/Scoring`: 168 tests, 408 assertions, all passed.
- F0 extraction gate: 11 tests, all passed.
- Pint and scoped PHPStan: passed.

The DASS output remains separate from eligibility, zone, label, diagnosis, and
user-facing narrative. Package inclusion and consent orchestration are outside
these scorer classes.

The T-01 through T-11 evidence matrix is updated with the new evidence: T-01
through T-06 and T-08 through T-11 pass. T-07 remains partial until the
recommendation-label boundary can run the architectural comparison. This matrix
is evidence, not a new checklist.

### Assessment-session application action

Atomic autosave is accepted as action commit `4a73141` plus direct PostgreSQL
verification commit `c070ba2`.

- SQLite action plus session unit gate: 79 tests, 289 assertions, all passed.
- Direct/full PostgreSQL disposable gate: 416 tests, 4,595 assertions, all
  passed.
- Race evidence used two forked runtime NOBYPASSRLS backends, observed an
  actual row-lock waiter, and produced exactly one commit plus one exact replay.
- Scoped PHPStan, Pint, and diff checks passed.

The test-only owner cleanup is bound to the exact disposable database marker
and exact synthetic IDs. It does not weaken the append-only production ledger.

Participant-owned start/resume is accepted as `3cc0e82`.

- SQLite start/autosave plus session unit gate: 84 tests, 344 assertions, all
  passed.
- Full PostgreSQL disposable gate: 417 tests, 4,617 assertions, all passed.
- Two non-owner NOBYPASSRLS processes demonstrated one start plus one replay,
  with an observed row-lock waiter and an identical immutable 3,671-second
  session window.
- Missing/cross-participant and DASS identities fail closed; overdue resume
  expires without extending the deadline; closed states remain closed.
- Scoped PHPStan, Pint, and diff checks passed.

### F3 versioned zone boundary

T-06 is accepted as `db459fe`. It extracts the 18 base standards, field-specific
raised standards, and required-interest standard from the authoritative Grey
Area worksheet, then applies them in a fail-closed pure eligibility service.

- Scoring plus eligibility suite: 203 tests, 1,016 assertions, all passed.
- All six job fields and all 18 aspects are covered.
- F0 extraction gate: 11 tests, all passed.
- Instrument seeder integration: 3 tests, 47 assertions, all passed.
- Pint, scoped PHPStan, diff check, and staged secret scan passed.

The next F3 increment is recommendation-label guardrails. T-07 remains partial
until that label boundary exists and can prove DASS non-interference end to end.

Recommendation-label guardrails are now accepted as `7334fa0`. The policy
implements the ordered G1/G2/G3 label rules against the exact T-06 payload,
rejects extra input fields, and therefore has no DASS input boundary.

- Eligibility suite: 34 tests, 198 assertions, all passed.
- Static psychometric/eligibility separation: 3 tests, 17 assertions, passed.
- Pint, scoped PHPStan, and diff check passed.

The static separation guard itself is accepted as `549b2e4`; it is deliberately
structural and does not yet close the behavioral two-session requirement T-07.

Behavioral T-07 is now accepted as `3c5b164`. Canonical DASS scoring produces
distinct Normal and Sangat Parah results while the otherwise-identical
psychometric input produces exact-identical zone and recommendation outputs.
The focused integration test passed 1 test/10 assertions; the architecture
regression passed 3 tests/19 assertions. This is an in-process domain integration
boundary, not a persistence/HTTP/report E2E claim.

G7 source discrepancy is accepted as `eb5e946`. A source-level spread of at
least two emits a deterministic review-required result and blocks automatic
narration for that aspect. The full Eligibility suite passed 70 tests/239
assertions; architecture separation, Pint, PHPStan, and diff checks passed.

### Assessment-session submit action

Participant-owned atomic submit/replay/expiry is accepted as `87b6346`.
It seals an accepted session at `submitted`, preserves exact replay state,
expires late in-progress sessions, rejects cross-participant/missing resources
without disclosure, and does not run scoring.

- Focused submit/policy suite: 10 tests, 73 assertions, all passed.
- Full disposable PostgreSQL suite: 419 tests, 4,640 assertions, all passed.
- Disposable resources were cleaned; application containers were not targeted.
- Pint, scoped PHPStan, and diff check passed.

The frozen public contract still promises synchronous `status: scored`; bridging
`submitted` to authoritative scoring/recovery is therefore an open coordinator
integration boundary, not silently claimed by this increment.

### P17c synthetic verification

Accepted commits include `fee63d4`, `ef2a2be`, `4326bda`, `f189ad1`,
`e411259`, `8a1e760`, and `2176656`. The host-native synthetic listener test
and its three controls passed after the stale fixture repair. This does not
close P17c/P18: real browser/native candidate and launch evidence are still
absent, and the related gates remain default OFF.

Commit `b0bc774` records the remaining participant secure-origin runtime gap;
the coordinator independently reran its 66 pure/static tests successfully.
Commit `41eb486` is accepted only as a non-authoritative contract proposal for
review. It does not freeze trust/revocation policy, authorize TLS verification,
or change P17c/P18 status.

## Current lane state

| Agent | Last accepted ownership | State | Next safe dependency |
|---|---|---|---|
| `/root/review_session` | Session schema/RLS, autosave, start/resume, submit and isolated tests | queued for F4 pure narrative slice | Implement only a deterministic injected-data cluster assembler; do not change session/API contracts |

`/root/review_ist` is queued for the isolated G7 source-discrepancy policy.
`/root/review_p17c` is queued for the test-only behavioral T-07 comparison.
All three increments start from coordination baseline `6fb0700` and have
disjoint exclusive files.

The first F4 slice is accepted as `457c8b6`: its injected-data ID cluster
assembler is deterministic, rotates direction-appropriate connectors, and
omits unresolved G7 aspects from automatic narrative. The Narrative suite
passed 35 tests/44 assertions with Pint, scoped PHPStan, and diff checks. The
active next slice owns only a new canonical reporting-data adapter and test.

The canonical reporting-data adapter is accepted across `c327f2b` and
follow-up `c7618b9`. It validates all 90 ID/JP bank entries and the canonical
5 additive, 5 contrast, and 2 ending connectors, then supplies deterministic
ID assembler inputs. The Narrative suite passed 62 tests/80 assertions. A
shared-index race placed the first code snapshot in the coordinator docs commit;
the final regression delta was committed separately without reset, amendment,
or data loss.

The standalone Japanese cluster assembler is accepted as `7eb1fdc`. It emits
ordered independent JP sentences without additive/contrast connectors, remains
deterministic, and omits unresolved G7 aspects. The Narrative suite passed 92
tests/118 assertions with Pint, PHPStan, and diff checks. The next F4 slice is
limited to exposing the already-validated canonical JP bank from the catalog.

Canonical JP projection is accepted as `c1e8b93`: all 90 Japanese entries are
preserved exactly and feed the JP assembler, while the established Indonesian
catalog contract remains unchanged. The Narrative suite passed 94 tests/126
assertions with formatter, static-analysis, and diff gates.

Explicit recommendation guardrail acceptance evidence is accepted as
`f981c92`: T-12/G1, T-13/G2, T-14/G3, and T-19 boundaries pass against canonical
reporting data and the real zone/label services (7 tests/49 assertions).

The pure F5 signing prerequisite gate is accepted as `4933c12` (34 tests/50
assertions). It enforces the V3, V2-note, G7-resolution, G9 conditions,
override-reason, target-field, and A-D narrative prerequisites. It does not
claim persistence, signatures, publication, or state transitions; those remain
separate increments.

The exhaustive pure report lifecycle is accepted as `74b92ca`: all 81 state
pairs/validation cases pass, no draft-to-publish shortcut exists, and REVOKED
and VOID are terminal. The pure G6 override policy is accepted as `204c226`
(50 tests/68 assertions): changed levels/labels require a Unicode-aware
20-character reason, preserve system/final values, and emit audit/recalculation
signals. Persistence, audit writing, and actual recalculation remain separate.

Pure G6 recalculation composition evidence is accepted as `49c9c4c`: a changed
critical level is applied to a fresh zone/label calculation while the system
value is preserved; label-only override leaves zones unchanged. The focused
integration passed 2 tests/27 assertions.

Signing transition composition is accepted as `b6bdfbc` (23 tests/49
assertions; full Review 188/248). It preserves all prerequisite blockers with
no transition and permits only the exact UNDER_REVIEW-to-SIGNED transition.
Persistence and real signing remain outside this boundary.

The bilingual four-cluster composer is accepted as `6187b9b` (Narrative suite
112 tests/178 assertions). F5 T-15/G5 and T-22/G9 integration evidence is
accepted as test commit `e3200f8` (12 tests/37 assertions), but the subsequent
adversarial review reopened the implementation boundary before persistence.

Adversarial findings requiring repair:

- P1: the raw state machine still permits UNDER_REVIEW-to-SIGNED without the
  prerequisite wrapper;
- P2: V3 recommendation output has no label but signing input requires one;
- P2: G7 rejects valid one-source aspect provenance;
- P2: eligibility configuration accepts invented fields and extra keys;
- P2: signing trusts caller-supplied empty G7/override summaries instead of an
  authoritative derived snapshot.

The first four findings are repaired and independently accepted:

- `44d047e` makes the generic lifecycle state machine reject every direct
  transition to `SIGNED`, confines signing to the prerequisite policy, and
  composes canonical V3 output with `label=null` while still blocking signing;
- `5caba7c` accepts a valid single-source aspect as spread zero while preserving
  empty-source rejection and all multi-source discrepancy thresholds;
- `34f35c1` requires the exact six canonical field codes, exact field-record
  keys, canonical required-interest structure, and consistent raised-to-four
  representations.

Independent combined verification passed Eligibility 101/280,
Review/Psychometric/Review-integration 215/380, Narrative 112/178, and
Architecture 12/221: 440 tests and 1,059 assertions total. Pint, scoped PHPStan,
and Git diff checks also passed. The authoritative derived signing snapshot is
the remaining P2 implementation boundary. No persistence or publish path is
accepted yet.

Next parallel wave starts from `b79f2f3` with disjoint ownership: Review owns a
new authoritative signing snapshot composer plus the existing transition
adapter; Architecture owns only a new static direct-signing bypass guard; and
Narrative owns only a new T-20 bilingual determinism acceptance test. No shared
contracts, routes, migrations, lockfiles, production data, or active services
are assigned.

The Review lane additionally owns
`tests/Integration/Review/ReportSigningGuardrailsAcceptanceTest.php`: its legacy
raw prerequisite calls conflict with the intentionally narrowed transition
boundary and must be migrated to the real snapshot composer. Neither other
active lane owns Integration/Review files.

F4 T-20 bilingual determinism acceptance is independently accepted as
`fc9e78c`: repeated canonical composition is byte-for-byte equal across ID/JP,
all 18 aspects are accounted for, G7 omissions align across languages, and
reordered input fails closed. Coordinator rerun passed Narrative 114 tests/202
assertions. The same lane continues with a disjoint new T-21 connector-rotation
acceptance test only.

The static signing-bypass guard is independently accepted as `e6de244`. Its AST
controls detect literal positional/named `SIGNED` transition calls outside the
single allowlisted signing policy, while non-signing calls and string/comment
text do not trigger it. Coordinator rerun passed Architecture 22 tests/1,763
assertions. Dynamic/obfuscated target expressions remain explicitly outside
this static proof. The lane continues with a new pure-domain G8 version-isolation
acceptance test only; database persistence non-retroactivity is not claimed.

F4 T-21 connector rotation is independently accepted as `28e2969`: the ID
assembler exhausts canonical direction-specific connector pools before reuse,
the bilingual rerun is identical, and JP output remains free of ID connectors.
Coordinator rerun passed Narrative 115 tests/255 assertions. The lane now
performs a read-only authority audit for the still-open seven-slot internal
draft so no narrative template is invented.

The subsequent source audit reopened full T-21 closure: supporting DOCX
`Update DASS/Spesifikasi Tim Teknis - Engineer Sistem_Psikotes.docx` section
8.1 specifies separate `dipakai.aditif` and `dipakai.kontras` counters, whereas
the current assembler selects by global transition position. `28e2969` remains
valid for homogeneous directions but does not cover the mixed-direction
sequence; a bounded repair follows after the read-only audit completes.

The read-only seven-slot authority audit found exact templates in workbook
sheet `9. Integrasi`, support phrases in sheet `10. Saran Dukungan`, and ordering
rules in the psychologist technical specification section 8.2. It also found
unresolved authority for S1 averaging/rounding, S2/S3 selection counts and G7
handling, S4 same-priority ties/OK filtering, S5 D-aspect support coverage, S6
RMIB ties and UMUM, S7 wording/duration, JP slot bodies, system-versus-final G6
levels, and any S1-S7-to-Uraian A-D summarization. No production slot prose or
automatic summary is authorized until those gaps are decided. The current
implementation wave therefore repairs only the independently specified
connector counters.

The connector-counter repair is independently accepted as `9675c4d`. Mixed
direction tests first reproduced the global-position defect, then passed with
separate additive/contrast pool counters. Coordinator rerun passed Narrative
115 tests/257 assertions.

The pure-domain G8 version-isolation acceptance is independently accepted as
`8c7498b`. Old/new calculators and recommendation results retain exact standard
version provenance and the old snapshot remains unchanged after the synthetic
new-version calculation. Coordinator rerun passed Eligibility 102 tests/295
assertions. This does not close database snapshot non-retroactivity; the lane
now performs a read-only persistence gap audit while migration ownership remains
unassigned.

The authoritative pure signing snapshot is accepted across `a8fb562` and the
coordinator-requested target-field repair `4ec39cf`. The transition boundary no
longer accepts raw prerequisite arrays; it derives G7 and changed override
projections from validated policy output shapes, requires structural audit and
recalculation evidence, and binds the report target to one of the six canonical
recommendation fields. Combined coordinator gates passed Eligibility 102/295,
Narrative 115/257, Review/Psychometric 228/398, and Architecture 17/992. Pint,
scoped PHPStan, and diff checks passed. Boolean resolution/audit evidence is
still a pure structural claim, not database authority.

The persistence audit confirms there is no durable report aggregate,
eligibility snapshot, G6 override/recalculation ledger, G7 resolution ledger,
or signing/publication ledger. The smallest independent prerequisite is source
history hardening for `instrument_versions`. Coordinator freezes a fail-closed
matrix for that slice: PostgreSQL login remains `psikotes_runtime`; only
transaction-local application role `service` may SELECT/INSERT/deactivate;
all admin, psychologist, branch, staff, participant, and missing contexts are
denied; runtime DELETE is denied. The existing seeder must run inside
`RlsContextRunner::runAsService`, never via a NULL-context policy exception.
This slice is prerequisite evidence only and does not close G8 report snapshot
non-retroactivity.

Fresh adversarial review reopens the signing snapshot acceptance despite its
passing regression gates. Three concrete probes still reached a synthetic
`SIGNED` transition: an invented standard/all-unassessed recommendation
summary, a critical level change paired with a stale recommendation plus a true
recalculation boolean, and a spread-two G7 result cleared by a bare resolution
boolean. Therefore `a8fb562`/`4ec39cf` are retained as implementation history
but are not an accepted authority boundary. Correct closure requires full
eligibility input/version binding, a typed post-override recalculation artifact,
typed G7 resolution with final level/reason, and ultimately a transactional
persistence command. A separate static guard extension may detect manual
literal successful-SIGNED arrays, but is defense-in-depth and does not close
these P1 findings.

The next parallel wave keeps ownership disjoint: one serial migration owner
hardens only `instrument_versions` plus its trusted seeder execution and
disposable PostgreSQL tests; Narrative adds only a pure A1-C7 S5 ordering
primitive with no prose; Review performs a read-only adversarial audit of the
accepted signing snapshot. Shared report identity, routes, DTOs, lockfiles,
canonical JSON, and active services remain untouched.

Coordinator review accepts the bounded S5 ordering primitive `951651c` and the
true-extrema repair across `e0b5d62` + `f50f75e`. The latter was reopened
because it promoted a resolved runner-up when the actual extreme was G7-blocked;
the repair now preserves the real B/C extreme, omits blocked candidates, and
does not claim S2/S3 prose authority. Focused coordinator verification passed
23 tests/30 assertions; the worker's full Narrative gate passed 159/309.

The manual successful-SIGNED array guard `7891d09` is accepted as static
defense-in-depth after the combined coordinator gate passed 56 tests/882
assertions. It does not repair the signing authority boundary. The read-only
signing audit found a fourth caller-forgeable path: internally consistent G7
source arrays can still be replaced with synthetic lower-spread sources because
they are not bound to stored F2 evidence. The safe sequence is now two parallel
pure artifacts (derived eligibility decision and typed 18-aspect G7 review set),
then a typed post-override recalculation artifact, and only then serial signing
composer integration. Persistence must wait for a coordinator-owned report /
assessment aggregate identity decision.

The first two typed artifacts are independently accepted as bounded partials:
`a025c4b` derives an immutable eligibility decision from exact 18-aspect inputs
and emits V3 as publication-blocked, while `3dac6c5` replaces boolean G7 evidence
with typed per-aspect states and a complete signing-ready 18-aspect set. The
coordinator reran Eligibility at 118 tests/349 assertions and Review at 252/349.
Neither artifact is persistence authority: raw eligibility configuration/source
versions and G7 discrepancy sources remain caller-supplied until a transactional
reader binds them to stored evidence. The next pure step is a reviewed decision
artifact that performs real post-level-override recalculation and validates an
optional label override without accepting recalculation booleans.

Instrument history commit `25d6b37` remains repair-required despite the full
disposable PostgreSQL gate passing 423 tests/4,696 assertions. Adversarial
inspection found that nullable timestamps can bypass the monotonic deactivation
trigger and that populated rollback is only proven with a superuser owner, so a
FORCE-RLS non-superuser owner could observe a false empty table. A serial repair
owns only that migration and its isolated tests. No active database was used;
the disposable containers and network were removed by the runner.

The repair `d7b5359` closes both history findings. Active NULL timestamps are
rejected before upgrade and by the insert/update trigger; populated rollback is
now proven with a real table owner that is `NOSUPERUSER` and `NOBYPASSRLS`.
Independent verification accepts `25d6b37` + `d7b5359` after the full disposable
PostgreSQL suite passed 426 tests/4,717 assertions, scoped Pint/PHPStan passed,
and the exact runner containers/network were absent after cleanup. The ordinary
unique-index creation may briefly lock this small catalog, so rollout must occur
before concurrent version seeding; this is operational, not an acceptance gap.

Real post-override recalculation is accepted as bounded pure-domain commit
`2ac749f`. It retains the baseline field, IQ, validity, standard configuration,
and source versions privately; exact ProfessionalOverridePolicy outputs update
levels, then a new EligibilityDecisionSnapshot is calculated before an optional
label override is checked. Raw recalculation/audit booleans are no longer part
of this artifact. Coordinator gates passed 369 tests/1,491 assertions and
PHPStan with zero errors. It remains non-authoritative until persistence loads
and binds the raw sources.

The identity audit found no existing document or schema that unifies direct,
legacy Selection, and integrated assessment attempts. ADR-0029 therefore
accepts `assessment_cases` as the immutable universal battery/report root, with
instrument sessions scoped to the case and one append-only report-version
stream per case. Ambiguous historical participant-to-case mappings must stop
backfill; they may never be guessed. This freezes the next serial migration
boundary without creating or running a migration.

## Preserved user-owned work

`docs/decisions/0026-checkout-secure-composition-launcher-authority.md` was
already untracked at this checkpoint. It is outside the active assignments and
must not be edited, deleted, or included in coordinator commits.

## Resume order

1. Preserve the user-owned ADR and inspect `git status --short`.
2. Snapshot existing agents; do not resend an increment to a running task.
3. Implement and review the authoritative derived signing snapshot before any
   persistence or publication work.
4. Keep migrations serial. Do not close PostgreSQL/RLS acceptance without a
   disposable PostgreSQL runtime pass.
5. Choose the next dependency-unblocked increment from `tasks/parallel-work.md`;
   keep architecture enforcement and deterministic narrative acceptance in
   separate non-overlapping test-only lanes when they can run in parallel.

## 2026-09-09 typed signing and persistence checkpoint

The typed signing composer is accepted across `a9375ed` and repair `9b59cd6`.
The canonical snapshot now binds the complete reviewed eligibility decision and
all 18 G7 evidence projections. Semantic permutations retain one hash, while a
different level, source version, or G7 fact changes the hash. Independent
coordinator verification passed 382 tests/1,726 assertions and scoped PHPStan.
This remains a pure-domain boundary; `persistence_authority_bound=false` is
intentional until a transactional reader reloads stored source evidence.

ADR-0029 phase-1 schema is accepted across `542cebd` and repair `fef865c`.
It creates the immutable universal `assessment_cases` root and nullable links,
enforces participant/organization tenant consistency with a composite database
foreign key, and indexes the nullable package foreign key. The repair also
updates the legacy migration regression to unwind dependent migrations in
reverse order instead of weakening constraints. Independent coordinator gates
passed SQLite 4/58, scoped PHPStan/Pint, and the full disposable PostgreSQL
suite at 431 tests/4,784 assertions; all disposable resources were removed.

Read-only backfill inspection found that only INTEGRATED attempts currently
have a lossless case identity: `assessment_attempt_id` is the prescribed case
public ID. The next case increment is therefore integrated dual-write followed
by deterministic backfill and enforcement of
`assessment_participants.assessment_case_id`. DIRECT_PUBLIC, legacy Selection,
`test_sessions`, and DASS remain nullable because participant-only mapping is
ambiguous when one person has multiple batteries; no migration may guess those
links.

Read-only F2/F3/F5 persistence audits also confirmed that existing generic
result versions are integration-only IQ projections, not normalized-result
authority. After case enforcement, the smallest independent schema slice is an
immutable case-bound instrument result/version ledger plus exact source-level
children. The later eligibility, G6, G7, report-version, signing-idempotency,
and dedicated append-only audit ledgers remain serial migrations. DASS stays in
its isolated schema and must not become an input or foreign-key dependency of
the 18-aspect normalized result.

## 2026-09-09 integrated dual-write and F4 boundary checkpoint

Integrated assessment-case dual-write phase 2A is accepted across production
commit `a48e2af`, PostgreSQL acceptance commit `8002ba8`, and deterministic
race-evidence repair `5f3ee01`. Both integrated provisioners now create the
INTEGRATED case and assessment participant atomically with the same public /
attempt ULID and exact participant, organization, and package binding. Replay
validation fails closed on ambiguous or mismatched history, and injected
post-insert failure leaves no case, attempt, participant, entitlement, outbox,
or audit orphan.

The PostgreSQL race tests use two independent runtime-role processes. Both
workers are held after their initial empty replay lookup and before insert;
the parent proves both reached that barrier, and lookup counts `[1,2]` prove
one unique-conflict loser executed the catch/refetch path. Final adversarial
review found no remaining P1/P2 issue. Coordinator verification passed Feature
88 tests/540 assertions, scoped PHPStan/Pint, and a fresh disposable PostgreSQL
run at 435 tests/4,935 assertions. Its exact containers and network were
removed. Phase 2B may now be assigned to one migration owner: backfill only
losslessly mapped INTEGRATED attempts and enforce their case binding without
guessing DIRECT_PUBLIC, legacy Selection, test-session, or DASS identities.

F4 structural target-interest evidence is accepted as `db3dce3`. It emits
exactly five canonical D1-D5 rows, assesses only the configured field target,
marks risk only for GREY/BELUM targets, keeps UMUM unassessed, and fails closed
when review evidence is unresolved. The non-claiming S5/S6 boundary acceptance
is accepted as `b8efab2`; it prevents the current partial primitives from being
mistaken for an authoritative S5/S6 composer. Production S5/S6 remains blocked
on three unresolved decisions: D-aspect support coverage, RMIB ties/UMUM and
suitability-label derivation, and system-versus-final G6 level selection.
The D5 eligibility fixture correction `f9bd44a` aligns provenance with RMIB
Social Service (`RMIB_S.Se`). These three accepted increments passed independent
Narrative/Eligibility, PHPStan, Pint, and diff checks.

The read-only F2 ledger freeze confirms that phase 2B is necessary but not
sufficient for result persistence. `test_sessions.assessment_case_id` remains
nullable and rebindable, while the 42 aspect-source associations exist only as
prose in SPEC/SCORING_ALGORITHM rather than a versioned data source. Therefore
no writer or signing reader may claim authoritative instrument results yet,
and those mappings must not be hardcoded into a migration. The eventual design
is an immutable case/session/instrument/version parent ledger plus exact ordered
source-level children, append-only FORCE-RLS access, canonical checksums and
idempotency, and eager loading in one parent plus one child query. DASS,
eligibility/G6/G7, and report/signing data stay outside that ledger. Required
predecessors are a separate immutable case-bound test-session slice and a
coordinator/F0 extraction of the 42 mappings into versioned configuration.

## 2026-09-09 integrated case enforcement checkpoint

Assessment-case phase 2B is accepted across foundation `2dded9c`, fixture
adapter commits `1d4354e` and `1a8033e`, and exact-definition repair
`be98ec7`. Historical `assessment_participants` now backfill only through the
lossless integrated mapping: attempt ULID becomes case public ID, with exact
participant, organization, package, preserved creation instant, INTEGRATED
origin, and a NULL historical intended-field snapshot. Existing bindings are
preflighted rather than repaired; any collision, mismatch, malformed history,
or partial enforcement aborts atomically.

PostgreSQL locks case then attempt tables, preserves FORCE RLS, and enforces a
composite identity FK, physical NOT NULL case binding, exact INTEGRATED insert
guard, and immutable identity on updates. SQLite performs a physical NOT NULL
rebuild while preserving existing descendants, indexes, foreign keys, and
funding triggers. Exact catalog validators and isolated corruption probes cover
the parent unique, child FK order/actions, and both guard definitions. Populated
rollback refuses without deleting history; empty rollback is tested. The
PostgreSQL harness now runs the three schema-boundary files first and selects
all 51 files exactly once, while ordinary fixtures retain immutable synthetic
history and scope assertions to their fixture IDs.

Final coordinator verification passed 106 Feature/SQLite tests with 689
assertions, full disposable PostgreSQL at 444 tests/5,007 assertions, configured
PHPStan with zero errors, Pint, and diff checks. Exact containers and network
were removed. Independent adversarial review found no remaining P1/P2. This
does not bind `test_sessions`, direct/legacy flows, or DASS; the next result-ledger
dependencies remain session-case immutability and a versioned 42-source mapping.

The follow-up session audit confirms that universal `test_sessions` case NOT
NULL would guess history. Existing sessions carry participant/test/attempt and
opaque authorization/allocation identifiers, but no integrated attempt, order,
Selection, origin, organization, or package relation. A pre-bound exact row may
be retained, and an unbound row may be linked only when its participant has
exactly one accepted case. Zero-candidate rows remain NULL because direct and
legacy flows do not create cases; multi-case rows abort as ambiguous. Timestamps
are not a mapping heuristic. The next serial slice is therefore nullable
session integrity: exact preflight, single-candidate backfill, composite
case/participant FK, bound-only case indexes, and immutable case/created-at
identity while preserving existing participant-scoped uniqueness and RLS.
Final NOT NULL waits for direct and legacy case identity, a case-aware atomic
session allocator, and explicit adjudication of multi-case history.

The nullable session-integrity foundation is accepted as `7e5fbba`. It binds
only unbound sessions whose participant has exactly one accepted case, retains
zero-candidate NULLs, and aborts atomically on ambiguous or corrupt history.
Bound rows receive an exact case/participant composite FK, case-scoped unique
indexes, and immutable case/created-at identity while participant-scoped
compatibility remains. PostgreSQL migration locks follow writer order
`test_sessions` then `assessment_cases`; FORCE RLS and service-only insert
semantics remain intact. SQLite preserves cross-table trigger SQL and behavior
through its rebuild. Exact-state corruption probes cover FK/index/guard
definitions, and the PostgreSQL harness selects all 52 files exactly once with
four schema-boundary files first. Worker full PostgreSQL passed 464 tests/5,124
assertions; coordinator focused gates passed 195/1,027 and adversarial review
found no P1/P2. A coordinator full rerun executed all 464 tests but hit only the
pre-existing invoice timing threshold at 3.0507s versus 3.0s; no functional
error occurred and disposable cleanup completed.

The versioned aspect-source configuration is accepted as `07eaa98`. Its
extractor reads the authoritative workbook rather than embedding mappings,
emits exact A1-D5 order with 18 aspects and 42 associations, normalizes A1 to
`IST_IQ`, maps D5 to `RMIB_S.Se`, and excludes DASS and Persuasive. Canonical
output is UTF-8 LF-only, 1,148 bytes, SHA-256
`cdd6c3b89e9ee792db14a9b6878e10c2ddd50143224582c0c99d2bfd03b4d9a9`.
Seeder registration is immutable and mismatch rollback is all-or-nothing.
Independent Python 20/20, Feature 5/59, PHPStan, Pint, and diff checks passed;
focused disposable PostgreSQL passed 7/77 with cleanup. Result-ledger work is
still blocked on final case-aware session identity, not on source mapping.

The direct and legacy case-identity audits are now frozen without changing
production code. DIRECT_PUBLIC cases reuse the server-minted order ULID as the
case alias and require an explicit one-to-one order-to-case relation; DASS-only
orders remain outside the generic case graph. Historical direct backfill must
prove one exact order and exact package-entitlement composition or abort.
LEGACY_SELECTION has no valid ULID alias and therefore mints a new opaque case
ULID. Its historical package and intended-field snapshots remain NULL rather
than being inferred from mutable or non-unique configuration. New Selection
provisioning dual-writes the case, while replay must validate the exact graph
and reject the existing split-brain OR-lookup ambiguity. The legacy increment
is the sole active migration lane; direct schema work waits for it.

The case-aware session allocator audit confirms that production has only
start, autosave, and submit actions; session creation is still test-fixture
only and the public controller returns `SESSION_ENGINE_PENDING`. A new writer
must resolve case identity through the origin-specific grant chain (order for
DIRECT_PUBLIC, Selection mapping for LEGACY_SELECTION, assessment participant
for INTEGRATED), never by selecting one case from `participant_id`. The case is
the serialization root, grant-derived authorization/allocation identities make
retry stable, and a transaction must claim entitlement, create the case-bound
session, and start it atomically. Implementation remains blocked until direct
and legacy links exist and a versioned authority for duration/config/seed is
located; retest stays disabled without a distinct audited grant.

The session-definition audit found authoritative basic values only for IST
(nine ordered subtests totalling 4,320 seconds) and Kraepelin (50 columns at
15 seconds, 28 digits/27 answers, bottom-to-top unit-digit input). It did not
find sufficient authority for the IST ME learn/recall timer split, PAPI/RMIB
durations, the deterministic Kraepelin generator algorithm/version, licensed
item references, or immutable session-definition provenance. Existing F0 JSON
is scoring data and must not be silently repurposed. A pure typed validator may
be built with synthetic data, but no production catalog or allocator wiring is
accepted until those decisions are supplied.

The preparatory typed session-definition boundary is accepted across
`804a414` and adversarial repair `a5762bb`. It validates exact shapes with
synthetic data only, excludes DASS, requires fixed non-seeded definitions for
IST/PAPI/RMIB, and enforces the known Kraepelin 50-by-28/27-answer contract.
Every identity string rejects padding and control/Unicode whitespace aliases.
The supplied checksum is recomputed from all validated canonical content, so a
changed definition cannot reuse an old digest. Adversarial re-review found no
remaining P1/P2; combined AssessmentSessions passed 142 tests/338 assertions
with PHPStan and Pint green. This is not a production catalog and does not
resolve the authority gaps above.

LEGACY_SELECTION case identity is accepted across `7aaa00b`, typed test-helper
repair `5b11f0b`, race/immutability repair `6f3feb1`, and delete/race-evidence
repair `1200ba0`. Historical mappings receive opaque ULID cases with NULL
package and intended-field snapshots; new provisioning snapshots the validated
configured field. Composite identity, exact origin, replay graph, all durable
mapping fields, and deletion are database-guarded on PostgreSQL and SQLite.
Both same-key and different-key/same-candidate two-process races produce one
durable graph and one locked replay. Full disposable PostgreSQL passed 468
tests/5,189 assertions; coordinator gates and final adversarial review found no
remaining P1/P2.

The DIRECT_PUBLIC migration-readiness audit classifies packages only from
relational composition: exact `{dass21}` remains unbound; a main package must
contain DASS plus at least one supported non-DASS instrument. Historical
backfill must prove exact equality between package items and order-bound
entitlements, along with participant/package/source identity, or abort the
whole migration. Main cases alias the server-minted order ULID and use order
creation time; historical intended-field remains NULL. Migration `000600` is
the next sole serial lane, including strict guards, SQLite descendant
preservation, PostgreSQL boundary ordering, and explicit fixture adapters.

The pure F7 proctoring policy is accepted across `63b002f`, adjudication repair
`13a2847`, and gate-meaning repair `a92ffc2`. Camera failure/interruption,
screen departure, network interruption, and unreasonable timing establish a
minimum V2; proven invalidity establishes V3. Raw face/second-face/audio
signals remain pending human adjudication and block only the proctoring gate.
Confirmed substitution/assistance must reference compatible raw evidence and
carry adjudicator identity/token; dismissals resolve the pending gate but still
require an adjudication note. DASS is rejected. The decision explicitly does
not replace global G5 signing/publication authority. Final adversarial review
found no P1/P2; focused evidence passed 30 tests/113 assertions and full Unit
passed 1,101/3,043. Persistence, API, private storage, retention, and UI remain
later case/session-bound slices.

Read-only F7 commission and F9 audits are also frozen. Commission tables and
dashboard are absent; their exact implementation remains blocked on business
authority for rate/base/rounding, eligible organizations, period timezone,
free/refund handling, and payout/rejection rules. F9 is not launch-ready:
queue/cache have partial static evidence, while a real secret/PII scan gate,
retention executor, 50-user Kraepelin load gate, and backup/restore harness are
missing. Load and runtime cache/queue evidence wait for a stable allocator and
schema; no live environment or provider was touched.

DIRECT_PUBLIC case identity is accepted across `43a21f0`, duplicate-order and
lock-order repair `4f611c6`, and complete-entitlement repair `87c530e`. Main
orders alias one immutable case by the server-minted order ULID; exact DASS-only
orders remain unbound. Backfill requires one order per DIRECT_PUBLIC participant
and exact equality of every participant entitlement, order binding, and package
composition; partial, foreign, NULL-order, duplicate-order, or collision history
aborts with zero schema/data/RLS delta. Runtime replay validates the complete
graph in one locked transaction. Migration and replay share the lock order
participant, package/items, case, order, entitlement, with coordinated PG proof
against deadlock. Full disposable PostgreSQL passed 475 tests/5,258 assertions;
final adversarial review found no remaining P1/P2.

A post-link session audit still rejects universal `test_sessions` case NOT NULL.
Existing authorization/allocation strings are opaque and have no durable FK or
namespace to an order, Selection mapping, or integrated grant; multi-case history
therefore remains ambiguous and DASS-only intentionally has no generic case.
The next safe non-migration slice is a read-only origin-aware case authorization
resolver that fails closed on zero/multiple/mismatched grants. A production
session creator remains blocked on durable grant identity and complete immutable
session definitions.
