# F2 authoritative result persistence readiness

Status: **read-only architecture freeze; no authoritative result writer exists**

Committed baseline inspected: `5afcc1e` (`79390f3` ADR-0030 checkpoint plus
accepted four-instrument manifest audit `5afcc1e`). Concurrent untracked files
in the shared worktree are not acceptance evidence and were not read or changed.

This report inspects the committed session, scoring, result-delivery, F3, F4,
and F5 boundaries plus the preserved `result-contract` and
`result-persistence` worktrees. It does not change production code, schema,
routes, contracts, configuration, seed data, activation state, or checklists.

## Conclusion

The repository can durably create, autosave, and submit a case-bound generic
session, and it has accepted pure scoring calculators. It cannot yet produce an
authoritative per-instrument result. Submission only changes the session from
`in_progress` to `submitted`; there is no sealed-answer snapshot/checksum,
definition-aware completeness check, scoring orchestrator, normalized result
DTO, or case/session-bound immutable result ledger.

The existing `generic_assessment_result_versions` graph is not that ledger. It
is a downstream Selection integration projection containing only an assessment
attempt, IQ, engine version, finality, version, and checksum. Its own handoff
states that the `authorizedSnapshot` parameter is a future caller contract, not
proof of scoring authority. It has no binding to `assessment_cases`,
`test_sessions`, the frozen session definition, the sealed answer revision or
checksum, the scoring-data row/checksum, the instrument, or normalized source
rows. Its migration also adds append-only triggers but no PostgreSQL RLS, FORCE
RLS, ACL, or tenant policy. It must remain a downstream IST-IQ adapter and must
not be used as the source of truth for F2/F3/F4/F5.

Real runtime scoring remains blocked for all four instruments by the accepted
manifest audit: import-ready **0/4**, start-ready **0/4**. This does not block a
synthetic, unwired sealed-answer boundary or its tests. It does block active
catalog rows, participant-visible definitions, and any production result claim.

## Exact authority path and present state

| Step | Required authoritative transition | Committed evidence | Classification and gap |
|---|---|---|---|
| 1. Case and grant | One trusted case and origin grant select one instrument session. | Nullable-but-immutable session case identity in `database/migrations/2026_09_09_000400_harden_test_session_case_identity.php`; durable grant graph; ADR-0030/0031; allocator `app/Actions/AssessmentSessions/AllocateAndStartAssessmentSession.php`. | **Accepted foundation.** New allocator rows carry exact case identity, but the allocator still has participant+instrument history logic and the public start cutover is not accepted. Synthetic pre-bound fixtures are sufficient for an unwired result slice. |
| 2. Definition freeze | The session pins participant-visible definition version, provenance, checksum, payload, timing, order, and Kraepelin seed/generator. | `SessionDefinition`, definition catalog/provider, `database/migrations/2026_09_10_000100_add_test_session_definition_snapshots.php`, and allocator snapshot fields. | **Accepted for synthetic definitions.** Real definitions are blocked 0/4 by `tasks/handoffs/f2-four-instrument-authority-manifest-audit.md`. Scoring JSON is not item/timer authority. |
| 3. Answer capture | Writes are session-scoped, revisioned, replay-safe, and permitted only before the server deadline. | `answers`, `assessment_autosave_mutations`, database guards, and `AutosaveAssessmentAnswers`; accepted commits `bf72c29`, `2b3937a`, `4a73141`, and `c070ba2`. | **Accepted for IST/PAPI/RMIB-shaped generic batches.** The action currently accepts all generic enum values, while `API_CONTRACT.md` reserves Kraepelin for `/events`; no `kraepelin_events` persistence/ingest exists. Values also are not yet interpreted against the exact definition. |
| 4. Seal | A submitted session makes its answer set immutable and replayable. | `SubmitAssessmentSession` (`87b6346`) locks participant+session and changes `in_progress` to `submitted`; answer triggers reject later writes. | **Partially accepted.** The status/replay transition is sound, but submit does not validate exact item coverage, materialize an answer-set checksum, queue scoring, or atomically reach the API contract's promised `scored` state. |
| 5. Sealed source snapshot | A scorer reads one exact case/session/instrument/definition/revision and a canonical ordered answer/event set. | No committed application boundary. | **Missing.** There is no fail-closed reader, canonical answer checksum, completeness proof, or persistent scoring idempotency key. This is the first missing authority edge. |
| 6. Per-instrument scoring | The appropriate pure calculator consumes the sealed source and exact versioned scoring data. | Accepted pure services under `app/Services/Scoring`; `instrument_versions` contains version/source/checksum/payload and PostgreSQL append-only/FORCE-RLS guards; ADR-0028 freezes normalization precedence. | **Domain accepted, orchestration missing.** Calculators return heterogeneous arrays and no service pins both the session-definition identity and exact `instrument_versions.id/version/checksum` while invoking them. |
| 7. Normalized result | One strict DTO records raw/audit values and canonical normalized source levels without losing instrument evidence. | `database/seeders/data/aspect_sources.json` (`07eaa98`) freezes version `ASPECT-SOURCES-2026.09`, 18 aspects, and 42 ordered associations. | **Missing DTO and assembler.** The association file is composition authority, not a persisted score. Duplicated associations must reference one canonical source result, not duplicate or recompute scores. |
| 8. Immutable result persistence | An append-only parent binds case, session, participant, instrument, sealed-source checksum, session-definition identity, scoring-data identity, result contract version, completion time, and result checksum; children store exact normalized source rows. | Earlier freeze `bf0bff5` describes this graph. | **Missing.** No table, writer, replay rule, correction model, RLS policy, or PostgreSQL concurrency proof exists. A schema must follow, not precede, the sealed source and normalized DTO contracts. |
| 9. Aspect composition | The 42 versioned associations derive exactly A1-D5 from stored source rows. | `aspect_sources.json`; pure F3 calculators and G7 discrepancy policy. | **Partially accepted.** No immutable 18-aspect composition/version snapshot binds the source-result IDs/checksums and mapping row/checksum. |
| 10. F3/F4/F5 handoff | Eligibility, bilingual narrative, review, override, and signing consume immutable versioned evidence. | F3 pure guardrails; F4 deterministic composers; F5 state machine, reviewed decision, override, and signing prerequisite/snapshot primitives. | **Domain accepted, persistence/runtime missing.** None is yet wired to the authoritative case result graph; HTTP, psychologist UI, and browser evidence remain open. |

