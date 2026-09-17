# Cooperative browser integrity implementation — synthetic review only

2026-09-04. Continuation of the interrupted backend increment, based on worker
847b6da and root authorization recorded in parallel-work.md (234da10). Option B
is accepted only for cooperative isolated synthetic work. This report does not
accept a browser run, authorize a refresh of the existing disposable source, or
replace the unchanged business/security assertions.

## Delta and lifecycle

Only three lane files: serve-checkout-session.php, checkout-integrity-tests.php,
and this report. The original full verifier is moved into a callable helper:
recursive canonical-path check, every manifest SHA-256, exact source inventory,
and the original required-file set remain. Default mode still uses that full
check on every invocation. The single-traversal benchmark 847b6da is NOT substituted.

The opt-in value ONCAM_CHECKOUT_BROWSER_INTEGRITY_MODE=cooperative-v1 selects:

| Operation | Required evidence and result |
| --- | --- |
| integrity-pre | Fresh exclusive evidence file; full scan and fixed subset pass; live owner; records preverified |
| integrity-start | Same owner/run/digest; three distinct live PHP/TLS/browser identities; records serving |
| serve | Same owner, serving state, registered PHP PID, all three runtime identities live; fixed subset checked before bootstrap |
| integrity-stop | All three registered identities dead and explicit successful-browser attestation; records stopped |
| verify | Stopped; registers a distinct live verifier process, then runs the unchanged real business/lifecycle verifier |
| business (internal only) | Same live verifier and successful real verifier return; records businessVerified |
| integrity-post | All registered runtime and verifier identities dead; successful browser and business evidence; original full scan; no violations; records postverified |
| Any observed integrity/transition/owner failure | Exclusive invalid latch; cannot restart, repair, downgrade to default, or rearm this run |

State output always contains accepted=false. No harness status is automatic root
acceptance. Normal init still uses full verification and existing fresh-database
guards. The new pure test entrypoint branches before autoload/environment/bootstrap.

Owner input is strict ordered JSON {pid, started, nonce}; started is Windows
process creation ticks, nonce is 64 lowercase hex characters generated once for
that run by its owner. ONCAM_CHECKOUT_BROWSER_OWNER must be identical throughout.
ONCAM_CHECKOUT_BROWSER_OWNED_PROCESSES has exactly php/tls/browser, each {pid,started}.
The existing reviewed manifest digest is unchanged throughout a run. Start requires
those processes alive but no browser requests yet; a cooperative runner must
arrange that handshake before navigation. This slice does not launch that runner.
ONCAM_CHECKOUT_BROWSER_ASSERTIONS_PASSED=1 is required at stop and is an explicit
cooperative runner attestation, not proof extracted from browser output.

The evidence file uses an exclusive creation plus short nonblocking file locks.
This is advisory ownership, not an OS write boundary. PID reuse is distinguished
by creation ticks; a dead owner is stale. Owner is rechecked after full pre/post
scans. No TTL, browser timeout, PHP execution limit, session lifetime or limiter
was changed. Runtime process inspection reads only PID creation ticks, not process
arguments/environment; inspection errors reject. Its actual Windows invocation
and timing still require a separately authorized runtime check.

## Fixed lighter surface and its limits

checkoutBrowserIntegrityCriticalFiles() explicitly enumerates 69 files. All 69
exist in the worker source (read-only presence check). The set includes Composer
manifest/lock/autoload machinery and installed metadata, bootstrap files, the
listed application configs/provider, checkout controller/views/middleware,
handoff/session actions, summary/profile/payment DTOs and readers, entitlement
and consent/settlement readers, and the browser/PHP/TLS harness scripts. It is
neither caller-selected nor expanded automatically. Review the exact list in that
function when source dependencies change.

Every lighter check pins manifest bytes to the original digest, hashes all 69
files, and checks their canonical parent chain. Run/source roots and the listed
runtime storage/DB/manifest paths remain canonical; .env variants and cached
config/routes are refused. Unlisted source files are fully hashed only pre/post.
Normal synthetic runtime session text changes outside source are allowed by the
integrity layer; actual DB/session deltas still require the existing verifier.

