# Authorized synthetic smoke — stopped before claim

One authorized invocation on 2026-09-05 local time, following preparation
`03311d4`. Result is **invalid, not accepted, no HTTP smoke completed**.
No retry, repair, timeout change, rearm or new run was attempted.

Run: `C:/Users/ThinkPad/AppData/Local/Temp/oncam-checkout-88ecdb30b351401db6c6111d8d4ffb27`.
Source pin: `76039b395dca3b9c80f918117e51bdeaa88f6020`.
Reviewed manifest SHA-256:
`4dd4496954a6ddfd0e9ab7c9883716cdabdbd587cce920c60e8a321dc081c8bd`.
Reviewed supervisor config SHA-256:
`3f3b42e34815f537facb52973ada31b9247d005a98dc53abd1d75a93ac793ddd`.
Both file hashes were unchanged in the final read-only check.

## Execution evidence

The new run-local `authorized-smoke.py` was created with apply_patch. Its SHA-256
is `139271450d3285cc2546ab94acf9b7e60643be156ff8ae9d4fbd0db2a55485b6`.
It imports the reviewed supervisor and calls exactly
`supervise(WindowsRun(config), mode='smoke', requests=3, budget=180)` after init.
No source, manifest, INI, browser configuration or supervisor code was edited.
Subprocess output was kept private and only sanitized stage/count evidence was
printed; no HTML, raw cookie, credentials, certificate key contents or HAR.

| Stage | Elapsed from wrapper start | Actual evidence |
| --- | --- | --- |
| Recheck | 10.435 s | Env/reparse/hardlink checks and reviewed critical/tool/config/asset hashes pass |
| Certificate check | 10.435 s | Valid at `2026-09-04T18:04:39.397101Z`; expires `2026-09-05T17:44:02Z` |
| Ports before init | 12.459 s | All-family listeners on 8126/443 absent |
| Init | 58.443 s | Exactly once; 45.983 s; exit 0; fixed success output and no stderr |
| Init baseline | 58.542 s | 42 tables match; handoff/session/audit/outbox counts all 0 |
| Supervisor return | 61.899 s | 3.348 s; `state=invalid`, `accepted=false`, `reason=occupied_port`, `requests=0` |
| Read-only postcheck | 63.422 s | Listeners absent; all 42 tables still match init baseline |

Init used the reviewed PHP executable with `-n -c runtime.ini`, an allowlisted
environment (SystemRoot/TEMP/TMP plus synthetic testing/directory/port/manifest),
and the full-integrity default. Database/baseline/fixtures were absent before
init; no file was overwritten. The outer init limit was 180 seconds and was not
reached. Synthetic migrations/fixtures ran only inside this new disposable run.

The supervisor entered its `occupied_port` stage and stopped before claim. Its
exception handler exposes the stage, not the underlying exception. Therefore
this result **does not establish that a listener was actually present**: the
earlier and subsequent independent listener queries both returned zero. No
timeout, query failure or transient occupation is asserted as the root cause.
No diagnostic rerun was made to resolve that ambiguity.

## Requests, ownership and cleanup

- Configured maximum: 3 synthetic GET login requests; actual grants: **0**.
- Actual HTTP responses: **0**; no PHP/TLS/browser role was launched.
- Registered owned identities: **0**; roles: empty; live owned handles: **0**;
  ownership uncertainty: false.
- Claim was never obtained, so the supervisor did not enter owned-runtime
  cleanup or use its shared 15-second cleanup allowance. No process was killed.
- No descendants were spawned by the supervisor beyond its bounded inspection
  subprocesses. No application/browser child was created or left for cleanup;
  the init process had already exited with code 0.
- Postcheck of all-family ports 8126/443 found no listeners.

`supervisor.json`, `integrity-evidence.json`, `integrity-invalid` and
`violations.txt` are absent. The physical invalid latch is absent because the
reviewed supervisor only writes it after claim. **This is not permission to
reuse the run**: the authorization was consumed, the initialized run is retained
as failed evidence and must never be rearmed or retried. No latch was fabricated
to obscure this pre-claim behavior.

## Database evidence and limits

Read-only SQLite comparison decoded each baseline row, normalized object key
order and compared complete row multisets for every non-internal table, not only
counts. Table names match the init baseline exactly: **42/42 tables unchanged**.
`checkout_handoffs=0`, `checkout_sessions=0`, `audit_logs=0`, `outbox_messages=0`
both immediately after init and after the stopped supervisor invocation.

Browser runtime, HTTP asset delivery, login GET response, cookies and the full
matrix remain unverified. No login POST, handoff, payment, notifier, public app
activation, real outbound, host/trust/DNS change, download, active data access or
old-run access occurred. No PHPUnit/Pint/PHPStan/PG regression is claimed for this
report-only increment. Git cached diff check passed with only this report staged.
Stop for coordinator review; do not repair or prepare another run automatically.
