# F9 session/result local load baseline — 2026-09-14

## Verdict

**BASELINE RECORDED; NO PERFORMANCE OR RELEASE PASS.** Two identical bounded
local runs completed the fixed workload without an operation error or integrity
failure. The run-to-run spread is material, and no authoritative latency or
throughput SLO exists. These measurements must not be converted into a budget,
capacity claim, optimization claim, or production forecast.

F9 remains `partial` and release status remains `NO-GO`. This lane changes no
T-01..T-28 status and proves neither public HTTP behavior nor manifest-backed
instrument delivery.

## Identity and scope

- Task: `/root/f9_load_performance_baseline`.
- Branch: `codex/f9-load-performance-baseline`.
- Worktree: `C:/Users/ThinkPad/.codex/worktrees/f9-load-performance/Psikotes`.
- Exact baseline: `99b557f3221f8261c10b7841a3bb18ccbe4953e9`.
- Measured implementation commit: `59bbc78` (full SHA reported in final handoff).
- Exact new files:
  - `tools/testing/run-f9-session-result-load.ps1`;
  - `tools/testing/f9-session-result-load.php`;
  - `tools/testing/tests/f9-session-result-load-contract.ps1`;
  - this evidence file.

No existing file changed. Production/application code, migrations/schema,
routes, HTTP/API/DTO/ADR/contracts, configuration, Compose, lockfiles,
checklists/statuses, manifests, feature activation, live infrastructure/data,
deployment, scheduler, notification, and outbound work are untouched.

## Authority and claim boundary

- `SPEC.md:298` assigns load testing to F9, but specifically names Kraepelin.
  This IST boundary baseline does not satisfy that future Kraepelin load gate.
- `tasks/parallel-work.md:23,81` assigns performance/load tooling to F9 and
  places final load evidence after stable runtime.
- `docs/PERFORMANCE_BASELINE.md:94-110` says general route budgets are absent,
  distinguishes query count from latency/plan, and requires distributions before
  setting a budget.
- `tasks/f2-f9-acceptance.md:49,56` keeps F2 and F9 partial: generic start is
  still unwired/manifest-blocked and load/observability/final launch remain open.
- `tasks/parallel-work.md:364` records the accepted trusted start integration;
  `tasks/parallel-work.md:367,450-455` records the accepted immutable IST reader,
  caller-owned service transaction, checksum validation, and nine-source order.

There is no authoritative latency percentile, throughput, concurrency, dataset,
or error-rate SLO for these two unwired boundaries. Zero errors and exact state
are harness correctness gates, not performance/release thresholds.

## Fixed workload and isolation

The workload was fixed before measurement and was not reduced:

| Dimension | Value |
|---|---:|
| Concurrency profiles | 1, 4, 8 |
| Warmup per boundary/profile | 32 operations |
| Measured iterations | 3 |
| Start operations per iteration/profile | 64 |
| Reader operations per iteration/profile | 256 |
| Pre-created start-session dataset | 672 distinct IST sessions/participants |
| Reader dataset | 64 immutable IST ledgers × 9 ordered sources = 576 sources |
| Measured operations per run | 2,880 |

The runner requires a full lowercase expected SHA, exact HEAD, and a clean
tracked/untracked tree before Docker inspection or provisioning. It executes a
Git archive of that SHA. It uses pinned `psikotes-app:dev` and
`postgres:17.6-alpine`, one GUID exact label, an internal Docker network with no
published ports, PostgreSQL tmpfs, task temp outside the repository, a read-only
real vendor bind, and synthetic data only. The migrated owner is replaced before
work by `psikotes_runtime`; the harness verifies `NOSUPERUSER` and
`NOBYPASSRLS`. Every boundary call uses service context and a transaction.

No identifier, SQL binding, token, raw answer, participant value, or result
payload is emitted. Query observations contain only counts and cumulative time.

## TDD and failure evidence

Initial RED, before the runner/harness existed:

```text
Load runner is missing.
```

GREEN contract:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass `
  -File ./tools/testing/tests/f9-session-result-load-contract.ps1
```

```text
f9-session-result-load-contract: PASS (46 assertions)
```

The contract statically and executably covers the exact workload, pinned images,
immutable snapshot, no ports/live actions, exact label/cleanup, direct boundary
calls, service/runtime identity, PostgreSQL observations, report-only semantics,
nearest-rank percentile math (`[1,2,3,4,5]` → p50 3, p95/p99 5), and error-rate
math (`1/5` → `0.2`).

Adversarial calls with the baseline SHA instead of HEAD and with warmup `0` both
failed before provisioning with exit 1. Exact-label inventory remained empty.

Two harness defects were found before accepted measurements:

1. label `14a001c4497344f797716daf4570a137`: a synthetic submitted session had
   `submitted_at` after `ends_at`; PostgreSQL rejected it at fixture setup;
