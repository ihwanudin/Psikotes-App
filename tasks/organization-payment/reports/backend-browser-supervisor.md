# Cooperative supervisor — implementation and pure/mock evidence

2026-09-05. One backend slice after accepted root integrations 1b925ba/2d9a0b3
and diagnostic be7a8ea/25a69fa. No reset/merge or exact disposable-run read/refresh.

## Delivered boundary

Three new files only:

- tools/testing/tests/Browser/checkout-supervisor.py
- tools/testing/tests/Browser/test_checkout_supervisor.py
- this report

The imported supervise() sequencing core has an explicit WindowsRun adapter.
Direct script execution intentionally exits without launching anything. Importing
it is inert; a later explicitly reviewed invocation can supply WindowsRun(config)
to supervise(). There is no default config discovery or implicit runtime command.
This slice neither registers a command nor authorizes that invocation.

Ordering is preflight -> free ports -> exclusive supervisor claim -> full
integrity-pre -> PHP -> TLS -> offline about:blank browser -> ownership/listener
proof -> integrity-start -> permitted driver work. Existing PHP guards and their
fixed 69 files are unchanged. All new subprocesses use CREATE_NO_WINDOW, direct
argument arrays and a small process-local environment. PHP still receives -n and
the reviewed run-local ini; TLS still uses upstream timeout10, browser navigation
still uses30 seconds. No session TTL, limiter or business timeout changes.

A cryptographically random session name prevents accidental reuse of an existing
CLI session. PID plus Windows creation ticks identify the owner and runtime roles.
The CLI-reported browser PID is a daemon, whose descendants must also be tracked.
The adapter records observed parent/child relationships only when the parent is
currently the same owned creation identity. It refuses PID reuse, unavailable
ticks and previously unobserved orphan ancestry; numeric PPID alone is not proof.
No process-name cleanup, close-all, kill-all or arbitrary PID termination is used.

Finally cleanup closes only the named owned browser session, checks descendants,
then terminates only identities matching their recorded creation ticks or its own
retained Popen handles. It checks all-family listeners on ports8126/443 and requires
zero remaining owned descendants/listeners. A foreign listener is a failure, never
an instruction to kill it. Partial-spawn handles are retained even if subsequent
inspection fails. Ambiguous ownership marks cleanup uncertain and prevents success.
The process census is cooperative observation, not a Windows Job Object or proof
against hostile/fleeting processes. Missing lineage can therefore stop a legitimate
run; it must not be worked around by adopting/killing an unknown orphan.

Command waits use a monotonic budget, regular temporary output files, bounded
capture/poll and own-handle cleanup. Inspector calls are capped at3 seconds within
the active deadline. Work has an explicit1..1200-second caller budget, default180;
cleanup has a15-second reserve and own-handle termination waits bounded to2 seconds.
These are supervisor bounds, not permission to lengthen existing browser/TLS/TTL
limits. Creation or an unresponsive OS is not a hard real-time guarantee. Output
spools may overshoot their polling cap before rejection; not a hostile disk quota.
No captured subprocess output or raw exception is returned in the summary.

## Smoke cannot become acceptance

Smoke accepts an integer request budget1..3. After start, each run-code invocation
permits exactly one GET to the fixed synthetic /__browser/login URL; other browser
requests are aborted. The context starts offline, gets its exact route guard
before going online, and is returned offline. Successful requests must meet the
5-second target. The summary requests count is a conservative count of granted
attempts, including a failed attempt; it is not a packet-capture claim.

Even successful smoke returns state=incomplete, accepted=false and
reason=fresh_run_required, after cleanup and an irreversible invalid latch. It
never invokes the full matrix, assertions-passed stop, business verifier or
integrity-post. A future full matrix requires a different fresh run, not repaired
or rearmed smoke evidence.

Full mode executes the existing unchanged browser driver after an abort-all guard
is installed and offline is released. Only its complete expected result permits
cleanup -> assertions-passed stop -> real business verifier -> another cleanup ->
full postcheck. Even then state=postverified and accepted=false: root review is
still required. Failed stages, interruption, partial claim, expired budget or
cleanup failure invalidate the run. No new entitlement/payment behavior exists.

## Driver and preflight sources

