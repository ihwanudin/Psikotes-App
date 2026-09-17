# Read-only postmortem: smoke stopped during integrity start

This postmortem reads only bounded, nonsecret markers and pinned source from the
already invalid run
`C:/Users/ThinkPad/AppData/Local/Temp/oncam-checkout-49c23c96866b46ddb7d426dbe713c15d`.
It did not rerun or import the supervisor, query database rows, launch a
supervisor/application child, kill any process, inspect a key, cookie, HTML or
PII, edit run artifacts, or access older runs. No code or environment fix is
implemented here.

## Earliest supported failure stage

The fixed marker census found `supervisor.json`, `integrity-evidence.json` and
`integrity-invalid`; `violations.txt`, `diagnostic-stages.jsonl` and runtime
`.log` files are absent. The sanitized marker projection is:

| Marker fact | Value |
| --- | --- |
| Supervisor mode / accepted | `cooperative` / false |
| Evidence version / state | 1 / `preverified` |
| Evidence owned roles | 0, empty array |
| Browser passed / business verified | false / false |
| Verifier present | false |
| Evidence owner current census | 1 recorded, 0 exact alive, 1 missing, 0 PID-reused |
| Invalid latch | present |

The prior sanitized wrapper evidence also proves all three roles were started,
the supervisor tracked 16 identities, `requests=0`, and returned
`reason=cleanup`. These facts support the following boundary:

1. Preflight, corrected port inspection, claim and `integrity-pre` completed;
   otherwise version-1 `preverified` evidence would not exist.
2. All PHP, TLS and browser launch calls returned; all three role names were in
   the runtime projection.
3. The request permit counter increments immediately before the first smoke call
   (supervisor lines 77–80). Its zero value proves execution never reached the
   first smoke invocation.
4. Evidence is still `preverified` with no owned roles. A successful
   `integrity-start` changes those exact fields to three roles plus `serving`
   (harness lines 955–968).

Therefore the **earliest supported primary failure is supervisor stage `start`,
during the integrity-start harness command and before its evidence transition
was durably published**. The available markers cannot safely narrow it to a
specific subprocess check within that command. There was no HTTP or asset stage.

The top-level result lost this primary stage. `supervise` first catches the error
and assigns the current stage at lines 96–99, then its `finally` invokes cleanup.
When cleanup returns false, lines 110–111 replace that reason with `cleanup`.
That replacement explains the observed result; it does not mean cleanup was the
first failure.

## Ownership uncertainty

The 16 runtime identities were an in-memory transitive census, not the three-role
evidence record. `_command(track=True)` records each short-lived CLI/harness
process (lines 227–242); `_discover` then adopts descendants recursively (lines
269–290). Thus a count above the three long-lived roles is expected during CLI,
browser and integrity helper activity. The persisted evidence remained empty
because integrity-start did not publish its transition.

Current bounded census found no candidate-path process, no 8126/443 listener and
no persisted role identity. All direct handles were already exited. This is
consistent with cleanup of short-lived processes, but `ownershipUncertain=true`
must remain authoritative.

The source has five ways to set uncertainty without recording which occurred:

- tracked process identity registration failure, lines 229–234;
- same PID with different creation tick, lines 272–274;
- an observed child whose numeric parent is owned but no longer has the exact
  live parent identity, lines 278–285;
- missing/older child creation tick, lines 286–287;
- launch identity failure or cleanup handle failure, lines 355–359 and 435–442;
  a known process alive after postcheck is another later case at 408–424, but
  postcheck was never reached here.

Normal short-lived CLI or harness descendants can explain why 16 identities were
seen and later disappeared. They do **not** prove which uncertainty branch fired.
Likewise the evidence shows no PID reuse, but it contains only the owner and not
the transient in-memory census. The artifacts cannot distinguish benign
parent-before-child disappearance from a real orphan/reuse/timestamp anomaly.
Claiming one trigger would exceed the evidence.

## Defect and proposed correction

This is primarily a **code observability/state-result defect**, not a supported
environment failure. The lifecycle correctly fails closed, but it destroys the
first actionable reason and stores uncertainty as an untyped boolean.

Preserve two fixed, payload-free dimensions:

- `primary_reason`: the first lifecycle stage/code, immutable once caught
  (`start` for this evidence);
- `cleanup_status`: fixed enum such as `not_required`, `clean`, `failed`, or
  `uncertain`.

