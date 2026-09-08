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

Explicit recommendation guardrail acceptance evidence is accepted as
`f981c92`: T-12/G1, T-13/G2, T-14/G3, and T-19 boundaries pass against canonical
reporting data and the real zone/label services (7 tests/49 assertions).

The pure F5 signing prerequisite gate is accepted as `4933c12` (34 tests/50
assertions). It enforces the V3, V2-note, G7-resolution, G9 conditions,
override-reason, target-field, and A-D narrative prerequisites. It does not
claim persistence, signatures, publication, or state transitions; those remain
separate increments.

## Preserved user-owned work

`docs/decisions/0026-checkout-secure-composition-launcher-authority.md` was
already untracked at this checkpoint. It is outside the active assignments and
must not be edited, deleted, or included in coordinator commits.

## Resume order

1. Preserve the user-owned ADR and inspect `git status --short`.
2. Snapshot the two active agents; do not resend either active increment.
3. Review and test each result before recording it as accepted.
4. Keep migrations serial. Do not close PostgreSQL/RLS acceptance without a
   disposable PostgreSQL runtime pass.
5. Choose the next dependency-unblocked increment from `tasks/parallel-work.md`;
   prioritize F3 recommendation-label guardrails, then close T-07 with the
   DASS non-interference comparison.
