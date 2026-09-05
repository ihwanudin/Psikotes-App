# Backend browser ownership journal hardening

Status: code/test-only increment complete; runtime acceptance remains gated.

## Implemented boundary

- `supervisor.json` is created once with an exact owner PID/start tick/nonce, session, canonical configuration binding, and a persisted journal binding.
- The journal is an append-only bounded generation chain rooted in the immutable claim binding. Each exclusive generation file and append-only head entry is flushed with `fsync`, carries its generation and previous digest, and records the full launch-intent stack plus each owned PID, creation tick, role, exact parent identity, executable, and session/config provenance. Recovery requires the exact contiguous chain, so a stale valid generation cannot replace the latest state silently.
- Journal integrity is a canonical SHA-256 corruption detector cross-bound to the immutable claim digest. It is deliberately not described as authentication: a party able to rewrite both local artifacts can recompute these unkeyed digests.
- Recovery is a callable imported flow (`recover`) and requires an exact latest `{generation, digest}` anchor retained independently by its coordinator. It takes its own bounded process snapshot and refuses stale-prefix rollback, active-owner, session, tick, parent, executable, config, tool-content, malformed/truncated, duplicate PID/role, incomplete-pair, and unresolved-launch-intent mismatches.
- Journal deletion is gated by a private monotonic state reached only after exact `integrity-post` evidence validates, followed by an exact cleanup with no listener, live owned identity, or uncertainty. Public `postcheck_complete` mutation, failed/partial cleanup, and interrupted/incomplete runs retain the journal and invalidate the run.

## Verification

No browser, server, database, listener census, or process kill/start was executed for this increment.

- AST syntax parse: passed for supervisor and unit-test modules.
- Pure/mock unit suite: 50 tests passed after the final increment.
- Intentionally excluded: `test_real_listener_inspector_covers_ipv4_and_ipv6_wildcards_without_killing`, because it performs the prohibited host listener/process inspection.

Command used for the pure suite:

```text
python -B -c "import unittest; from tools.testing.tests.Browser.test_checkout_supervisor import SupervisorTests; excluded={'test_real_listener_inspector_covers_ipv4_and_ipv6_wildcards_without_killing'}; names=[n for n in unittest.defaultTestLoader.getTestCaseNames(SupervisorTests) if n not in excluded]; result=unittest.TextTestRunner(verbosity=1).run(unittest.TestSuite(SupervisorTests(n) for n in names)); raise SystemExit(0 if result.wasSuccessful() else 1)"
```

## Residual gate

This increment does not authorize the disabled runtime entrypoint or establish browser acceptance. In particular, the current disabled runner does not yet publish or persist the latest anchor in an independent coordinator-owned location, so standalone crash recovery is not claimed and remains gated. Managed runtime spawns after claim—including PHP/TLS, browser launcher, harness/run-code commands, and close CLI—are tracked. PowerShell identity/listener snapshot helpers remain explicitly untracked because tracking them would recurse through the identity probe needed to register them; their `Popen` handles are retained only within the live supervisor and are not claimed as crash-recoverable descendants. A crash after any tracked spawn begins but before its exact PID/tick can be journaled leaves a persisted launch intent and recovery fails closed with `recovery_incomplete`; an operator must resolve that host state under a separately reviewed process-ownership procedure. The unkeyed chain plus independent anchor detects local corruption and stale-prefix replacement under the stated artifact model but does not authenticate against an actor able to rewrite both local artifacts and the external anchor. Real Windows process lineage, anchor publication, append/fsync behavior, helper-process failure behavior, and cleanup must still be validated in the reviewed isolated manifest environment before P17c can be accepted.
