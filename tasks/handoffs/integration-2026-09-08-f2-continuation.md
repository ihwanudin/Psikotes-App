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
| `/root/review_session` | Session schema/RLS, autosave, start/resume and their isolated tests | idle; all dispatched increments reviewed | Choose the next internal status or submit/scoring-recovery boundary without contradicting synchronous `status: scored` |

`/root/review_ist` is idle after accepted DASS, normalization, and T-06 zone
work. The next safe dependency is the recommendation-label guardrail policy
under exclusive eligibility ownership. `/root/review_p17c` is idle after its
accepted evidence/proposal work.

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
