# Authorized smoke with actionable supervisor result

One authorized invocation ran on the exact reviewed candidate:
`C:/Users/ThinkPad/AppData/Local/Temp/oncam-checkout-4e23188e5906484e8655c7d8ce335364`.
It failed closed before any request. No retry, rearm, diagnosis, source/config
change, full matrix or new run followed.

Source pin: `4d72d8353bcffae323bedc9919861743bce5e6ec`.
Manifest SHA-256:
`bc9d7c581e2c4f50053ef25469e0daba160417e85400ba7766119905a383eba2`.
Supervisor config SHA-256:
`0e247e0377b57c664eb4c134005419e28381315a2f7623ed882548724b21d694`.
Both reviewed hashes remained unchanged after execution.

The apply_patch-created invocation SHA-256 is
`c20fcc764af1bcb640908280bd6ff79c28f78b1def8e7d4736e619fc3aed7e38`.
It called exactly
`supervise(WindowsRun(config), mode='smoke', requests=3, budget=180)` once
after guarded init and emitted only fixed state, counts and elapsed times. It did
not print raw subprocess output, process identities, cookies, HTML, key or HAR.

## Stages and latency

| Stage | Wrapper elapsed | Actual result |
| --- | ---: | --- |
| Recheck | 4.893 s | Certificate valid; static checks pass; all-family ports 8126/443 free |
| Recheck time | 4.893 s | `2026-09-04T19:09:06.492632Z` |
| Init | 24.812 s | Exactly once; 19.918 s; exit 0; fixed success; no stderr |
| Init baseline | 24.850 s | 42 tables exactly match; four lifecycle counters zero |
| Supervisor | 74.497 s | 49.640 s; fixed result below |
| Read-only postcheck | 74.659 s | Ports empty; invalid latch present; 42 tables unchanged |

Exact supervisor result:

| Field | Value |
| --- | --- |
| `state` | `invalid` |
| `accepted` | false |
| `requests` | 0 |
| `reason` | `spawn_browser` |
| `primary_reason` | `spawn_browser` |
| `cleanup_status` | `uncertain` |
| `uncertainty_categories` | `parent_identity_mismatch`, `parent_missing`, `pid_reuse` |

The new result contract preserves the actionable primary failure: PHP and TLS
roles started, but browser launch did not complete and no browser role was
published. Cleanup uncertainty no longer overwrote that stage.

## Requests and HTTP evidence

- Configured smoke permits: at most 3 fixed login GETs.
- Permits consumed: **0**.
- HTTP responses observed: **0**.
- Login status/MIME: not reached.
- Asset status/MIME: not reached; no asset request was added.
- No login POST, handoff, payment, provider, notifier, public endpoint or real
  outbound operation occurred.

## Ownership and cleanup

Published roles at return: `php`, `tls`; browser absent. The in-memory ownership
census contained 7 exact PID/creation-tick identities. Immediately after the
supervisor returned it measured:

| Census outcome | Count |
| --- | ---: |
| Exact identity still alive | 4 |
| Missing | 3 |
| PID reused at census | 0 |
| Direct process handles still open | 0 |

The four live exact identities mean cleanup is **not** claimed complete even
though direct handles were closed and all-family listeners on 8126/443 were
absent. The supervisor correctly reports `cleanup_status=uncertain` and records
three bounded categories without exposing identities. No manual kill, follow-up
process interrogation or retry was performed; this report does not guess whether
the remaining identities subsequently exited.

The invalid latch exists. This run is consumed and must never be reused or
rearmed. Older runs were not accessed.

## Database boundary

Complete normalized SQLite comparison against the immutable init baseline passed
for **42/42 tables** after supervisor return. Counts remained zero both after init
and post-run:

| Table | Count |
| --- | ---: |
| `checkout_handoffs` | 0 |
| `checkout_sessions` | 0 |
| `audit_logs` | 0 |
| `outbox_messages` | 0 |

No application source/route activation, active data, host/trust/DNS change,
download, deploy or push occurred. Browser HTTP behavior remains unverified. No
fix or next runtime is assumed. Git cached diff check passed with only this report
staged. Stop for coordinator review.
