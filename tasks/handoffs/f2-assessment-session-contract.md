# F2 Assessment Session Contract Proposal

Status: **Proposed — requires coordinator review before implementation**

Scope: generic IST, PAPI, RMIB, and Kraepelin assessment sessions

Authority: `SPEC.md`, `API_CONTRACT.md`, `DATABASE_SCHEMA.md`, `SECURITY.md`, and accepted ADRs

This document freezes a proposed boundary for the F2 session lane. It is not an
amendment to the canonical API or schema documents and authorizes no migration,
route, or production change. Implementation must wait until the coordinator
accepts the contract and records any required canonical amendments.

## Non-negotiable boundaries

- The generic session engine supports only `ist`, `papi`, `rmib`, and
  `kraepelin`.
- DASS-21 never uses generic `test_sessions`, `answers`, generic answer actions,
  or generic session RLS policies. It continues to use the existing isolated
  `dass.assessments`, `dass.responses`, and `dass.results` tables (and the
  `dass_*` SQLite test equivalents), including its separate consent, access,
  scoring, reporting, and two-year retention rules.
- `participant_id`, test type, entitlement, duration, item configuration,
  randomization seed, timestamps, deadline, and attempt number are established
  by trusted server state. None is accepted from an untrusted request as
  authority.
- PostgreSQL is the acceptance engine. SQLite tests are useful for fast domain
  feedback but cannot prove RLS, PostgreSQL constraints, locking, or rollback.

## Session and attempt model

### State machine

The persisted generic session states are:

| State | Meaning | Permitted successor |
|---|---|---|
| `created` | Attempt allocated after entitlement and one-attempt checks | `in_progress`, `void` |
| `in_progress` | Server deadline fixed; answers may be accepted within the write window | `submitted`, `expired`, `void` |
| `submitted` | Answer set sealed; scoring may be completed or safely retried | `scored`, `void` only by an authorized recovery decision |
| `scored` | Scoring result durably committed | none |
| `expired` | Deadline/grace elapsed without successful submit | `void` only by an authorized administrative decision |
| `void` | Attempt administratively invalidated with an audit reason | none |

`scored`, `expired`, and `void` are terminal for participant actions. A client
cannot restart or submit any terminal session. `submitted` is immutable to the
participant and is terminal with respect to answer writes.

All transitions use a row lock or an equivalent conditional update in one short
transaction. Identity, test type, attempt number, `started_at`, and `ends_at`
are immutable after creation; replaying start cannot extend the deadline.

### Exact deadline and grace rule

Let `received_at` be the authoritative database/server receipt instant and let
`write_deadline = ends_at + 10 seconds`.

- An answer batch is eligible only when the locked session is `in_progress` and
  `received_at <= write_deadline`.
- A batch received at exactly `write_deadline` is eligible. Any batch with
  `received_at > write_deadline` is rejected atomically with no answer, revision,
  or mutation-ledger write.
- Client timestamps never extend or establish this window.
- Once the server observes `received_at > write_deadline`, it conditionally
  transitions an unsubmitted `in_progress` session to `expired`. A scheduler may
  perform the same transition, but correctness must not depend on the scheduler
  running first.
- Submit locks the session and applies the same boundary. A submit received
  after `write_deadline` cannot manufacture a late successful attempt; it
  transitions the session to `expired` and returns the stable deadline error.

The response `server_time`, `ends_at`, `write_deadline`, and
`remaining_seconds` are informational projections of server state. The browser
timer is never authoritative.

### One attempt and retest semantics

- Attempt allocation is bound to the participant, test type, entitlement, and
  an immutable monotonically increasing `attempt_no`.
- At most one `created` or `in_progress` session may exist for a participant and
  test type. The invariant must be protected by a PostgreSQL unique/partial
  constraint, not a check-then-insert query.
- `submitted`, `scored`, and `expired` consume the allocated attempt. Merely
  leaving the active partial index must not authorize another attempt.
- A retest requires an explicit authorized admin void/retest decision, an audit
  reason, and a new server-issued entitlement/attempt authorization. Voiding a
  row alone does not silently create entitlement.