Persistent noncritical edits, extra/missing files and nested junctions can pass a
request guard but fail the full postcheck. Noncritical edit-then-restore and a
transient nested junction restored before postcheck CAN pass. Tests demonstrate
both, without executing their changed text. Endpoint hashes do not prove which
bytes executed between checks. Same-account hostile writers can replace code,
markers or the verifier; no hostile-writer/outbound containment claim is made.

Registered PHP/TLS/browser/verifier identities are checked, but this helper does
not enumerate descendants, arbitrary writers or all listeners. The cooperative
runner must also verify its child-process tree and owned listeners are gone;
postverified alone must never be relabeled accepted without that external cleanup
and root review. Missing postcheck, hard-killed owner, incomplete marker and failed
verification are unusable evidence; a process killed before its shutdown hook may
leave an incomplete state until the next check rejects it. Do not delete the
invalid latch or repair source to resume the same run.

## Actual verification

- Existing pure CLI regression: 64 checks, PASS.
- New suite: 52 synthetic cases / 161 assertions, PASS, php -n.
- PHP syntax checks: both owned PHP files PASS.
- Pint --test on exactly those two files: PASS.
- git diff --check: PASS before scoped staging.
- No listener on ports 8126/8443 at resume; no process was killed.

TDD evidence: the resumed primitive stub previously failed before helpers existed.
The meaningful cleanup regression then failed because postcheck could complete
while the business-verifier process was still alive (exit 1). Adding recorded
verifier identity and postcheck cleanup made it pass. A second RED showed a marked
run could try default-mode downgrade; the mode guard now rejects and latches it.
Final suite also covers owner death during the full precheck before publication.

Tests use fresh random direct OS-temp children with synthetic text, their own
manifests, and simulated owner/runtime/verifier process identities. Six native
Windows junction cases use a short PowerShell New-Item helper linking only paths
inside the newly created fixture, and restore the junction afterward. There is no
server/browser/application/database launch. Fixtures are preserved; no recursive
delete, privilege change or shared dependency mutation was performed.

Coverage includes full precheck same-size/mtime change, missing/extra files,
manifest digest/traversal, env/cache, source and nested/critical junctions;
missing/truncated/wrong-run/digest/owner/version/nonce evidence; owner death/PID
reuse, wrong server/runtime death, illegal transitions, advisory-lock contention,
critical change and refusal after repair, persistent noncritical changes,
edit/restore limitations, live-process/failed-browser/no-business/violation/post
replay denial, wrong verifier and verifier crash. Native file symlinks, OS owner
probe timing, descendant cleanup and HTTP environment/startup wiring were NOT
runtime-tested. No claim that simulated process identities prove OS containment.

PHPStan's configured paths exclude tools/testing; no app/static-analysis or
PHPUnit/PG/browser suite was run for this pure harness slice. There are no app,
transaction, schema, route or business-verifier changes requiring a fabricated
regression claim. Real HTTP assertions and performance targets remain pending.

## Provenance and stop

No read/refresh/rewrite of the exact disposable run or its manifest was performed.
Prior run is oncam-checkout-7b2a098a09934fa38a946b9d796b6480; prior reported manifest
3b333a3f25d5255e5a17da94dbed8054651cf0e163458274a0c1eb4a9f827dea and harness
2c38a360ffffc99818509bae881eef188e70c16ec73bb34d7fb95aea5ef4fb86 are historical,
not freshly verified hashes in this turn. 5340ef7/50e2800 provenance integration
remains a root decision. Dirty baseline is preserved, not staged as lane work.
No .env/real data/provider/notifier/DB, dependency installation, public wiring,
timeout/TTL change, deploy/push, or new task/agent. STOP for root review before any
source refresh, runtime benchmark or browser pass.

## Review fix P2 — bounded inspector (2026-09-04)

Supersedes the earlier untested-inspector statement above only for the narrow
self-owned subprocess probes below. Root has not accepted the original browser
increment or authorized checkout runtime. Delta stays in the same three lane files.

The original Windows inspector read pipes and called proc_close without a deadline.
The replacement uses real tmpfile descriptors (no anonymous pipes at all), polls
process status plus output sizes every 10 ms, and reads at most 257 bytes per stream.
Production inspector deadline is 3,000 ms, with a separate 1,000 ms termination
confirmation bound. A trusted internal helper allows 100–5,000 ms for synthetic
tests; there is no browser/environment-selected deadline. No business timeout,
TTL, session limit or limiter changes.

