# Read-only supervisor listener diagnostic — 2026-09-05

This report follows the pre-claim smoke failure recorded in `541a592`. It is a
bounded diagnosis only. No smoke run was retried and no supervisor/preflight,
init, server, browser, database, application source, manifest or reviewed config
was used or changed. The initialized `88ec...` run remains authorization-exhausted
and was not read. The older `7b2a...` run was not read or modified.

## Isolated reproduction

New direct temp directory:
`C:/Users/ThinkPad/AppData/Local/Temp/oncam-listener-diagnostic-118d2212957f4473b2589b61f39b2784`.
The apply_patch-created wrapper ran the exact PowerShell listener-inspection
command used by `WindowsRun._listeners`, with the same sanitized supervisor
environment keys and values except that directory/home/owner were scoped to this
new diagnostic temp. It captured measurements only; it did not disclose raw
stderr, process arguments, environment values, listener identities or addresses.

At most two invocations were allowed. The first used the existing three-second
bound and succeeded, so the conditional ten-second diagnostic invocation was
**not run**.

| Measurement | Actual value |
| --- | --- |
| Bound | 3 seconds |
| Elapsed | 2.9041 seconds |
| Exit code | 0 |
| Timeout | false |
| stdout valid JSON array | true |
| Listener row count | 0 |
| stderr byte count | 0 |
| Fixed category | `none` |
| Exact owned inspector handle exited | true |

A separate read-only call through the existing .NET
`IPGlobalProperties.GetActiveTcpListeners()` API also reported **0** listeners
across all address families for ports 8126 and 443 immediately before the exact
command. Neither inspection killed, opened or modified a listener.

## Supported finding

The earlier `reason=occupied_port` does not establish occupation. The code sets
the stage to `occupied_port` before calling `_listeners`; the common supervisor
exception handler then reports that stage for **any** exception from subprocess
launch, its fixed three-second `_ps` bound, stderr/size checks, UTF-8 decoding,
JSON parsing or the PowerShell network module. Only a successfully parsed,
non-empty array proves occupation.

The failure itself was not reproducible in this single permitted reproduction.
The exact command completed only **0.0959 seconds** inside its three-second bound.
Together with zero listeners before the failed smoke, after it, and in this
diagnostic, this supports a transient inspection failure—especially cold-module
or scheduling latency crossing the tight command bound—as the leading trigger.
It does **not** prove which internal exception occurred because raw error capture
was intentionally prohibited and the failed state cannot be replayed. Command
syntax, module availability, JSON shape and persistent occupation are contradicted
by the successful exact reproduction; they are not plausible persistent causes.

The durable root cause of the misleading result is therefore the guard's loss of
the distinction between `listeners_found` and `listener_inspection_failed`.
The transient trigger remains bounded to inspection execution/parsing rather than
an application, browser, database or owned-process failure.

## Minimal fix proposal (not implemented)

Keep fail-closed behavior, but make the listener inspector return only one of two
typed outcomes after a successful read: `free` or `occupied`. Map launch, timeout,
stderr, decode, parse and module errors to a third fixed sanitized outcome,
`inspection_failed`. The supervisor can still stop before claim, but its result
will no longer claim a port collision without a parsed listener row.

For the smallest latency reduction, replace the PowerShell
`Get-NetTCPConnection` module command in this one read-only check with the already
available .NET `IPGlobalProperties.GetActiveTcpListeners()` API and test both IPv4
and IPv6 results. Preserve a bounded inspector process, exact owned-handle cleanup,
and the no-kill policy. If PowerShell remains, a narrowly scoped inspector budget
above observed cold-start latency should be reviewed separately; this diagnostic
does not authorize changing the operational three-second bound.

Required tests for a later authorized fix: parsed empty/non-empty arrays,
all-family listeners, timeout/nonzero/stderr/invalid JSON/module unavailable,
fixed generic result codes, and owned inspector handle exit. No raw exception,
process metadata or listener identity should enter the supervisor result.

No code fix, retry, new smoke preparation or runtime operation follows this
report. `git diff --cached --check` passed with only this report staged. Stop for
coordinator review.

## Reviewed correction follow-up

After review, a narrow supervisor correction was implemented locally in the two
existing supervisor files. The PowerShell `Get-NetTCPConnection` query remains:
the faster .NET `IPGlobalProperties.GetActiveTcpListeners()` benchmark returned
IPv4 and IPv6 endpoints but no owning PID, while the existing ownership and
cleanup invariants require the PID for exact role/listener matching. Substituting
it would weaken that proof.

Only this cold, module-backed listener query now receives a **six-second**
inspection allowance. The value is below the reviewed maximum of ten seconds,
adds margin above the measured 2.9041-second result, and remains debited from the
single global supervisor deadline through `_command`'s `min` bound. Identity,
process snapshot, browser, server, TLS, request and cleanup limits are unchanged.

The inspector validates a JSON array whose rows have exactly `pid`, `port` and
`address`, with positive integer PID, port 443/8126, and bounded nonempty address.
Timeout, command/nonzero/stderr failures, decode/JSON/shape failures and unexpected
exceptions are converted without payload to `listener_inspection_failed`.
Only a successfully parsed nonempty array produces `occupied_port`; a parsed
empty array is free. Both outcomes stop before claim and remain fail-closed.

TDD evidence:

- RED: the new focused suite reported **5 failures and 2 errors across 32 tests**
  for malformed/failure classification and the real inspector against the old
  implementation.
- Final GREEN: **32/32 tests passed in 4.601 seconds**, including all 29 prior
  tests.
- The real bounded test first established both controlled ports were free, then
  bound owned wildcard IPv4 `0.0.0.0:8126` and IPv6 `[::]:443` listeners. One
  inspection returned both ports and the current process PID. `finally` closed
  only those socket handles; a final inspection returned empty. It was not
  skipped, stole no port and killed no process.
- Mock cases cover free, occupied, generic failure, timeout-equivalent command
  refusal, null/object/string/invalid-row shapes, sanitization, and supervisor
  reason classification. Python AST parsing passed for both changed files.
- `git diff --check` passed. No browser, PHP server, database, supervisor lifecycle,
  source copy, fresh-run preparation, or smoke retry occurred.

This correction does not authorize another runtime. A new isolated preparation
and smoke still require separate root review and authorization.