- Concurrent initial allocation or retest allocation has exactly one winner.
  Losing requests replay the same session when they represent the same intent,
  or return `ATTEMPT_ALREADY_EXISTS`/`RETEST_NOT_AUTHORIZED` as applicable.
- Session history is never physically deleted by participant or admin flows.

## Autosave contract

### Request

`POST /sessions/:id/answers` retains the existing route and batch shape, with
the following contract fields proposed for the canonical API freeze:

```json
{
  "mutation_id": "01J...ULID",
  "revision": 7,
  "items": [
    {"item_no": 12, "value": "A"},
    {"item_no": 13, "value": {"rank": 3}}
  ]
}
```

- `mutation_id` is generated once for one logical batch and reused unchanged
  for every transport retry.
- `revision` is a positive, session-scoped monotonic integer. The first accepted
  batch is revision 1; a new batch is accepted only when it is exactly the
  current accepted revision plus one.
- A batch is non-empty, has bounded item count and encoded size, contains no
  duplicate `item_no`, and is validated against the immutable instrument item
  definition. Canonical request hashing includes session ID, revision, and the
  normalized ordered item/value payload.
- A unique `(session_id, mutation_id)` ledger claim, request hash, revision
  advance, item upserts, and stored receipt are committed atomically while the
  session is locked.

### Replay and ordering behavior

| Condition | Outcome |
|---|---|
| New mutation, `revision = current + 1` | Apply all items and advance revision once |
| Same mutation and same request hash | Return the originally stored receipt; perform no writes |
| Same mutation with a different hash | `MUTATION_PAYLOAD_MISMATCH` |
| Different mutation with `revision <= current` | `AUTOSAVE_STALE_REVISION`; never overwrite newer answers |
| `revision > current + 1` | `AUTOSAVE_REVISION_GAP`; client reloads session state before retrying |
| Concurrent requests for the same next revision | Exactly one commits; the other re-evaluates as replay or conflict |
| Closed session or late receipt | No answer, revision, or mutation-ledger write |

Successful receipt DTO:

```json
{
  "session_id": "01J...ULID",
  "mutation_id": "01J...ULID",
  "revision": 7,
  "accepted_item_nos": [12, 13],
  "received_at": "2026-09-08T03:00:05.000000Z"
}
```

The stored receipt makes retry behavior deterministic. Server receipt time is
evidence, not an ordering mechanism; monotonic revision prevents a delayed old
retry from overwriting a newer answer.

## Submit contract

`POST /sessions/:id/submit` remains safe to repeat and does not accept a client
participant ID, deadline, status, score, or result.

- The session row is locked, the deadline rule is applied, and the answer set is
  sealed exactly once.
- The unique submission intent is derived from the immutable session identity;
  concurrent submit requests cannot create multiple score/result records.
- First success persists `submitted_at`, the sealed `answers_revision`, scoring
  provenance, and the resulting response before returning.
- Replaying submit for the same `submitted`/`scored` session returns the same
  stored outcome and timestamps without rewriting answers or scoring twice.
- If a crash leaves the state `submitted`, retry resumes or claims scoring
  idempotently. It does not reopen answers or create another submission.
- Completeness/validity failures are durable scoring/validity outcomes; they
  never justify accepting late answers. A V3 result remains unable to publish.

The existing canonical success expectation is preserved:

```json
{
  "session_id": "01J...ULID",
  "status": "scored",
  "submitted_at": "2026-09-08T03:04:59.000000Z",
  "answers_revision": 7
}
```

If F2 scoring is separated into an asynchronous operation, that would change
the observable `status: scored` contract and therefore requires a dedicated
coordinator-owned canonical API amendment before implementation.

## Stable DTOs

All IDs exposed to clients are public ULIDs, never database sequence IDs. Times
are UTC RFC 3339 strings with an explicit offset.

### `AssessmentSession`