## Identity, version, and provenance contract that must survive end to end

The eventual authoritative result must preserve these identities without
deriving them later from mutable or merely active rows:

1. case: `assessment_case_id` and its participant/organization binding;
2. session: internal `test_sessions.id`, public ULID, instrument, attempt number,
   `submitted_at`, and final `answers_revision`;
3. administration definition: the session's exact
   `session_definition_version`, `session_definition_provenance`,
   `session_definition_checksum`, and canonical payload, including the issued
   Kraepelin seed/generator when applicable;
4. sealed response source: ordered canonical items/events plus one
   domain-separated SHA-256 checksum;
5. scoring source: exact `instrument_versions.id`, code, version, source file,
   checksum, and a result-contract/scorer version; never a later active-row
   lookup;
6. per-instrument result: an immutable result ID/version/checksum and exact
   source rows needed for audit and downstream normalization;
7. composition: exact `aspect_sources` row/version/checksum and the ordered
   source-result identities used to produce each A1-D5 value.

`F2-2026.09` alone is insufficient provenance: several instruments share that
string, while the immutable database identity and checksum distinguish their
payloads. Session-definition provenance and scoring-data provenance are also
different authorities and must remain separate fields.

## Replay and idempotency assessment

Existing replay controls are useful but stop before scoring:

- allocation replays an existing session/grant, although ADR-0031 case-aware
  history wiring remains in progress;
- autosave uses unique `(session_id, mutation_id)`, a request hash, exact next
  revision, and unique `(session_id, item_no)`;
- submit safely replays `submitted`/`scored` state;
- the Selection IQ result store replays an identical result-version checksum
  and enforces a linear correction/revocation chain.

The missing scoring key must be derived from stored authority, not caller data.
The minimum identity is:

`session_id + assessment_case_id + instrument + answers_revision +`
`sealed_source_checksum + session_definition_checksum +`
`scoring_source_id + scoring_source_checksum + result_contract_version`.

An exact replay returns the same immutable result. Any equal identity with a
different payload/checksum is corruption and fails closed. A session may not
produce two initial results. Corrections/revocations, if product-authorized
later, require a separate append-only version chain and must never rewrite the
sealed source or silently select a newer scoring configuration. ADR-0031 leaves
retest/result-selection authority unresolved, so this increment must not invent
correction or retest behavior.

