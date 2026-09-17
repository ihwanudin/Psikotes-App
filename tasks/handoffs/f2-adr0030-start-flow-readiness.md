# F2 ADR-0030 start-flow implementation readiness

Status: **read-only readiness freeze; production/HTTP wiring remains blocked**

Baseline: `a522f47` on `codex/organization-payment-spec` (2026-09-13)

Authority reviewed: `SPEC.md`, `SCORING_ALGORITHM.md`, `API_CONTRACT.md`,
accepted ADR-0030 and ADR-0031, the accepted F2 continuation handoff, and the
current session allocator, case resolver, multi-case policy, definition catalog,
grant schema, controller, route, architecture tests, feature tests, and
PostgreSQL tests. This report changes no route, shared contract, migration,
provider binding, feature flag, or production code.

## Readiness conclusion

The database and domain foundations are substantially present, but replacing
`SESSION_ENGINE_PENDING` is not yet safe. The existing
`AllocateAndStartAssessmentSession` already performs a single atomic
definition/session/grant/source transition and exact first-attempt replay, but
it predates the accepted multi-case boundary. Its history query is scoped by
participant plus instrument, and `CaseAuthorizationResolver` still requires one
global case/order/Selection graph. It does not build candidates or invoke
`AssessmentSessionSelectionPolicy`. A participant with multiple legitimate
cases therefore fails closed rather than selecting by ADR-0031. That is safe as
a temporary denial, but it is not an implementation of the accepted contract.

The public controller also still performs entitlement reads and implements
`RequiresRlsContext`; the route remains inside `participant.jwt` plus `rls`,
accepts `dass21`, and always returns 501 after its readiness check. Directly
calling the allocator from this controller would hit the exact participant-to-
service elevation conflict ADR-0030 was written to prevent.

The smallest trustworthy next vertical slice is therefore an **unwired typed
participant command plus case-candidate projection**, proven with synthetic
definitions. It must be accepted before the coordinator changes the shared
route/controller/request files.

## Existing evidence that can be reused

| Boundary | Existing implementation/evidence | What it proves | Remaining gap |
|---|---|---|---|
| Generic instrument input | `GenericAssessmentInstrument` | Only IST, PAPI, RMIB, and Kraepelin enter the generic domain; DASS-21 fails before SQL in allocator tests | Route/request still include DASS-21 |
| Case authorization | `CaseAuthorizationResolver` plus feature and PG concurrency tests | Exact single-case DIRECT_PUBLIC, LEGACY_SELECTION, and INTEGRATED source graphs; service transaction and lock requirements | No server-side multi-case candidate projection or selected-case revalidation |
| Multi-case decision | `AssessmentSessionSelectionPolicy` and its unit matrix | Replay precedence, ambiguity, scope mismatch, case+instrument history, no caller case selector | Not wired to database candidates or allocator |
| Definition boundary | `AssessmentSessionDefinitionAuthority`, immutable catalog/provider, snapshot schema | Exact active-row cardinality, checksum, provenance/version fields, Kraepelin per-session seed, fail-closed missing/invalid catalog | Catalog has no approved active definition rows |
| Atomic allocation | `AllocateAndStartAssessmentSession` and SQLite feature tests | Session+grant dual-write, source consumption, snapshot, deadline, replay, rollback, bounded SQLSTATE retries | Signature is pre-ADR, permits integrated principal, uses participant-scoped history, and has no allocator PG concurrency proof |
| Durable grants/RLS | `test_session_grants` migration and PG security tests | Origin-specific immutable grant identity, tenant/case integrity, FORCE RLS | Does not itself select one case from multiple ready cases |
| Pre-created start | `StartAssessmentSession` plus `AssessmentSessionStartActionConcurrencyTest` | Two workers serialize starting one already-created session | This is not allocation; it does not prove definition, case selection, grant insert, or source consumption races |
| HTTP | `StartParticipantSessionController`, request and authorization tests | Credential rechecks and sanitized pending/locked behavior | Middleware/controller shape contradicts ADR-0030 and returns 501 |

## Contract conflicts to resolve before implementation

### C1 — ADR result type collides with an existing, different result

ADR-0030 declares:

```php
execute(
    ParticipantPrincipal $principal,
    GenericAssessmentInstrument $instrument,
): AssessmentSessionStartResult
```

`AssessmentSessionStartResult` already belongs to `StartAssessmentSession`, the
older action for starting or replaying an existing public session ID. It is a
nullable success/error envelope. The accepted allocator instead returns the
strict successful `AssessmentSessionAllocationResult`, including the validated
definition, duration, deadline, and answers revision required by
`API_CONTRACT.md`.

The coordinator must freeze one of these before code ownership is assigned:

1. correct/supersede ADR-0030 to return `AssessmentSessionAllocationResult`, and
   map domain exceptions only at the HTTP boundary; or
2. define a new, unambiguous command result type and an explicit mapping from
   allocation success/failure.