Keep `state=invalid` and `accepted=false` whenever either dimension fails. Do not
replace `primary_reason` when cleanup fails. If compatibility requires `reason`,
derive it deterministically while retaining both new fields; do not make cleanup
success able to hide a primary failure.

For ownership diagnostics, retain a bounded set of fixed reason flags only, such
as `pid_reused`, `parent_identity_lost`, `child_tick_invalid`,
`identity_probe_failed`, and `handle_cleanup_failed`. Never persist PID, command
line, path, environment or raw exception payload in the result. A fixed count per
category is sufficient.

Tests required before another environment run:

1. failure at each primary stage plus cleanup `clean`/`failed`/`uncertain`
   preserves the same primary reason;
2. cleanup failure cannot turn a `start` failure into an apparent cleanup-only
   failure, and successful cleanup cannot make it accepted;
3. each uncertainty assignment emits only its fixed category and no identity;
4. a tracked short-lived child that exits normally does not become PID reuse,
   while parent loss, tick mismatch and invalid tick remain fail closed;
5. BaseException paths still execute bounded cleanup and preserve the primary
   failure when a result can be returned;
6. result-schema compatibility and all existing supervisor lifecycle/cleanup,
   IPv4/IPv6 and listener-classification tests remain green.

No next smoke is justified from environment changes: the earliest stage and
uncertainty trigger need the code result/evidence correction and focused tests
first. Any new preparation or runtime still requires separate review and
authorization. `git diff --cached --check` passed with only this report staged.

## Reviewed code correction

The approved correction now keeps four exact result dimensions:
`state`, `accepted`, immutable `primary_reason`, and `cleanup_status`. The legacy
`reason` key remains as a compatibility alias of `primary_reason`; cleanup cannot
replace it. `cleanup_status` is exactly one of `not_required`, `clean`, `failed`,
or `uncertain`. Request counting and accepted/state semantics are unchanged.

The cleanup result is merged by severity across the shared cleanup allowance:
`uncertain` outranks `failed`, which outranks `clean`; a later clean pass cannot
erase an earlier problem. A primary lifecycle exception is captured once. When
the lifecycle itself completed but its required cleanup is the first failure,
the immutable primary reason becomes `cleanup`. Smoke success still returns
`primary_reason=fresh_run_required`, `cleanup_status=clean`, state incomplete and
accepted false; full success retains `root_review_required` and postverified.

`WindowsRun.uncertain` is now a read-only compatibility property derived from a
bounded internal set. The only allowed categories and assignment-site counts are:

| Fixed category | Assignment sites |
| --- | ---: |
| `pid_reuse` | 1 |
| `parent_missing` | 1 |
| `parent_identity_mismatch` | 1 |
| `child_tick_invalid` | 1 |
| `identity_probe_failed` | 2 |
| `postcheck_live_process` | 2 |
| `cleanup_exception` | 2 |

Each former boolean assignment now records one of these constants. Unknown or
caller-supplied category data is never echoed: result projection substitutes the
fixed `cleanup_exception` fallback when uncertainty exists, and `_mark_uncertain`
rejects non-enum categories with fixed `internal`. No PID, path, command, raw
exception or environment enters the result. Cleanup remains exact-owner-only and
any uncertainty still prevents success.

TDD evidence:

- RED before implementation: **33 tests**, five `KeyError` errors from the new
  result/cleanup matrix because the dimensions did not exist.
- Intermediate checks exposed and corrected old expectations for overwritten
  cleanup reason, the read-only property, and simultaneous PID/parent mismatch.
- Final regression: **34/34 tests PASS in 4.635 seconds**, retaining all 32 prior
  listener/lifecycle tests plus two new grouped tests.
- New pure/mock matrix covers preclaim/no cleanup, start failure with clean or
  uncertain cleanup, spawn failure plus failed cleanup, cleanup as first failure,
  smoke/full success, and untrusted category sanitization.
- Assignment-site test enumerates all seven categories and all ten call sites,
  triggers PID reuse, parent missing/mismatch and invalid child tick, and proves
  arbitrary category refusal. Existing tests exercise identity, postcheck and
  cleanup failure sites.
- The two new pure/mock tests pass independently: **2/2 in 0.001 seconds**.
  Python AST parsing passes for both changed files; diff check passes.

The full 34-test regression includes the already accepted controlled local
IPv4/IPv6 listener test; it launched no browser, PHP server or database and closed
only its owned sockets. No exact run, source copy, new preparation, supervisor
lifecycle, application process or outbound operation was performed. A future
diagnostic requires a fresh candidate and separate authorization after review.
