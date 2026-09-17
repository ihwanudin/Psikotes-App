# Frontend peer review: cooperative integrity harness

Date: 2026-09-04. Static review of backend worker `65ee301` against `847b6da`.
Reviewed the harness delta, new integrity tests and backend report, with root UI
commit `740f236` as read-only integration context. This report changes no harness,
application source, manifest, source copy or canonical coordination document.

## Finding

### P2 — Bound and reap the Windows identity-probe subprocess

Location: `tools/testing/tests/Browser/serve-checkout-session.php:725-730`
(`checkoutBrowserIntegrityProcess`; subprocess creation starts at line 719).

The probe synchronously reads stdout to EOF, then stderr to EOF, then calls
`proc_close`, without a deadline or a `finally` cleanup path. If the PowerShell
child stalls before closing stdout, the probe cannot return either an identity or
an inspection failure. This affects lifecycle CLI commands as well as requests.
In particular, runtime probes at lines 795-796 run while the evidence lock is
held: a stalled inspector stalls that operation and keeps its lock. The catch
that writes the invalid latch is not reached while blocked. Killing the PHP
parent externally is not cleanup of the inspector performed by this function.

This is a liveness/owned-child-cleanup defect, not an integrity bypass or a claim
that an observed run hung. It does not depend on a hostile writer or on expanding
the accepted cooperative threat model. The advertised "short" evidence lock
does not have a bound at this call site. Existing outer browser/PHP limits do not
provide this helper with explicit inspector termination and reaping.

Reproducer for a separately authorized isolated test (NOT executed here): arrange
for the newly launched identity inspector to remain alive without closing its
output, then invoke a lifecycle operation. Assert completion within a chosen
probe deadline, invalid evidence on failure, released lock, and no surviving
inspector. The present sequential EOF reads have no path satisfying that bound
until the child exits or an external actor interrupts the parent.

Minimal recommendation: encapsulate inspection with a bounded subprocess
lifetime, bounded output handling and guaranteed pipe/process cleanup, throwing
on timeout so the existing invalidation path runs. Do not extend browser timeouts,
session TTLs or accept inspection failure as a dead PID. Cover timeout, malformed
output, inspection error, live PID and exited PID with the real Windows adapter
when runtime verification is separately authorized. The injected probe at
`checkout-integrity-tests.php:47-49` tests lifecycle decisions, but cannot detect
this subprocess defect.

## Coverage and conclusions without additional findings

- Traced CLI dispatch and `init -> pre -> start -> serve -> stop -> verify ->
  business -> post`. `init` still runs full integrity before creating runtime
  files; the canonical runtime-path loop checks existing paths conditionally,
  so it does not require the database to exist before initialization.
- Checked owner/run/digest/nonce binding, creation-tick comparison, distinct
  registered process IDs, recorded verifier identity, state ordering and
  pre/post owner rechecks. No additional definite state-transition defect found
  by source inspection. Browser navigation must wait for `integrity-start`;
  actual startup handshake remains an untested runner dependency.
- Reviewed exclusive evidence creation, nonblocking lock, invalid latch,
  malformed-record rejection, refusal to rearm and marked-run downgrade guard.
  Failure does not produce accepted evidence. Post output still says
  `accepted=false`; it must not be promoted into acceptance automatically.
- Reviewed full scan versus fixed critical-file checks and their callers. No
  additional critical-list defect established in this review. The explicit list
  is not transitive dependency coverage; the reported transient noncritical
  edit/restore limitation remains the approved cooperative limitation, not a
  new finding or an OS sandbox claim.
- Registered runtime/verifier death checks are not descendant/listener
  enumeration. The backend report correctly leaves that to the runner and root
  acceptance. This is distinct from the inspector spawned inside the probe
  itself, whose bounded cleanup is the finding above.
- Read the synthetic tests and their simulated PID map. Backend-reported
  52 cases/161 assertions and 64 existing CLI checks were NOT rerun here.
  Windows command exit-code behavior for missing/access-denied PIDs, invocation
  timing, startup and actual descendant cleanup remain unverified; no claim
  that simulated identities establish those behaviors.

## UI integration note — separate pending work

Root `740f236` adds local `/css/checkout-summary-v1.css` and
`/brand/oncam-logo-full-color.png` references to the accepted readonly view.
The reviewed harness routes shown at lines 406-433 do not establish retrieval of
those static assets. Future source-copy/manifest and routing integration must
account for the accepted view and its assets, then prove retrieval in the browser.
This note is not authorization to refresh the existing copy, add routes or expand
this review into browser/UI implementation.

## Verification and provenance

Read-only Git/source inspection only; no PHP execution, pure tests, syntax checks,
server, browser, DB, junction creation, dependency installation or runtime probe.
Only this new report is owned/staged; `git diff --check` is the document check.
The existing exact-run manifest
`3b333a3f25d5255e5a17da94dbed8054651cf0e163458274a0c1eb4a9f827dea`
was not read, rewritten, refreshed or reverified. No active environment/data,
outbound requests, reset/merge, production changes, push/deploy or extra agents.
Stop for coordinator review; no runtime acceptance or P14/P16 completion claim.
