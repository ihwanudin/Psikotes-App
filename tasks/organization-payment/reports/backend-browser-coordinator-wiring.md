# P17c coordinator anchor wiring

Date: 2026-09-06
Status: code/test-only adapter and assembly complete; no runtime runner or acceptance.

## Delivered boundary

`tools/testing/tests/Browser/checkout-coordinator.py` resolves the interface between
the supervisor and the coordinator-owned anchor store without activating either.
`SupervisorAnchorPublisher.__call__` delegates publication to
`CheckoutAnchorStore.publish`. Its `load()` path requires the store's exact bounded
version/run/session/configuration provenance wrapper and returns only a fresh strict
`{generation,digest}` copy expected by the supervisor. Malformed or mismatched
wrappers fail with a fixed coordinator code and do not expose stored values or
paths. Before every publish or load, the adapter recomputes the bound run's
configuration binding for its captured session and checks that the captured
normalized run, session and store provenance remain unchanged. Mutation refuses
before store I/O. The captured operational identity also pins the exact store
object, coordinator directory and directory identity, anchor and lock paths, plus
the original bound publish/load functions and their store receiver. Calls use those
captured bound methods rather than mutable attributes, validate again afterward,
and require the run's currently attached publisher to remain this adapter.

`assemble_fresh` accepts only an explicit configuration dictionary and an existing
coordinator directory. It constructs a side-effect-free `WindowsRun`, computes the
configuration binding with the supervisor's own `_config_binding` implementation,
constructs the bound store and attaches the adapter before returning the still
unclaimed run. The explicit configuration is defensively deep-copied before it is
given to `WindowsRun`, so later top-level or nested caller mutation cannot alter the
bound run. Attachment uses the supervisor's one-time `bind_anchor_publisher` API;
direct public-field assignment is not an assembly path. The supervisor additionally
pins the publisher's callable implementation, which the adapter relies on alongside
its own store-method and provenance checks. This internal same-tool coupling is
intentional: duplicating the binding algorithm would create two security contracts.
Parity is pinned by tests.

`assemble_recovery` additionally requires an explicit canonical checkout session.
It assigns that session to the recovery candidate before binding, binds a recovery
store, loads and validates the exact persisted wrapper, and
returns the recovery candidate plus the raw anchor. It does not call `recover`, take
a process snapshot, clean up anything or start a subprocess. The candidate is marked
`recovery_ready`, so it cannot be mistaken for a fresh claim; only the existing
supervisor recovery path may hydrate it.

There is no environment lookup, default configuration discovery, directory
creation, fallback store, logging, `__main__` entrypoint or runtime activation.

## TDD and verification

The focused test was first run before the implementation existed and failed all
eight initial cases with `FileNotFoundError`. The minimum adapter and assembly then
made the contract green. Follow-up adversarial tests reproduced caller aliasing,
mutable run/store binding and recovery-session drift before the hardening change.

Final code-only evidence:

- `python -B tools/testing/tests/Browser/test_checkout_coordinator.py`: **12 tests passed**.
- `python -B tools/testing/tests/Browser/test_checkout_anchor_store.py`: **14 tests passed**.
- Pure/mock supervisor suite excluding the explicitly host-inspecting listener test:
  **88 tests passed**.
- Python AST parse for the coordinator and its tests: **2 passed**; import check:
  **passed** with no assembly or runtime side effect.

The coordinator tests cover strict wrapper/provenance validation, delegation and
copy isolation, defensive configuration copying, sequential run/store/path/method
and publisher mutation refusal before store I/O, post-operation drift detection,
fresh claim publication, exact internal binding parity, recovery
anchor loading without recovery, wrong session/configuration, locked publication,
corrupt storage, missing/inside-candidate coordinator paths, no directory creation,
the recovery-only phase and absence of subprocess/environment use.

No browser, process census, server, listener, database, network request, provider,
notification, environment file, deployment or source activation was used.

The supervisor remains a cooperative single-owner tool. Concurrent in-process
mutation by hostile code is unsupported: the adapter's postcheck detects identity
drift, but it cannot roll back a store write that completed before that drift became
observable. Runtime callers must not share or expose these mutable Python objects.

## Residual gates

The repository still has no enabled runtime runner or committed runtime
configuration/manifest loader. `checkout-supervisor.py` retains its disabled
`__main__` boundary. This adapter does not authorize a claim, recovery or spawn.

All historical disposable candidates, manifests, configuration bindings and hashes
predate the current supervisor/store/coordinator sources and must not be patched or
rearmed. A future separately authorized preparation must create a fresh immutable
source archive and full manifest, include and hash the coordinator/store/supervisor
and their tests plus the current browser harness, rebuild the supervisor config and
binding, verify every explicit tool/config hash, and generate a fresh reviewed
synthetic certificate rather than reuse the expired historical certificates.

Coordinator-directory ACL and single-writer ownership, Windows descriptor/reparse
and replace/flush behavior, anchor durability across a real crash, PowerShell helper
failure, exact process lineage/listeners/cleanup, fresh SQLite fixtures, ports,
TLS/browser/service startup and browser behavior remain host-gated. This report does
not close P15, P16, P17c or P18 and does not authorize runtime invocation.