```text
session_id: ULID
test_type: ist | papi | rmib | kraepelin
status: created | in_progress | submitted | scored | expired | void
attempt_no: positive integer
started_at: timestamp|null
ends_at: timestamp|null
write_deadline: timestamp|null
submitted_at: timestamp|null
server_time: timestamp
remaining_seconds: non-negative integer
answers_revision: non-negative integer
config: instrument-specific immutable object
seed: opaque string|null (Kraepelin only)
```

`remaining_seconds` is zero outside `in_progress` and is clamped to zero. The
generic DTO never contains DASS response, result, consent, or retention fields.

### Error envelope and codes

Every failure uses:

```json
{
  "error": {
    "code": "STABLE_MACHINE_CODE",
    "message": "Safe human-readable message",
    "details": {}
  }
}
```

| HTTP | Code | Meaning |
|---:|---|---|
| 401 | `UNAUTHENTICATED` | Participant bearer authentication failed |
| 403 | `ENTITLEMENT_NOT_READY` | Required entitlement is not ready |
| 403 | `RETEST_NOT_AUTHORIZED` | A consumed attempt has no approved retest authorization |
| 404 | `SESSION_NOT_FOUND` | Missing or inaccessible session; cross-tenant existence is not disclosed |
| 409 | `ATTEMPT_ALREADY_EXISTS` | Another attempt/session already owns the invariant |
| 409 | `SESSION_NOT_STARTED` | Operation requires `in_progress` |
| 409 | `SESSION_CLOSED` | Session is submitted, scored, expired, or void |
| 409 | `DEADLINE_EXCEEDED` | Server receipt is later than the inclusive grace deadline |
| 409 | `AUTOSAVE_STALE_REVISION` | A different mutation uses an already-consumed revision |
| 409 | `AUTOSAVE_REVISION_GAP` | Revision skips one or more unacknowledged batches |
| 422 | `MUTATION_PAYLOAD_MISMATCH` | Existing mutation ID was reused with different canonical content |
| 422 | `INVALID_ANSWER_BATCH` | Batch shape, bounds, item identity, or value is invalid |
| 422 | `INVALID_SESSION_TRANSITION` | Requested state transition is not in the state machine |

Unexpected database or scoring details are never returned. Authorization checks
occur before distinguishing missing from forbidden tenant resources.

## Existing endpoint mapping

| Existing endpoint | Contract behavior |
|---|---|
| `POST /api/sessions/:test_type/start` | Server derives participant and ready entitlement, atomically allocates/replays the authorized attempt, starts once, and returns `AssessmentSession`; generic `dass21` is rejected because DASS uses its isolated flow |
| `GET /sessions/:id` | Returns the caller-owned `AssessmentSession`, current revision, and authoritative time projection; may atomically mark an overdue active session `expired` |
| `POST /sessions/:id/answers` | Applies the mutation/revision autosave contract above |
| `POST /sessions/:id/events` | Kraepelin-only event ingest; preserves the existing unique `(session, seq)` rule and applies the same ownership/deadline/closed-session boundary |
| `POST /sessions/:id/subtest/next` | IST-only monotonic subtest transition; cannot extend `ends_at` or reopen a closed session |
| `POST /sessions/:id/proctor` | Stores bounded proctor evidence/logs under the same participant/session authorization without altering deadline or answer revision |
| `POST /sessions/:id/submit` | Seals once, scores idempotently, and returns the stable `status: scored` response |

Route/controller work must continue to implement `RequiresRlsContext` and use
the existing participant bearer and `rls` middleware. IDs in paths are selectors,
not authorization evidence.

## PostgreSQL RLS and privilege matrix

The application role remains non-owner and `NOBYPASSRLS`; all generic session
tables force RLS. Empty, malformed, or stale application context denies access.

| Actor/context | Generic sessions | Generic answers/events | Physical delete |
|---|---|---|---|
| participant | SELECT own session; only narrowly guarded start/submit transition capability | SELECT own; INSERT/UPDATE own only while the parent is `in_progress` and within deadline | denied |
| branch admin/staff | SELECT metadata for participants in own branch | denied raw response/event access | denied |
| psychologist | SELECT sessions and raw answers/events needed for review | denied ordinary mutation | denied |
| super admin | SELECT operational session metadata | denied raw answer/event access unless a separately approved psychological-data capability exists | denied |
| service/internal job | Required lifecycle, ingest, scoring, expiry, and retention operations with explicit internal context | required bounded operations | retention/repair only, never participant-driven |
| no valid context | denied | denied | denied |