2. labels `4928b47d66a74630a934cc4a48617025` and
   `3317463317bb4ee89b82c7dfd1fa546f`: the final cardinality query ran without
   service context, so FORCE RLS correctly returned zero visible rows.

Only harness/test-fixture code was repaired. No measured workload was reduced and
no product code was optimized. All failed attempts ended at exact-label cleanup
containers `0`, networks `0`, and temp removed `True`. Missing-report detection
was added so a framework-level unreliable process exit cannot accept a run.

## Reproducible measured command

```powershell
$sha = (git rev-parse HEAD).Trim()
powershell -NoProfile -ExecutionPolicy Bypass `
  -File ./tools/testing/run-f9-session-result-load.ps1 `
  -ExpectedCommit $sha `
  -VendorDirectory D:/LSI/Web/Psikotes/vendor
```

Run 1 label: `oncam.f9-session-result-load=0d4bf5aa5ba443a8bc0fe74b6df2c308`.

| Boundary | C | Iter | Wall ms | ops/s | p50 ms | p95 ms | p99/max ms | Queries / DB ms |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| start | 1 | 1 | 518.084 | 123.532 | 6.935 | 8.130 | 34.312 | 192 / 391.89 |
| start | 1 | 2 | 560.391 | 114.206 | 7.610 | 9.417 | 37.770 | 192 / 431.12 |
| start | 1 | 3 | 497.513 | 128.640 | 6.766 | 7.636 | 36.483 | 192 / 390.04 |
| reader | 1 | 1 | 1021.580 | 250.592 | 3.657 | 4.704 | 5.736 / 18.531 | 768 / 600.93 |
| reader | 1 | 2 | 1080.128 | 237.009 | 3.634 | 6.579 | 7.780 / 20.805 | 768 / 656.96 |
| reader | 1 | 3 | 1102.058 | 232.293 | 3.738 | 6.479 | 6.782 / 19.573 | 768 / 669.24 |
| start | 4 | 1 | 261.817 | 244.446 | 10.310 | 45.272 | 50.771 | 192 / 647.95 |
| start | 4 | 2 | 293.533 | 218.034 | 11.797 | 46.366 | 52.929 | 192 / 754.09 |
| start | 4 | 3 | 301.940 | 211.962 | 12.329 | 51.079 | 60.082 | 192 / 778.34 |
| reader | 4 | 1 | 477.897 | 535.681 | 6.136 | 7.338 | 26.690 / 28.949 | 768 / 1072.76 |
| reader | 4 | 2 | 578.877 | 442.236 | 7.518 | 8.996 | 28.839 / 31.554 | 768 / 1301.45 |
| reader | 4 | 3 | 561.299 | 456.085 | 7.157 | 8.604 | 30.265 / 33.270 | 768 / 1256.50 |
| start | 8 | 1 | 316.884 | 201.967 | 17.190 | 70.856 | 84.094 | 192 / 1260.73 |
| start | 8 | 2 | 338.308 | 189.177 | 20.341 | 82.144 | 98.653 | 192 / 1377.32 |
| start | 8 | 3 | 330.376 | 193.719 | 19.653 | 88.431 | 89.136 | 192 / 1441.92 |
| reader | 8 | 1 | 461.315 | 554.935 | 10.135 | 12.329 | 47.419 / 52.379 | 768 / 1818.86 |
| reader | 8 | 2 | 454.706 | 563.001 | 9.349 | 11.566 | 46.005 / 53.337 | 768 / 1720.11 |
| reader | 8 | 3 | 426.063 | 600.850 | 9.300 | 10.880 | 43.709 / 52.060 | 768 / 1677.99 |

Run 2 label: `oncam.f9-session-result-load=ef66295dcb4f4dcfaf68486afb2f0f3f`.

| Boundary | C | Iter | Wall ms | ops/s | p50 ms | p95 ms | p99/max ms | Queries / DB ms |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| start | 1 | 1 | 455.216 | 140.593 | 6.025 | 8.357 | 28.964 | 192 / 345.22 |
| start | 1 | 2 | 394.476 | 162.241 | 5.146 | 6.501 | 29.077 | 192 / 307.57 |
| start | 1 | 3 | 430.361 | 148.712 | 5.528 | 7.499 | 33.448 | 192 / 329.18 |
| reader | 1 | 1 | 822.325 | 311.312 | 2.796 | 3.328 | 4.269 / 17.488 | 768 / 460.35 |
| reader | 1 | 2 | 763.956 | 335.098 | 2.687 | 3.765 | 4.740 / 16.923 | 768 / 456.07 |
| reader | 1 | 3 | 788.743 | 324.567 | 2.808 | 3.550 | 4.050 / 17.616 | 768 / 465.91 |
| start | 4 | 1 | 233.156 | 274.494 | 8.768 | 41.153 | 44.054 | 192 / 563.47 |
| start | 4 | 2 | 230.609 | 277.526 | 8.547 | 39.931 | 41.874 | 192 / 552.21 |
| start | 4 | 3 | 247.524 | 258.560 | 9.604 | 44.316 | 47.696 | 192 / 618.23 |
| reader | 4 | 1 | 380.674 | 672.491 | 4.762 | 6.492 | 21.379 / 23.319 | 768 / 855.79 |
| reader | 4 | 2 | 421.297 | 607.647 | 5.139 | 6.105 | 32.255 / 32.999 | 768 / 906.90 |
| reader | 4 | 3 | 550.502 | 465.030 | 7.300 | 8.779 | 25.247 / 27.156 | 768 / 1233.00 |
| start | 8 | 1 | 269.546 | 237.436 | 15.956 | 65.118 | 79.549 | 192 / 1154.97 |
| start | 8 | 2 | 298.193 | 214.626 | 16.643 | 71.357 | 85.723 | 192 / 1243.18 |
| start | 8 | 3 | 318.822 | 200.739 | 18.731 | 77.511 | 90.972 | 192 / 1364.24 |
| reader | 8 | 1 | 401.787 | 637.154 | 8.480 | 11.852 | 38.656 / 40.836 | 768 / 1543.60 |
| reader | 8 | 2 | 428.795 | 597.021 | 9.342 | 11.451 | 39.684 / 48.310 | 768 / 1674.48 |
| reader | 8 | 3 | 463.624 | 552.172 | 9.913 | 12.659 | 47.090 / 50.296 | 768 / 1771.29 |

For every row: requested equals completed, errors/error rate are zero, metrics
are finite/nonnegative and percentile ordering holds. Maximum observed database
connections were 2/5/9 at concurrency 1/4/8. Every row recorded complete
`pg_stat_database` deltas for transactions, tuples, blocks, temp files/bytes,
deadlocks, and conflicts. Across both runs, lock-wait samples, temp files/bytes,
deadlocks, and conflicts were all zero. Start rows each observed 64 updates;
reader rows observed no inserts, updates, or deletes.

Each run ended with 736 synthetic participants/sessions, all 672 start targets
started exactly once into a valid `in_progress` window, exactly 64 immutable
results and 576 ordered sources, and three unchanged replay samples. Each run
cleaned containers `0`, networks `0`, temp removed `True`.

## Run-to-run variance

Median iteration values demonstrate host/runtime noise; they are descriptive:

| Boundary | C | Median ops/s run 1 → run 2 | Change | Median p95 ms run 1 → run 2 | Change |
|---|---:|---:|---:|---:|---:|
| start | 1 | 123.532 → 148.712 | +20.4% | 8.130 → 7.499 | -7.8% |
| start | 4 | 218.034 → 274.494 | +25.9% | 46.366 → 41.153 | -11.2% |
| start | 8 | 193.719 → 214.626 | +10.8% | 82.144 → 71.357 | -13.1% |
| reader | 1 | 237.009 → 324.567 | +36.9% | 6.479 → 3.550 | -45.2% |
| reader | 4 | 456.085 → 607.647 | +33.2% | 8.604 → 6.492 | -24.5% |
| reader | 8 | 563.001 → 597.021 | +6.0% | 11.566 → 11.852 | +2.5% |

The second run being faster in most cells is not an improvement: no product
change occurred between runs. It is direct evidence that this local setup has
material run-to-run variance and is unsuitable for a threshold until a PM-owned
SLO and a calibrated, controlled environment exist.

## Correctness and static verification

- PHP and PowerShell parsers: zero errors.
- `git diff --check`: pass.
- Relevant SQLite boundary regression in a disposable app container:
  `StartAssessmentSessionTest` plus `LoadPersistedIstResultTest`, 9 tests / 73
  assertions, pass; label `4f991b1414e14b09bd857cd840fefbcf`, cleanup
  containers 0.
- Repository security scanner: 50/50 pass.
- PII and SECRET profile results are run on the final staged four-file state and
  reported with the immutable candidate SHA.

An earlier regression-container attempt failed before tests because the PHPUnit
launcher loaded two Composer autoloaders. Its exact label
`7e3f3834570c43129472e46f222a7c05` cleaned to zero containers. The corrected
runner copied `vendor/bin` rather than invoking the read-only vendor launcher;
no repository file changed for this diagnostic.

## Remaining gates

This lane does not establish Kraepelin-specific load behavior, a representative
production dataset, HTTP/browser latency, host saturation, CPU/memory/I/O,
connection-pool sizing, sustained/soak behavior, production capacity, or an SLO.
It also does not close manifest, activation, observability, backup/PITR,
scheduler/alerting, deployment, or launch authority. Those remain separate
PM-owned decisions and evidence lanes.

Review status: **implementation complete; Tech Lead and independent QA review
required before integration.**