## PostgreSQL and RLS readiness

The committed session, answer, mutation, grant, case, definition-catalog,
definition-snapshot, and scoring-version foundations have PostgreSQL guards and
service-context evidence recorded in the F2 integration reports. A future
result ledger must preserve the same standard:

- runtime role is `NOBYPASSRLS`;
- `ENABLE ROW LEVEL SECURITY` and `FORCE ROW LEVEL SECURITY` on parent and child;
- no PUBLIC privileges or grant option; minimum service insert/select authority;
- immutable identity and UPDATE/DELETE denial in the database;
- composite foreign keys proving case/session/participant/instrument ownership;
- unique initial-result/idempotency constraints plus canonical checksum checks;
- populated downgrade refusal without temporarily weakening RLS/ACL;
- disposable PostgreSQL tests for direct-write denial, cross-tenant/cross-case
  denial, exact replay, checksum conflict, concurrent double score, unchanged
  migration rerun, and populated down/no-partial-mutation.

The current `generic_assessment_result_versions` migration does not meet this
RLS/identity shape and is therefore not a reusable persistence parent. Its
projector/outbox/poll/callback chain may consume a reviewed IST IQ projection
later, after an authoritative result exists.

## DASS separation

DASS-21 remains bundled operationally but is not a generic instrument and must
not enter this ledger or the A1-D5 graph. `GenericAssessmentInstrument` excludes
it; `aspect_sources.json` contains no DASS source; accepted T-07 proves the pure
eligibility domain ignores DASS. The persistent boundary is still incomplete.

Required separation for future work:

- DASS keeps its own session/answer/result policy and storage;
- no DASS FK, source key, score, severity, screening flag, or consent state is
  accepted by the generic sealed-answer DTO, instrument result, 18-aspect
  composition, eligibility, generic IQ projection, or signing source set;
- bundled completion may coordinate workflow status only; it may not merge
  scoring payloads or alter eligibility;
- separate tests must prove both absence of DASS fields and unchanged generic
  result/checksum when DASS evidence varies.

## Preserved worktree audit

| Worktree | Head | Finding | Decision |
|---|---|---|---|
| `D:/LSI/Web/Psikotes-worktrees/result-contract` | `06a17101` | Pure generic Selection envelope work. `git cherry` marks `3b78bf5` and `06a17101` patch-equivalent to changes already on this baseline (`29b28d0`, `4b754ca`). It carries no per-instrument authority. | **Stale/preserved; do not cherry-pick.** Reuse only as a downstream adapter after authoritative IST scoring. |
| `D:/LSI/Web/Psikotes-worktrees/result-persistence` | `93826263` | The common history through `67e6255` is already on main. `git cherry` also marks `93826263` patch-equivalent on main. Its change is Compose/runtime-worker documentation/testing, not result authority. Main has subsequent retention and integration hardening. | **Stale/preserved; do not cherry-pick.** No missing authoritative result code is hidden here. |

No branch-only result-contract or result-persistence commit is required for this
lane. Cherry-picking either worktree would regress current files or duplicate
already integrated patches.

## Smallest dependency-unblocked implementation increment

Freeze **R0: sealed generic answer-set authority**, unwired and restricted to
IST, PAPI, and RMIB. Do not create a result migration or call a scorer yet.

Exclusive likely ownership (new files only):

- `app/Domain/AssessmentResults/SealedGenericAnswerSet.php`;
- `app/Services/AssessmentResults/LoadSealedGenericAnswerSet.php`;
- `tests/Feature/AssessmentResults/LoadSealedGenericAnswerSetTest.php`.

Exact contract:

1. The loader is internal-only, receives a trusted positive internal session ID,
   and may run only inside an existing service-context transaction. It accepts no
   participant/case/definition/revision/checksum supplied by HTTP or queue data.
2. It locks/loads the session and requires status `submitted` or `scored`, a
   non-null exact `assessment_case_id`, and a complete definition snapshot. It
   reconstructs `SessionDefinition::fromArray()` from the persisted payload and
   proves the duplicated version/provenance/checksum/instrument fields match.
3. It accepts only `ist|papi|rmib`. DASS and Kraepelin fail before an answer
   query. Kraepelin remains blocked until its dedicated event ledger/ingest and
   canonical event snapshot are designed per `API_CONTRACT.md`.