Read the installed CLI0.1.19 README/configuration schema and coreBundle.js directly
under npm-cache/_npx/31e32ef8478fbf80, not a guessed command or npx download. Source
confirms open about:blank --config, named -s session, run-code --filename, close,
reported daemon PID and hidden detached launch. The installed CLI entrypoint
skips update notification for NO_UPDATE_NOTIFIER/CI. The adapter uses the exact
approved CLI path and chromium-1234/chrome-win64/chrome.exe with reviewed hashes;
it never installs a browser or substitutes the CLI's default revision.

Source also confirms PWTEST_DAEMON_SESSION_DIR and PWTEST_CLI_GLOBAL_CONFIG.
They point inside run-local storage/framework, alongside the process-local
USERPROFILE/LOCALAPPDATA, avoiding shared daemon/global config directories.
Configuration must specify isolated/headless browser, offline=true,
serviceWorkers=block, explicit executable, no proxy/background networking and
resolver mappings only for the controlled loopback hosts plus NOTFOUND fallback.
Each executable/ini/config/cert/key hash is supplied by root, never recomputed as
an automatically approved value. Runtime ini/config/cert/key must be run-local.

[Python subprocess documentation](https://docs.python.org/3/library/subprocess.html)
provides argument-array, explicit environment, Popen ownership and Windows
CREATE_NO_WINDOW behavior, and documents the process-creation timeout limitation.
[Get-NetTCPConnection](https://learn.microsoft.com/en-us/powershell/module/nettcpip/get-nettcpconnection?view=windowsserver2025-ps)
is the listener inventory; inspection errors fail closed. Neither was executed
by these tests. No claim that mocks prove the Windows adapter's runtime timing.

Static assets are a BLOCKING preflight requirement. Root UI740f236 adds
/css/checkout-summary-v1.css and /brand/oncam-logo-full-color.png; the old exact
copy is not silently refreshed. Config requires a root-reviewed asset-delivery
map containing exact manifest hashes for both source public assets, summary view
and harness/router. It also verifies those files against the unchanged manifest.
A missing/mismatching review or file stops before claim/launch. This is an explicit
review attestation of those routes, not automatic route inference from file presence.
No generic public-file serving, route exception or harness guard change was added.

## Actual tests and next bounded authorization

TDD first run:12 tests,2 failures (partial claim skipped invalidation; full mode
never released offline). Both fixed before GREEN. Final:21 pure/mock unittest
tests PASS in0.003s, covering sequence, smoke1/2/3 and bounds, no smoke assertions
or postcheck, every-stage failure, crash/interrupt, partial spawn, occupied port,
cleanup failure, budget, slow request, full postconditions, child tracking,
PID reuse/orphan ambiguity, own-only cleanup, missing asset review, and no spawn
past deadline. Two Python files AST-parse successfully. Existing PHP64 pure checks
PASS. No Python dependencies installed and no bytecode cache generated (-B).
No browser/server/inspector/DB subprocess was executed by orchestration tests.
Pint/PHPStan do not cover these Python-only additions; no claim of rerunning them.
Prior52/161 lifecycle and62 real inspector evidence belongs to the accepted previous
slice and was not repeated here. git diff --check is required at scoped commit.

Concrete next authorization requested only AFTER code review: prepare one NEW
synthetic run/copy with a reviewed manifest and explicit asset-delivery overlay,
review exact config/executable hashes, initialize only its disposable fixture, and
run at most3 smoke request permits with a180-second work cap plus15-second cleanup
reserve. This includes precheck and blank-browser startup, not just HTTP time.
Each request must meet <=5 seconds with TLS10/browser30 unchanged. If preflight,
ownership, command API, daemon ancestry, cleanup or time target fails, stop and
report; no retries/rearm/timeout increase. This smoke authorization is not currently
granted by this report. A later full matrix needs another fresh run and separate
approval; the1200-second supported maximum is not authorization to execute it.

The real Windows adapter, CLI startup handshake, process-census timing, asset
responses and cleanup remain unexecuted. No actual runtime readiness or browser
acceptance claim. No exact-run access/refresh, app/harness edits, real .env/data,
active DB, provider/notifier, dependency/network installation, deploy/push, or new
task/agent. Preserve all unrelated dirty baseline. Commit only these three files
and STOP for coordinator review.
