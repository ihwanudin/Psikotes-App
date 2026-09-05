# P17c coordinator anchor store

Date: 2026-09-06
Status: code/test-only persistence primitive complete; supervisor integration and runtime acceptance remain gated.

## Delivered boundary

`tools/testing/tests/Browser/checkout-anchor-store.py` provides an importable
`CheckoutAnchorStore`. Its bound `publish` method is suitable for the supervisor's
coordinator-owned publisher callback, but this increment does not wire or activate
that callback.

The caller must explicitly supply an existing coordinator directory, existing
candidate run directory, canonical checkout session, and exact configuration
binding. Coordinator storage is rejected when it is the candidate directory or a
descendant of it. Existing symlinks and Windows reparse points are rejected where
the Python runtime exposes them. The derived filename binds the normalized exact
run path, session, and configuration binding without placing those values in a log.

Each document has an exact bounded JSON schema containing only version, provenance,
and the strict `{generation,digest}` anchor. Reads use an OS file descriptor with
`O_NOFOLLOW` where available and compare the opened handle identity and type with
the non-reparse path identity before and after consuming bounded bytes. The captured
coordinator-directory identity is also revalidated around loads and publications.

Writes use a unique exclusive temporary file, flush and `fsync` it, atomically
replace the bound document, and synchronize the directory on platforms that support
directory `fsync`. An exclusive lock serializes cooperative publishers. Its handle
stays open through the transaction, and cleanup unlinks it only when the current
non-reparse path is still the exact opened identity. A changed or crash-retained
lock fails closed and remains for coordinator review. This is a bounded cooperative
single-writer protocol, not a claim of eliminating every hostile filesystem race.

Generation one must be first. Later writes must advance exactly once; an exact
same-generation replay is idempotent. Stale, skipped, reordered, or conflicting
anchors fail closed. A new store instance reopens the exact anchor together with
its run/session/configuration provenance. Oversized, empty, truncated, duplicate-key,
extra-key, wrong-type, malformed, or provenance-mismatched documents fail with fixed
codes. Exceptions do not include paths, stored values, or underlying error text.

## TDD and verification

The initial focused run failed because the implementation file did not exist. After
the first implementation, five tests exposed an invalid Windows attempt to `fsync`
a read-only reopened file; the redundant call was removed while retaining the
pre-replace file flush/`fsync`. A new boolean-version test then failed and tightened
the version type check.

Final focused evidence:

- `python -B tools/testing/tests/Browser/test_checkout_anchor_store.py`: **14 tests passed**.
- Cases cover new-instance reopen, provenance, copy isolation, exact replay,
  monotonic order, conflict/stale rejection, strict anchor types, bounded and
  duplicate-key JSON, live and broken symlink/reparse rejection where available,
  orphaned crash temp isolation, exclusive writer locking, opened-file identity
  mismatch, coordinator-directory replacement, constructor scope races with private
  OS errors, swapped-lock retention, and replacement failure preserving the prior
  anchor.

No browser, process census, service, database, network request, environment file,
provider, notification, or deployment operation was used.

## Residual gates

This local store adds corruption and rollback detection when combined with the
supervisor's journal chain; it is not authentication. An actor able to rewrite both
the candidate journal and coordinator storage can recompute the unkeyed values.

The disabled runner still needs separately reviewed wiring that makes this publisher
mandatory and supplies its loaded anchor to standalone recovery. The coordinator
must enforce exclusive single-writer ownership and reviewed directory ACLs. Real
Windows opened-handle identity, reparse/TOCTOU behavior, replace/flush durability,
crash remnants, coordinator directory ownership, historical process lineage,
PowerShell helpers, cleanup, disposable manifest/tool hashes, browser/service
startup, and browser behavior remain unverified. This report does not close P15,
P16, P17c, or authorize any runtime invocation.