RLS establishes row visibility; database constraints/triggers or equally strong
guarded database commands establish immutable columns, legal state transitions,
deadline checks, and revision rules. A participant policy must not be `FOR ALL`
with ownership as its only predicate. In particular, participant context cannot
arbitrarily change `participant_id`, `test_type`, attempt, timestamps, deadline,
status, score linkage, or delete sessions/answers.

If ordinary row policies cannot express comparison with the previous row or
the required atomic command safely, use a narrowly granted database function or
trigger-backed command. Do not compensate by setting `app.role=service` from an
untrusted participant request or by granting unrestricted table UPDATE/DELETE.

## Migration and rollback contract

- Migration owner and runtime credentials remain separate. New tables, indexes,
  functions, triggers, and policies must be owned/configured so the runtime role
  remains subject to forced RLS.
- Foreign-key columns used for ownership/cascades are indexed. Durable uniqueness
  protects active attempt, answer item, mutation identity, revision, Kraepelin
  sequence, and one result per session as applicable.
- Empty-schema `up -> down -> up` must succeed on the supported PostgreSQL
  version. A populated down migration must refuse destructive rollback unless a
  separately reviewed recovery/export path exists; it must not silently delete
  assessment history.
- A migration failure must not leave partially created tables or policies. DDL
  assumptions are verified against PostgreSQL rather than inferred from SQLite.

## PostgreSQL acceptance cases

The implementation is not accepted until a disposable PostgreSQL run using the
real `psikotes_runtime` role proves all of the following:

1. Fresh migration, empty rollback, re-migration, ownership, grants, forced RLS,
   constraints, partial/unique indexes, and guarded populated rollback.
2. No-context access returns no rows and cannot insert, update, or delete.
3. A participant can read only their own session/answers and cannot guess another
   public or internal ID, re-parent rows, change immutable session fields, force
   a legal-looking state/deadline, or delete history.
4. Branch admin/staff are branch-scoped for session metadata and cannot read raw
   answers/events. Psychologist and super-admin behavior matches the matrix.
5. Generic tables and actions reject `dass21`; DASS rows remain confined to the
   isolated schema and invisible to non-psychologist admins.
6. Two concurrent start/allocation requests yield one authorized attempt and one
   durable deadline. Resume does not extend it.
7. Receipt at exactly `ends_at + 10s` is accepted; one microsecond later is
   rejected with zero batch/revision/ledger writes and the session expires.
8. Autosave same-mutation replay is a no-op with the identical receipt; payload
   mismatch, stale revision, gap revision, and two concurrent next revisions
   produce the specified deterministic outcomes.
9. Autosave racing submit is serialized: either the eligible batch commits before
   sealing or submit seals first and the entire batch is rejected. No partial
   batch is possible.
10. Concurrent/replayed submit creates one sealed revision, one scoring result,
    and one stable response; submitted recovery never reopens answers.
11. `submitted`, `scored`, and `expired` do not authorize a new attempt. A retest
    succeeds only after an authorized, audited void/retest grant, and concurrent
    retest allocation still has one winner.
12. Tests run as non-owner `NOBYPASSRLS`, and connection reuse across different
    participant/branch contexts proves transaction-local context is cleared.

## Review gates and ownership

Before implementation, the coordinator must approve:

- the added request fields for autosave and additive response fields;
- the expanded session states and synchronous `status: scored` behavior;
- the attempt/retest authorization representation;
- the exact guarded-command mechanism used for participant start, answer, and
  submit operations; and
- migration ownership plus PostgreSQL test ownership for the next increment.

Only the coordinator may then amend `API_CONTRACT.md`, shared DTOs/routes,
canonical checklists, or ownership records. One explicitly assigned migration
owner implements schema/RLS changes serially. DASS implementation remains a
separate lane and may not consume this generic contract.
