# Authorized synthetic smoke after listener correction — cleanup failure

One authorized invocation ran on the exact reviewed candidate
`C:/Users/ThinkPad/AppData/Local/Temp/oncam-checkout-49c23c96866b46ddb7d426dbe713c15d`.
The result is **invalid and not accepted**. No retry, rearm, diagnosis, code/config
change or new preparation followed the failure. Older runs were not accessed.

Source pin: `0298edc455257e656d94e3bde66bd5cb9b0cd6b5`.
Manifest SHA-256:
`6f6d1af196480364f1c5165bf728c8f73af6fbc07ce36fda7eafa2fd78f2bafd`.
Supervisor config SHA-256:
`935385c6d07b1fbb50412a5ff2fdfe09ee37c2469e34a4b70df5a5cc89858912`.
Both reviewed hashes remained unchanged after the invocation.

The run-local invocation script SHA-256 is
`9fc0910b420ddf7144f9afe5455e80af66eeb8adf11005ad219a91a0e095e1c5`.
It called exactly
`supervise(WindowsRun(config), mode='smoke', requests=3, budget=180)` once,
after guarded init. It printed only bounded stage/count/elapsed evidence; no raw
cookie, HTML, key, subprocess output, process identity or HAR was captured here.

## Actual stages

| Stage | Elapsed from wrapper start | Result |
| --- | --- | --- |
| Recheck | 8.945 s | Static source/config checks pass; cert valid; all-family ports 8126/443 free |
| Certificate time | 8.945 s | `2026-09-04T18:35:43.808589Z`, inside reviewed validity |
| Init | 51.152 s | Exactly once, 42.207 s, exit 0, fixed success output, stderr empty |
| Init baseline | 51.200 s | 42 tables exactly match; lifecycle counters below are zero |
| Supervisor | 179.716 s | 128.502 s; `state=invalid`, `accepted=false`, `requests=0`, `reason=cleanup` |
| Read-only postcheck | 179.959 s | Listeners absent, invalid latch present, all 42 tables unchanged |

Init ran only because `browser.sqlite`, `baseline.json` and `fixtures.json` were
all absent. It used the reviewed PHP/INI, the full-integrity default, a sanitized
synthetic environment and a 180-second outer bound. No existing fixture was
overwritten.

The corrected listener inspection passed: the run advanced beyond the port
guard, claimed its lifecycle and started all three roles (`php`, `tls`,
`browser`). The supervisor then failed before granting its first smoke request.
Its final fixed result reports `cleanup`; because cleanup can replace an earlier
stage failure, this evidence does not identify which preceding lifecycle check
first failed. No extra diagnostic or reproduction was run.

## Requests, HTTP and assets

- Configured maximum: 3 fixed synthetic GET login requests.
- Request permits granted: **0**.
- HTTP responses observed: **0**.
- Login status/MIME: not reached and therefore not asserted.
- Asset status/MIME: not reached; smoke mode does not add asset requests.
- No login POST, handoff, payment, provider, notifier or real outbound operation.

## Cleanup and persistent state

The supervisor recorded 16 owned identities and started all three expected roles.
At return, all direct handles were exited and there were no listeners on 8126 or
443. A subsequent read-only command-line/path count, excluding its own inspection
process, found zero processes referring to this candidate. No process or listener
was killed outside the supervisor's own cleanup.

`ownershipUncertain=true`, so this report does **not** upgrade cleanup to proven
identity-complete success even though direct handles, candidate-path process count
and listeners were empty. The supervisor correctly returned `reason=cleanup` and
wrote the invalid latch. `supervisor.json` and integrity evidence exist;
`violations.txt` is absent. Their contents were not printed or copied.

Complete normalized SQLite row comparison against the immutable init baseline
passed for **42/42 tables** after the failed lifecycle. Counts were zero both
after init and after supervisor return:

| Table | Count |
| --- | ---: |
| `checkout_handoffs` | 0 |
| `checkout_sessions` | 0 |
| `audit_logs` | 0 |
| `outbox_messages` | 0 |

No public source/route activation, active data, host/trust/DNS modification,
download, deployment or push occurred. This initialized and invalid-latched run
must never be reused. Browser HTTP behavior remains unverified. No fix or next
smoke is assumed; stop for coordinator review. `git diff --cached --check`
passed with only this report staged.
