# Test harness reliability handoff — 2026-09-13

## Identity and ownership

- Task: cross-process PHPUnit harness reliability and disposable PostgreSQL runner hardening.
- Discovery baseline: `16fa5b9fc07f3aee6913eaae3686705d6146ee01` (`codex/f2-wave1-integration` at task start).
- Integration parent used for final verification: `fff2febd743c049a04a1d1f4929295aa0e574d18`.
- Branch: `codex/test-harness-reliability`.
- Result commits:
  - `d51f1f5` — harden forked PostgreSQL worker result/teardown paths.
  - `71fd720` — add suite-order-preserving PostgreSQL filtering.
- Owned files:
  - `tests/Support/ForkedProcessResult.php`
  - `tests/Unit/Support/ForkedProcessResultTest.php`
  - the 22 PostgreSQL test files changed by `d51f1f5`
  - `tools/testing/run-org-postgres.ps1`
- Explicitly untouched: production behavior, routes, shared DTO/API contracts, migrations, payment behavior, ADRs, and canonical acceptance checklists. R2 remains the sole migration owner.

## Diagnosis

Forked PostgreSQL tests wrote their terminal JSON result to a socket and only then disconnected and exited. When a parent assertion or timeout closed that socket, PHPUnit's error handler converted `EPIPE` into a `Throwable`. The child could then skip the final `exit(0)` and unwind into its inherited PHPUnit runner, allowing duplicate fixtures and process-local Storage/Mockery state to contaminate later tests.

The runner also needed a safe way to select focused tests. Passing file paths after the XML configuration changed canonical suite ordering and reproduced false fixture failures. The runner now accepts only a PHPUnit `--filter`, so the XML suite order remains authoritative.

## Repair

- Added `ForkedProcessResult::sendAndExit()` and `sendOrExitFailure()`.
- Result-channel, JSON, cleanup, and close failures terminate the child with exit code 2; success terminates with 0.
- Retrofitted every raw terminal result-write path found in the PostgreSQL suite; existing already-guarded worker implementations were left intact.
- Guarded two event-writer catch paths which could throw again while reporting their first failure.
- Added `-Filter` to the disposable PostgreSQL runner without accepting arbitrary file arguments.

## RED/GREEN evidence

- RED 1: closed terminal channel escaped the child helper; expected exit 2, observed exit 9 (`1 test`, `4 assertions`, `1 failure`).
- GREEN 1: terminal-channel reproducer passed (`1 test`, `4 assertions`).
- RED 2: closed intermediate channel escaped before its follow-up barrier; expected exit 2, observed exit 9 (`2 tests`, `1 failure`).
- GREEN 2: both process-boundary regressions passed (`2 tests`, `8 assertions`).
- Final unit rerun on the integration parent: `2 tests`, `8 assertions`, pass.

## Suite evidence

### Stable pre-R2 baseline (`16fa5b9` plus the repair)

- Fork-heavy focused PostgreSQL run: `97 tests`, `1,951 assertions`, pass.
- Full disposable PostgreSQL suite: `538 tests`, `5,731 assertions`, pass in 2m36s.
- A separate pre-repair baseline run also passed once at the same `538/5,731`, confirming the reported pollution is nondeterministic; one green run was not treated as proof of reliability.

### Final integration parent (`fff2feb` plus the repair)

- S4 SQLite exact scope: `13 tests`, `84 assertions`, pass.
- S4 disposable PostgreSQL exact scope: `5 tests`, `52 assertions`, pass.
- Fork/migration focused filter: `119 tests`, `2,175 assertions`, `5 errors`. All five errors are the R2-owned ledger FK preventing legacy `test_sessions_grant_scope_unique` rollback; the other 114 tests passed. No broken-pipe, duplicate payment-method, Storage, or Mockery error appeared.
- Full disposable PostgreSQL suite: `553 tests`, `5,522 assertions`, `43 errors`, `1 failure` in 2m26s. The primary root is the same new ledger dependency:
  - `generic_instrument_results_session_scope_fk` prevents legacy tests from dropping `test_sessions_grant_scope_unique`;
  - the ledger FK prevents isolated `instrument_versions` truncation;
  - failed teardown leaves a synthetic instrument version which causes the later catalog equality failure.
- The historical `535 tests / 5,698 assertions` claim was not reproduced. The discovered counts were `538/5,731` before R2 and `553` tests after R2.

## Static and cleanup evidence

- Pint: pass for the changed PHP files.
- PHPStan: `0` errors for the new helper and regression tests. Broader changed-test analysis reports pre-existing test typing debt and was not used as a green claim.
- PowerShell parser: pass for `run-org-postgres.ps1`.
- `git diff --check`: pass.
- Static scan: no remaining unguarded terminal `fwrite` → `fclose` → `exit` sequence in `tests/Postgres`.
- Disposable cleanup: each runner reported cleanup; follow-up Docker container, network, and volume queries found no `oncam-org-test-*` resources.

## Residual risk and verdict

- Harness repair verdict: **READY** on the pre-R2 baseline and for the exact S4 scopes.
- Integration verdict: **REPAIR-REQUIRED**. The full suite cannot be called green or stable until the R2 migration owner makes the new result-ledger foreign keys compatible with legacy rollback/truncation tests (or updates those fixtures under explicit ownership), then reruns the complete disposable suite.
- No residual broken-pipe/Storage/Mockery flake was observed after the repair. The remaining failures are reproducible schema/teardown incompatibilities rather than an intermittent harness symptom.
