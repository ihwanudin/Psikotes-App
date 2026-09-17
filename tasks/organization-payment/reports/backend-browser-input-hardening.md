# Backend browser input hardening

Status: code/test-only increment complete; runtime acceptance remains gated.

## Implemented boundary

- Browser `launchOptions.args` is accepted only as one exact ordered list of three unique strings: the reviewed loopback-only host resolver rule, `--no-proxy-server`, and `--disable-background-networking`. Missing, reordered, duplicated, additional, non-string, alternate resolver, and proxy-conflicting arguments fail `browser_network_guard`.
- Recovery and descendant discovery share one strict snapshot-schema validator before relevance filtering or PID-map construction. The outer value must be a list; every row must be an exact-key object with non-boolean positive PID, non-boolean non-negative parent PID, globally unique PID, and either a canonical positive decimal tick plus absolute non-NUL executable path or a paired null tick/path. Zero and leading-zero ticks are rejected; legitimate Windows ticks remain within the accepted 1-20 digit positive range.
- One canonical positive-tick helper is shared by live identity probes, persisted claim owners, journal owned records and parents, and snapshot rows. Rechaining and republishing structurally valid JSON cannot make `0` or leading-zero owner/lineage ticks pass claim or journal validation before comparison or recovery hydration.
- The PowerShell snapshot probe stages tick and executable into temporary values and commits them to output only after both reads succeed. Its catch resets both fields to null and its finally block disposes every acquired process object, preventing a `MainModule` failure from emitting half-null identity evidence.
- Paired null identity evidence is allowed only for unrelated inaccessible rows. Persisted owner/owned/parent identities and every descendant reachable from them must have complete tick/path evidence. Recovery maps schema failures to `recovery_identity`; normal discovery refuses malformed evidence, and cleanup converts that refusal into an uncertain failed cleanup rather than treating the process as absent.

## Verification

No browser, server, database, listener census, or process start/kill was executed.

- AST syntax parse: passed for supervisor and unit-test modules.
- Pure/mock unit suite: 79 tests passed.
- Intentionally excluded: `test_real_listener_inspector_covers_ipv4_and_ipv6_wildcards_without_killing`, because it performs prohibited host listener/process inspection.
- Scoped `git diff --check`: passed.

## Residual gate

These tests prove parser and state-machine behavior against synthetic inputs only. The exact Windows CIM field behavior, inaccessible-process null pairing, executable-path availability, browser launch-argument delivery, and cleanup behavior still require the separately reviewed isolated runtime manifest and coordinator. This increment does not enable the disabled runner or establish browser acceptance.