Do not silently repurpose the existing `AssessmentSessionStartResult`; doing so
would change the already accepted pre-created-session contract.

### C2 — HTTP error mapping is not yet authoritative

The API contract defines the success payload but not status/code mapping for
definition unavailable, multi-case ambiguity, stale source authority, or an
expired replay. Before removing 501, freeze a non-enumerating mapping. At
minimum, foreign/missing/revoked/stale graph failures must share one safe 403 or
404 response, ambiguity must not expose case identities, and missing catalog
must be a retry-safe service-unavailable response rather than a 500.

### C3 — integrated credentials are outside this route boundary

The existing allocator accepts `AssessmentPrincipal|ParticipantPrincipal`, but
ADR-0030 freezes the public participant command to `ParticipantPrincipal` and
states that integrated authentication needs a separate decision. The first
command must not preserve the union merely because the internal allocator has
synthetic integrated tests. Retain integrated allocation logic as unwired
internal capability or move it behind a later command; do not add it to the
participant route.

## Four-instrument authority manifest boundary

The immutable catalog and fail-closed database provider are implementable now,
and all command/selection/rollback behavior can be tested with injected
synthetic definitions. A missing active row already throws
`AssessmentSessionDefinitionUnavailable`; an invalid or duplicate active row
throws `InvalidAssessmentSessionDefinitionCatalog`. This is the correct closed
default and does not require inventing psychometric content.

Successful real new-session allocation remains hard-blocked for **all 4/4**
instruments until a psychologist-approved manifest supplies one immutable
version/provenance entry per instrument:

| Instrument | Known structural authority | Manifest blockers |
|---|---|---|
| IST | Nine ordered subtests; 4,320 seconds total | Approved definition version/provenance, exact ME learning/recall timer split, and licensed item/reference inventory |
| PAPI | Fixed, non-seeded definition shape | Approved version/provenance, total/subtest duration, and licensed item/reference inventory |
| RMIB | Fixed, non-seeded definition shape | Approved version/provenance, total/subtest duration, and licensed item/reference inventory |
| Kraepelin | 50 columns, 15 seconds each, 28 digits/27 answers, bottom-to-top unit-digit input | Approved version/provenance and one exact deterministic generator algorithm/version resolving seeded-versus-fixed authority |

Scoring data under `database/seeders/data` and ADR-0028 normalization rules are
not session item/timer authority and must not be imported into the catalog by
inference. No active definition row, seed, or production-success fixture may be
created before the manifest is approved.

## Exact implementation sequence

Each increment is intentionally small. Shared route/controller/request changes
remain coordinator-owned and occur only after S1–S4 pass review.

### S0 — coordinator contract checkpoint (documentation only)

Freeze C1 and C2, keeping the API success fields from `API_CONTRACT.md` and the
four-instrument/DASS separation. No runtime change.

Acceptance:

- one unambiguous command return type;
- one sanitized exception-to-HTTP matrix;
- integrated credentials explicitly excluded from this route.

### S1 — database candidate projection, still unwired

Likely files (2–3):

- new `app/Services/AssessmentSessions/ParticipantAssessmentSessionCandidates.php`;
- new focused feature test;
- only if necessary, a narrow typed projection value object under
  `app/Domain/AssessmentSessions/`.

The service must run only in an existing service transaction. It derives origin
from the persisted participant, locks all matching case+instrument source grants
in canonical order, joins durable live session grants, and emits candidates for
`AssessmentSessionSelectionPolicy`. No request/caller case ID is accepted. The
selected candidate retains an internal exact case/source identity for the
resolver recheck; terminal history never becomes retest authority.

Acceptance:

- zero, one, and multiple legitimate cases project losslessly;
- foreign tenant/origin/instrument rows fail closed rather than being filtered
  into a misleading unique candidate;
- direct and legacy graphs remain distinct; DASS-21 is structurally impossible.

### S2 — selected-case resolver recheck

Likely files (2):

- `app/Services/AssessmentSessions/CaseAuthorizationResolver.php`;
- its focused feature test.

Add an internal typed entrypoint that accepts only the trusted selected
candidate/value object from S1, never a raw/caller case ID. Re-lock and recheck
the exact case, order or Selection row, entitlement, package composition, tenant,
participant, origin, and instrument. A candidate changed while waiting on locks
must reject without writes. Preserve existing single-case resolver entrypoints
until their callers are migrated and reviewed.

### S3 — ADR-0030 participant command and allocator integration

Likely files (3–4):

- one typed application command, named after the S0 decision;
- `AllocateAndStartAssessmentSession.php` refactored as an internal
  within-service-transaction allocator;
- focused command/allocator feature tests;
- result adapter only if S0 selects a new type.