4. It loads `answers` once in ascending `item_no`, requires exact unique coverage
   `1..sum(definition.subtests[*].item_count)`, requires every row revision in
   `1..session.answers_revision`, valid JSON without non-finite values, and no
   unknown/missing item. It does not require all rows to share the latest
   revision because unchanged answers legitimately retain an earlier autosave
   revision.
5. The readonly DTO exposes only stored authority: case/session/participant IDs,
   public session ULID, instrument, attempt number, submitted time, final answer
   revision, the full validated definition identity/payload, and ordered
   `{item_no,value,revision,answered_at}` rows.
6. It computes `sourceChecksum = sha256("sealed-generic-answers:v1|" + canonical
   JSON)` over all exposed authority except the checksum itself. Object keys are
   recursively sorted, list order is retained, JSON uses unescaped Unicode and
   slashes plus preserve-zero-fraction, and timestamps are canonical UTC with
   microseconds. A stored JSON number must not be silently coerced to another
   numeric representation.
7. Two reads of the same sealed session are byte-identical. Missing/extra items,
   malformed/corrupt definition or answer JSON, unbound session, open/expired/
   void session, instrument mismatch, revision outside the sealed range, DASS,
   or Kraepelin all fail closed without result writes or raw-answer logging.

Synthetic definitions make R0 fully testable now and do not relax the 0/4 real
manifest block. The test must include exact replay, partial batch across several
autosave revisions, one corrupt/ambiguous case per invariant, transaction/context
preconditions, query-count evidence (one session read plus one ordered answer
read), and privacy-safe exceptions. SQLite is sufficient for the pure loader
feature slice; the later writer/schema slice requires disposable PostgreSQL.

## Ordered follow-up after R0

1. **R1, one IST vertical contract:** adapt the sealed snapshot plus exact IST
   `instrument_versions` row into existing calculators and a strict immutable
   per-instrument result DTO. Pin raw/SW/IQ/level evidence and checksums; use only
   synthetic definition/content and accepted scoring fixtures.
2. **R2, serial PostgreSQL ledger:** persist the frozen R1 parent and normalized
   child shape with append-only FORCE-RLS constraints and exact replay. Do not
   reuse `generic_assessment_result_versions` as the parent.
3. **R3, aspect composition:** persist a versioned A1-D5 projection referencing
   the R2 source rows and exact `aspect_sources` version/checksum. Do not copy
   DASS or silently collapse multiple source associations.
4. **R4, downstream adapters:** feed the accepted F3 decision/guardrail domain,
   F4 composers, and F5 reviewed/signing snapshots from immutable R2/R3 IDs.
   Only a reviewed finalized IST IQ may be projected to the existing generic
   Selection result store/outbox/poll/callback chain.
5. **Parallel blocked path:** design Kraepelin event persistence/sealing and the
   separate DASS authoritative lifecycle without sharing R0/R2 tables.

## Stop conditions

- Do not seed or activate a real definition, use supporting workbooks as implied
  approval, or invent psychological values.
- Do not change submit to report `scored` until scoring plus immutable persistence
  commits atomically or a durable recovery job is proven.
- Do not infer scoring authority from session status, an active config row, the
  `authorizedSnapshot` parameter name, or an existing Selection IQ row.
- Do not hardcode the 42 associations in PHP or a migration.
- Do not add DASS to the generic enum, answer reader, result ledger, eligibility,
  or generic result transport.
- Do not wire routes, queue workers, scheduler, callback/poll activation, or
  production catalog data in R0-R2.

## Static evidence

- Authority: `SPEC.md`, `SCORING_ALGORITHM.md`, `API_CONTRACT.md`, PRD 1.3
  FR-05/06/08/08b and go-live prerequisites, ADR-0028/0030/0031,
  `tasks/parallel-work.md`, `tasks/plan.md`, `tasks/todo.md`, the canonical
  F2-F9 acceptance matrix, latest integration reports, and the accepted
  four-instrument manifest audit.
- Code/schema: committed session/answer/grant/case/definition migrations and
  actions, all scoring services and data files, generic Selection result
  projector/store/migration/transport, and F3/F4/F5 domain consumers.
- Git: both preserved result worktrees, ancestry, patch equivalence (`git
  cherry`), committed file differences, and main history were inspected without
  checkout, cherry-pick, or mutation.
- Verification is documentation/static only. No database, container, `.env`,
  participant data, provider, outbound action, route, feature flag, or active
  resource was used.
