# Bounded ownership safety follow-up for consumed candidate

This is a required read-only resource-safety follow-up for the consumed and
invalid-latched candidate
`C:/Users/ThinkPad/AppData/Local/Temp/oncam-checkout-4e23188e5906484e8655c7d8ce335364`.
It did not rerun smoke, import/call the supervisor, start a server/browser,
query the database, edit run/source/config, or kill a process. No raw PID,
command line, environment, cookie, HTML, key or personal data is included.

## Evidence available

The immediate sanitized runtime result recorded 7 PID/creation-tick identities:
4 were exact-alive, 3 missing and 0 reused immediately after supervisor return.
Direct open handles were 0. The fixed result was:

- `primary_reason=spawn_browser`;
- `cleanup_status=uncertain`;
- categories `parent_identity_mismatch`, `parent_missing`, `pid_reuse`;
- published roles `php`, `tls`; browser was never published;
- listeners on 8126/443 were absent.

The persisted fixed-marker projection now shows:

| Fact | Value |
| --- | --- |
| Supervisor accepted | false |
| Integrity evidence state | `preverified` |
| Persisted owned role count | 0 |
| Browser/business passed | false / false |
| Invalid latch | present |

The evidence owner itself is no longer alive at its recorded creation tick and
its PID was not reused in that census. Crucially, the seven-entry in-memory map
was never persisted because browser spawn failed before the integrity-start
transition. `evidence.owned=[]` therefore contains no resumable ownership map.

## Current bounded census

The current process census matched command lines internally against the exact
candidate directory and then emitted counts only:

| Current fact | Count |
| --- | ---: |
| Candidate-referencing processes | 1 |
| Creation timestamps available | 1 |
| Exact approved executable path | 1 |
| Parent currently present | 0 |
| Parent also candidate-scoped | 0 |
| All-family listeners on 8126/443 | 0 |

This proves one current process has a candidate-specific command line and an
approved executable path, but it does not prove that process is one of the seven
historically recorded identities. Its parent is missing, so exact ancestry also
cannot be established. The current creation timestamp has no retained historical
tick to compare against. The reduction from four immediate exact-alive identities
to one candidate process is observational only; it cannot safely map individual
historical identities or prove how the other three exited.

One preliminary census command had a local PowerShell syntax error and produced
no authoritative result; it launched or killed no candidate process. The corrected
single census above is the evidence used for this decision.

## Cleanup decision

Fixed action: `not_invoked_missing_recorded_map_and_lineage`.

The existing supervisor cleanup API requires the original PID plus exact creation
tick map. Reconstructing a new map from path/name/port/current PID would violate
the own-only boundary. Calling cleanup with the persisted marker would do nothing
because its owned map is empty; adopting the current process would be unsafe.
No kill by name, path, port or PID alone was attempted.

The candidate remains invalid-latched and must not be reused or rearmed. The
remaining candidate-referencing process should be treated as requiring user or
host-operator action with direct process visibility; automation must not close it
without original identity and lineage proof. Ports 8126/443 are currently free,
but that does not authorize another run.

For a later code change, retain a bounded crash-safe cleanup record before browser
spawn can orphan descendants: exact PID/creation tick, fixed role/category and
parent identity, integrity-protected and scoped to the run. Clear it only after
exact-handle cleanup is proven. A recovery cleanup must reject missing parent,
tick mismatch, unknown executable, or changed candidate-specific session config.
This report does not implement that design.

No next runtime or cleanup attempt is assumed. Git cached diff check passed with
only this report staged. Stop for coordinator review and resource-safety decision.

## Bounded read-only recheck — 2026-09-07

The initial literal-path probe matched its own census command. That result was
contaminated by the observer and is discarded as non-evidence. The corrected
split-literal probe avoided embedding the candidate path in the census command;
two consecutive snapshots both reported candidate-referencing process count
**0**. The probes themselves succeeded. All-family listener counts were also
**0** on port **443** and **0** on port **8126**.

The exact candidate directory still exists and its `integrity-invalid` latch is
present. This closes only the point-in-time resource blocker. Historical cleanup
and lineage remain unprovable; the candidate is invalid and must never be reused,
rearmed, or treated as recoverable. Native provider, sealed input authority, and
browser P17c remain blockers.

This recheck did not build a candidate, start a browser/server, inspect a
database, invoke native/provider behavior, read environment configuration, use
outbound network access, deploy, or kill any process.