The public command rejects any existing RLS context **or transaction before any
SQL**, owns the bounded retry loop and the outer `runAsService` transaction, then
performs S1 selection, S2 recheck, definition issue, session+grant insert, source
transition, and start in that same transaction. Its signature accepts only
`ParticipantPrincipal` and `GenericAssessmentInstrument`. The allocator history
key changes from participant+instrument to selected case+instrument. Replay is
loaded through the selected durable grant and stored definition snapshot; it
never calls the catalog again or extends `ends_at`.

Do not implement this as a thin controller closure that can elevate arbitrary
participant code, and do not leave the old container-resolvable allocator as an
alternate unguarded public entrypoint.

### S4 — PostgreSQL authority and race evidence

Likely files (1–2 dedicated PG tests; no migration):

- new allocator/command concurrency test;
- extend a session RLS test only if the assertion cannot live in the new file.

Run on a disposable database as runtime `NOBYPASSRLS`. Required cases are in
the evidence matrix below. Full PG must be green before HTTP wiring.

### S5 — coordinator-owned HTTP cutover

Likely shared files:

- `routes/api.php`;
- `StartParticipantSessionController.php`;
- `StartAssessmentSessionRequest.php`;
- authorization/HTTP and architecture tests.

Move only the start route to `participant.jwt` without `rls`; keep every other
participant session route unchanged. Remove `RequiresRlsContext` from this
controller, inject only the typed command, validate/convert the route instrument,
and emit the S0 response. The route and request allow exactly
`ist|papi|rmib|kraepelin`; DASS-21 and every body/query scope selector are
rejected. Static architecture tests must forbid DB facade, Eloquent models,
`RlsContextRunner`, entitlement gates, generic callbacks, and service-elevation
code in the controller.

Do not cut over while the definition manifest is absent unless S0 explicitly
accepts a default-closed public 503 and the full command/PG proof is green. A
synthetic fake definition is never acceptable production wiring.

## Required evidence matrix

| Axis | Required proof before S5 | Existing evidence reusable? |
|---|---|---|
| Contract/input | Typed participant principal and enum; no case, attempt, origin, grant, duration, seed, timestamp, or definition from request; DASS rejected before SQL | Partial: enum/request selector tests exist |
| Selection | Unique ready case selected; one live grant-bound in-progress session replays first; multiple live is `history_ambiguous`; multiple ready is `selection_ambiguous`; terminal history unavailable | Pure policy only; database projection missing |
| Concurrency | Two same-principal/instrument requests yield one new session/grant and one exact replay; opposing source/case mutation yields one winner or closed rejection; no duplicate definition issue becomes durable | Existing PG test covers only pre-created start, so new evidence required |
| Rollback | Inject failure after definition issue, session insert, grant insert, source transition, and final start; every case leaves source ready and zero session/grant partial rows; context and transaction level reset | SQLite covers late source/final failures only; full phase matrix missing |
| Retry | Only SQLSTATE `40001` and `40P01` restart the whole outer transaction, maximum three attempts; no nested/partial state | SQLite synthetic evidence exists; PG race evidence still required |
| PostgreSQL RLS | Direct no-context and participant-context session/grant writes denied; command succeeds only through service context; runtime role is non-owner/NOBYPASSRLS; FORCE RLS remains enabled | Table security evidence exists; command path proof missing |
| Revocation/staleness | JWT/principal accepted earlier, then entitlement/case/source/package/tenant changes before lock cause safe rejection and zero writes | Gate/resolver evidence partial; command transaction proof required |
| Definition | Missing/duplicate/invalid catalog is sanitized and leaves zero writes; replay uses immutable stored snapshot; new Kraepelin session receives only server-issued seed | Provider/snapshot tests exist; command rollback/mapping required |
| HTTP | Route has `participant.jwt` and no `rls`; controller has no persistence/elevation dependencies; four exact instruments only; success payload is UTC and complete; errors do not enumerate foreign records | Current route/controller contradict this; new tests required |
| Cleanup | Success and every exception leave `RlsContextRunner::current()` null and no transaction; disposable PG resources removed by exact label/name | Partial allocator retry proof exists |

## Stop conditions

- Do not edit migrations: case identity, case-scoped entitlement uniqueness,
  definition catalog, snapshots, and durable grants already exist.
- Do not use FIFO, newest-case, or caller `case_id` selection.
- Do not authorize retest; ADR-0031 lists its missing business authority.
- Do not route integrated `AssessmentPrincipal` through the participant command.
- Do not seed or activate real definitions without the approved four-entry
  authority manifest.
- Do not remove `SESSION_ENGINE_PENDING` until S0–S4 are accepted and the S5
  cutover is reviewed as one coordinator-owned change.

## Recommended next assignment

Assign S1 alone to the F2 session-backend lane with exclusive ownership of the
new candidate projection and its new focused test. In parallel, the coordinator
can resolve S0 without overlapping production files. S2 follows only after the
S1 projection type is frozen; S3 follows S2; S4 follows S3; S5 remains serial
coordinator integration.
