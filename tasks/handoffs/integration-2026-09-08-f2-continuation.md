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

The schema is not yet accepted for PostgreSQL. The active sole migration owner
must repair explicit lifecycle nullability, microsecond timestamp precision,
RLS/write enforcement, and populated rollback locking. PostgreSQL runtime
evidence remains mandatory; SQLite/static evidence alone cannot close it.

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

The following transforms remain blocked on authoritative psychometric decisions
and must not be guessed: IQ-to-level, PAPI normalized level, RMIB rank-to-level,
Kraepelin Panker wording, and Kraepelin rounding mode. The exact conflicts and
minimum expert questions are recorded by accepted docs-only commit `31acac9` in
`tasks/handoffs/f2-psychometric-data-conflicts.md`.

### P17c synthetic verification

Accepted commits include `fee63d4`, `ef2a2be`, `4326bda`, `f189ad1`,
`e411259`, `8a1e760`, and `2176656`. The host-native synthetic listener test
and its three controls passed after the stale fixture repair. This does not
close P17c/P18: real browser/native candidate and launch evidence are still
absent, and the related gates remain default OFF.

## Active increments

| Agent | Ownership | Increment | Next coordinator action |
|---|---|---|---|
| `/root/review_session` | Assessment-session migration/RLS and its tests; sole migration owner | PostgreSQL schema/RLS repair | Inspect delta and run disposable PostgreSQL tests before acceptance |
| `/root/review_ist` | DASS-only scoring source/tests | Pure DASS-21 validation, subscale scoring, canonical categorization, and provenance | Inspect delta and rerun scoring/F0/static gates before acceptance |

`/root/review_p17c` is idle after the accepted conflict brief and must not be
given overlapping scoring or migration work.

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
   keep all conflicting psychometric normalization work paused pending the
   decisions in the conflict brief.