The acceptance limit is 256 bytes each for stdout/stderr; any stderr or nonzero
exit is refused, and stdout must be empty or at most 20 decimal tick digits after
trim. Oversize output is refused while polling and again on bounded capture.
Temporary spool size can overshoot 256 bytes between polls (tests emit 4,096 bytes);
this is not a hard disk quota or protection against a hostile flood producer.
Only the fixed, trusted local inspector command is used by the harness.

Finally cleanup terminates only the inspector resource created by that call,
confirms it stopped before proc_close, and closes both temporary file handles on
success, error, timeout, malformed output, oversize output and callback failure.
If termination cannot be confirmed, it releases the process handle through GC,
returns the same failure and never waits with proc_close. Such an OS termination
failure is not reported as successful cleanup and remains untested. Inspection
never terminates its target PID. All failures expose only "Owner inspection failed"
without stderr, command, path, identity or chained exception. Cooperative evidence
catches this failure, latches invalid, and releases its file lock.

The process query now uses local .NET Process.GetProcessById and disposes that
handle. Only its documented missing-PID ArgumentException becomes an empty result;
other failures remain errors. This also avoids PowerShell Get-Process's nonzero
status for a missing PID, found during the actual synthetic cleanup test.

Source basis: [PHP 8.3.26 proc_open.c](https://github.com/php/php-src/blob/php-8.3.26/ext/standard/proc_open.c)
shows Windows TerminateProcess is called on the proc resource's own handle;
proc_close waits indefinitely, whereas resource GC closes without waiting.
[PHP proc_open documentation](https://www.php.net/manual/en/function.proc-open.php)
supports direct argument arrays and real-file resource descriptors. No assumption
about stream_set_blocking(false) on Windows is made.
[Microsoft GetProcessById](https://learn.microsoft.com/en-us/dotnet/api/system.diagnostics.process.getprocessbyid?view=netframework-4.8.1)
documents local lookup and the missing-process exception. No remote overload used.

Measured on installed Windows PHP 8.3.26, php -n --inspector-tests:

| Synthetic probe | Helper wall seconds |
| --- | ---: |
| Success | 0.061 |
| Empty result | 0.050 |
| Stall, 300 ms deadline | 0.308 |
| Nonzero exit | 0.064 |
| Stderr | 0.060 |
| Malformed stdout | 0.070 |
| Oversize stdout | 0.063 |
| Oversize stderr | 0.068 |
| Unexpected callback error | 0.004 |
| Self-owned live child: two real tick inspections then normal exit | 1.577 |

62 new inspector assertions PASS. Each ordinary probe asserts bounded return,
expected fixed error/result, removed temporary streams and an actual OS lookup
confirming that its own child is gone. Stall also asserts it reached the deadline.
The live control produces matching creation ticks twice, then its own expected
output, proving the inspector did not kill the inspected child. A real stalled
child under the evidence lock verifies invalid latch plus nonblocking lock
reacquisition after failure. No simulated child-termination claim is used here.
Timing is a small set of local probes, not a checkout benchmark or hard scheduler
real-time guarantee. Process creation/filesystem/OS failure cannot be bounded by a
PHP polling loop if the OS itself stops responding; no such guarantee is claimed.

RED: the new inspector suite exited 1 before the bounded helper existed; first
implementation then exposed the missing-PID PowerShell exit problem, also exit 1.
GREEN after both fixes: 62 inspector assertions, original 64 pure checks, original
52 fixture cases / 161 assertions, two PHP syntax checks, Pint --test, diff-check.
The 52-case lifecycle tests still simulate their process identities; the separate
62-check inspector suite uses real short self-owned children. These are distinct
claims. No app/server/browser/DB, exact-run read/refresh, visible windows, privilege
change, provider/notifier, or unrelated process termination. Fixed 69-file scope,
accepted=false, cooperative edit/restore and external descendant-cleanup limits
remain. STOP for coordinator review; no checkout runtime authorization inferred.
