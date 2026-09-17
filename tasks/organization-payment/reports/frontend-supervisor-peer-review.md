# Static peer review — cooperative Windows supervisor

2026-09-05. Reviewed backend `f533eedbd550496d89df72e2017b85e2df8302a9`:
`checkout-supervisor.py`, `test_checkout_supervisor.py`, and
`backend-browser-supervisor.md`. One report-only frontend increment.

## P2 — Final verification commands are outside the cleanup finally

Location: `tools/testing/tests/Browser/checkout-supervisor.py:86-97`.

After the initial cleanup, full mode launches more subprocesses for
`integrity-stop`, `verify` and `integrity-post`. If stop/verify raises, execution
skips the second cleanup at line 91. If post raises, that cleanup has already
happened. The exception handler only invalidates evidence; it does not clean the
newly owned process tree. A KeyboardInterrupt in this block also escapes without
that invalidation because it is not an Exception. The earlier finally at lines
71-82 cannot cover this later block.

This matters even under the approved cooperative model: a verifier can time out
while its inspector child is alive. `_command` records descendants at lines
213-217, but its finally at lines 224-227 kills/waits only its direct Popen child.
An already recorded surviving descendant is left without a cleanup attempt on
this path. This is not the accepted limitation of an unobserved fleeting child;
the supervisor can already possess its exact identity. The result may correctly
say invalid while still failing its owned-process cleanup obligation.

The successful post path also launches processes after the last cleanup census,
then sets postverified without checking newly raised ownership uncertainty or
performing a final owned-tree/listener check. `accepted=false` remains correct,
but does not itself establish cleanup for these last commands.

Proposed reproducer, NOT executed: extend the pure fake so `harness('verify')`
records a live owned descendant and raises a timeout; require cleanup after that
failure and an invalid latch. Repeat for postcheck failure and KeyboardInterrupt
in each finalization stage. At the adapter level, model a registered verifier
parent exiting/killed while its known child remains. Current tests asserting
only invalid state/latch for verifier/post failure do not assert that cleanup
runs afterward. Also model postcheck returning after setting ownership uncertain;
it must not yield a clean final result.

Minimal recommendation: place the entire post-browser verification phase under
an unconditional bounded cleanup/failure-invalidation structure, including
interrupts, and check the final cleanup outcome before publishing its result.
Keep the required cleanup before integrity-stop/business verification; do not
replace it with a cleanup only at the end. Preserve exact-identity termination,
the existing work budget and bounded cleanup policy. No timeout increase or
unknown-PID adoption is proposed.

## Inspected boundaries; no additional concrete finding

- CLI commands were compared with the installed cached CLI source at
  `npm-cache/_npx/31e32ef8478fbf80/node_modules`: explicit Node entrypoint,
  named `-s` session, `open about:blank --config`, `run-code --filename`, and
  session-specific `close`. `cli-client/session.js:151-189` confirms detached
  daemon launch and the reported PID; `output.js:161` matches the parser.
  The reported PID is a daemon, not the Chromium leaf. Parent identity checks
  may legitimately refuse missed startup ancestry; no runtime success assumed.
- Cached `coreBundle.js:72261-72284` confirms explicit config loading and the
  PWTEST_CLI_GLOBAL_CONFIG override. Registry source confirms
  PWTEST_DAEMON_SESSION_DIR. The CLI entrypoint exits its update check for CI or
  NO_UPDATE_NOTIFIER. Supervisor supplies both and a narrow environment, explicit
  executable paths/hashes and run-local ini/config/cert/key paths.
- Inspected generated PowerShell identity/census/listener/kill commands, numeric
  PID validation, tick comparison before termination, own Popen handles, regular
  output spools and monotonic checks. No additional command syntax/API defect
  established statically. OS behavior, timing, startup races and process creation
  remain unverified; the 15-second reserve is not an OS hard real-time guarantee.
- Inspected offline about:blank startup, start-before-driver ordering, smoke's
  exact GET URL/method and single permit, route guard before going online, smoke
  invalidation and refusal to run business/post acceptance. Normal successful
  smoke returns offline; exceptional smoke relies on supervisor cleanup.
  This is not a packet-capture or hostile-network-containment claim.
- Read the full-driver wrapper and existing result fields/check count. Static
  asset delivery is explicitly blocking preflight pending root review, not a new
  defect. Root-provided hashes are review inputs, not automatic authorization.
- Cooperative B limitations, absence of Job Objects and uncertainty for unknown
  orphan ancestry are accepted constraints and are not reflagged here.

## Evidence and stop

Read-only source/Git inspection only. The root-reported 21 pure/mock PASS was not
rerun. No interpreter/test/OS probe/subprocess-under-review/server/browser/DB was
launched; no exact-run directory or manifest was read or written. No backend,
production, config, environment or dependency changes. Only this new report is
committed; staged document whitespace check is the sole check run here.
Stop for coordinator review; no runtime authorization or readiness claim.
