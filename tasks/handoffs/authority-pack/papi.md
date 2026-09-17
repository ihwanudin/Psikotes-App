# PAPI Kostick authority decision pack

Date: 2026-09-14

Candidate baseline: `274c43de45f9f16e4a3f8cb33e377f697d6e9f73`

Requested branch: `codex/f2-wave1-integration`

Observed checkout: detached HEAD at the exact candidate baseline; no content
drift was present before this file was created.

## Decision

**BLOCKED — readiness 0/4. Import-ready: NO. Start-ready: NO.**

PAPI scoring and choice-to-dimension mapping are versioned, but there is no
approved fixed 90-item administration form. The current evidence does not bind
the exact paired statements, form version, immutable provenance, checksum,
duration, subtest encoding, rights, or an instrument-specific approval. No
manifest, seed, catalog row, or session-start activation may be derived from
this pack.

## Decision-ready evidence matrix

| Decision field                         | Evidence available at candidate baseline                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    | Required final evidence or attachment                                                                                                                                                                                                                                                                  | Authority decision                                                                                                 | Status           |
| -------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------ | ---------------- |
| Final evidence package                 | `Master Kamus Tes PAPI Kostick.xlsx` provides a 90-row ROLE/NEED choice-to-dimension mapping, and `papi.json` preserves scoring mappings. Neither is the fixed participant-visible form because the 90 paired statement texts and full delivery instructions are absent.                                                                                                                                                                                                                                                                                                    | One immutable, access-controlled PAPI administration package plus a signed manifest enumerating every ordered statement pair, choice label, instruction, and participant-visible asset.                                                                                                                | Mapping data cannot substitute for item-text authority.                                                            | **BLOCKED**      |
| Provenance and source                  | Source precedence is PRD 1.3 -> final psychologist confirmation -> Tabel Lookup Skoring v1.1 -> golden test -> non-conflicting older technical material (ADR-0028). The inspected mapping workbook is `D:/LSI/Psikotes/PSIKOTEST LSI/PSIKOTEST/Master Kamus Tes PAPI Kostick.xlsx`, sheet `Dimensi Mapping`, cells `A4` and `A7:D97`, SHA-256 `9c97a8d84f8a89f86536d475a68d96e88cdb598de7215302da1a42da3e5056a6`. The same-named copy one directory higher has the same hash, but no signed provenance statement designates a canonical source edition or chain of custody. | A provenance statement naming the exact source edition/path, issuer or custodian, issue date, derivation history, and authoritative relation between the mapping and final text form.                                                                                                                  | Matching file bytes reduce ambiguity but do not establish provenance or authorization.                             | **BLOCKED**      |
| Administration version                 | `F2-2026.09` is the normalization-data version under `SCORING-4.3.0`; it is not a PAPI form version. No canonical licensed 90-item form version is assigned.                                                                                                                                                                                                                                                                                                                                                                                                                | A unique, immutable administration-form version supplied by the authorized approver and bound to the exact statement package.                                                                                                                                                                          | Do not reuse a scoring-data or workbook version as the form version.                                               | **BLOCKED**      |
| Checksums                              | Current Git object bytes for `database/seeders/data/papi.json` hash to SHA-256 `b827f01de1c9c651979d31b9aed67b80932fc007bcd97fbeb63af53e5f8b12e6`. The earlier four-instrument audit records `f5ee0ff8f5ad9f01cfec3de45cfa2c2248c21921307e52e7bef064958a10e28a`; that value does not match the candidate baseline and must not be repeated as verified file evidence. Neither value is a catalog `template_checksum`.                                                                                                                                                       | A checksum inventory covering the exact 90 ordered statement pairs, choices, instructions, and other assets, followed by the canonical `SessionDefinition::checksumFor(...)` value after the definition is complete. The discrepancy in the audit's JSON hash also requires correction or explanation. | Mapping hashes do not bind absent text and do not confer approval, provenance, or rights.                          | **BLOCKED**      |
| Duration                               | PRD 1.3 does not specify a PAPI duration. An older technical diagram writes `PAPI (20)`, but it does not establish a current approved unit or timer definition and cannot be converted to 1,200 seconds by assumption.                                                                                                                                                                                                                                                                                                                                                      | Signed timer decision stating exact total seconds, pause/resume/expiry behavior if relevant, and the authority/source for that value.                                                                                                                                                                  | Duration is unassigned.                                                                                            | **BLOCKED**      |
| Subtest encoding                       | No approved PAPI subtest partition or runtime code is supplied. Treating all 90 pairs as one subtest may be plausible technically but is not approved evidence.                                                                                                                                                                                                                                                                                                                                                                                                             | Approved ordered subtest list with exact `code`, `duration_seconds`, and `item_count`, including an explicit decision whether the form is one 90-item segment or another fixed partition.                                                                                                              | No subtest code or partition may be inferred.                                                                      | **BLOCKED**      |
| Fixed 90-item count and text authority | SCORING-4.3.0 requires exactly 90 forced-choice items, 45 ROLE and 45 NEED, producing 20 dimensions on 0-9. PRD FR-08b prohibits item and option randomization. The mapping workbook gives 90 A/B dimension mappings, but not the actual paired statements.                                                                                                                                                                                                                                                                                                                 | Approved fixed ordered list of all 90 statement pairs, exact A/B choice labels, instructions, response encoding, item-to-dimension mapping, and an attestation that the text and ordering match the licensed form/version.                                                                             | The 90-row mapping proves scoring shape only. Text/version/provenance/checksum remain unresolved.                  | **BLOCKED**      |
| Rights and license                     | PRD §10 and §14 name PAPI usage rights as a pre-go-live prerequisite. No license, permission letter, procurement record, rights-holder statement, territory/channel restriction, expiry, or redistribution condition was found in the reviewed repository evidence.                                                                                                                                                                                                                                                                                                         | Executed license or written authorization identifying the rights holder, licensee, exact fixed 90-item form/version, web/digital administration rights, territories, participant population, term, storage/display restrictions, and translation/adaptation permissions.                               | Rights must be documented for the exact form; a scoring mapping is not a license.                                  | **BLOCKED**      |
| Named approver                         | PRD 1.3 names **Rizqi Ulin Nuha, S.Psi., Psikolog — SILP-D8A35113BB4D** as the psychometric responsible person. No instrument-specific signed approval for a fixed 90-item PAPI package was found. No named rights/licensing approver is established by the reviewed evidence.                                                                                                                                                                                                                                                                                              | Dated approval signed by the named psychometric authority for the exact text, order, duration, encoding, version, provenance, and checksum; separately attach authorization from the rights holder or its authorized representative.                                                                   | The PRD role identifies the required psychometric review path but is not evidence that any PAPI form was approved. | **BLOCKED**      |
| Import-ready acceptance                | The immutable catalog requires exact instrument, version, provenance, total duration, ordered subtests with duration and count, fixed randomization declaration, canonical content, and matching checksum. The text form, duration, encoding, version, provenance, rights, and approval are incomplete.                                                                                                                                                                                                                                                                     | Completed manifest and attachments above; typed validation; reviewed catalog-import procedure; disposable PostgreSQL acceptance; no production activation.                                                                                                                                             | Import acceptance remains fail-closed.                                                                             | **BLOCKED — NO** |
| Start-ready acceptance                 | ADR-0030 requires a unique active server-side definition inside the trusted atomic start command. No approved definition can be imported or activated from current evidence.                                                                                                                                                                                                                                                                                                                                                                                                | Import-ready acceptance first; then unique active-row verification, provider checksum validation, trusted-start integration evidence, and the relevant PostgreSQL/HTTP/browser gates.                                                                                                                  | Start readiness cannot precede import readiness.                                                                   | **BLOCKED — NO** |

## Four-gate readiness

| Gate                              | Acceptance condition                                                                                              | Result      |
| --------------------------------- | ----------------------------------------------------------------------------------------------------------------- | ----------- |
| 1. Canonical content and identity | Fixed ordered 90-pair text package, canonical form version, immutable provenance, and verified checksum inventory | **BLOCKED** |
| 2. Administration encoding        | Exact duration and approved ordered subtest code/count representation                                             | **BLOCKED** |
| 3. Rights                         | Executed evidence authorizing this exact PAPI form for digital administration                                     | **BLOCKED** |
| 4. Named approval                 | Instrument-specific sign-off binding gates 1-3 and the final canonical checksum                                   | **BLOCKED** |

**Readiness: 0/4.** Import-ready and start-ready remain **NO**.

## Required attachments

1. `PAPI fixed-form manifest` identifying the canonical 90-item form/version,
   provenance, custodian, issue date, fixed ordering, and all file hashes.
2. `PAPI statement-pair inventory` containing all 90 ordered paired statements,
   exact A/B choice labels, instructions, response encoding, and mapping linkage
   in an appropriately access-controlled attachment.
3. `PAPI timing and subtest decision` supplying exact seconds and the approved
   runtime code/partition.
4. `PAPI rights evidence` covering the exact fixed form and web delivery.
5. `PAPI psychometric approval` signed and dated by the named authority,
   binding the exact form manifest and checksum inventory.
6. `PAPI import validation record` containing canonical template checksum,
   typed validation, and disposable PostgreSQL results after gates 1-5 pass.

## Unanswered questions

1. What is the exact authoritative text of each of the 90 fixed paired
   statements and each A/B choice label?
2. What is the canonical form/version identity, distinct from `F2-2026.09`?
3. Who issued and currently controls the authoritative form, and what chain of
   custody links it to the choice-to-dimension mapping?
4. What is the approved total duration in seconds?
5. Is PAPI encoded as one 90-item subtest or another fixed partition, and what
   are the exact runtime code(s) and per-part duration(s)?
6. Who owns or controls the rights, and what permission covers web delivery,
   storage, display, and any translation or adaptation?
7. Will Rizqi Ulin Nuha approve the exact text/version/checksum package, and who
   is the named licensing signatory authorized to bind the rights holder or
   licensee?
8. Why do the current `papi.json` bytes and the checksum printed in the earlier
   manifest audit disagree, and which checksum record will be formally
   corrected?

## Tech Lead then QA handoff contract

**Tech Lead review first:** verify that the fixed 90-pair attachment maps
losslessly to the typed `SessionDefinition`, approve the exact duration and
subtest encoding, recompute the canonical checksum, and confirm that neither
the scoring version nor the mapping workbook is being promoted to item-text or
rights authority.

**QA review second:** only after Tech Lead acceptance, verify all attachment
hashes, exact 90-item and 45/45 invariants, fixed order/options, timer
boundaries, negative cases for missing or altered evidence, typed import
validation, unique active definition behavior, and fail-closed start behavior.
QA must return the pack to `BLOCKED` if any statement, attachment, approval,
rights term, or hash binding is absent.

## Sources reviewed

- PRD 1.3 FINAL (2026-09-08), especially FR-05, FR-08b, §10, and §14.
- SPEC v4.3 §§4.2, 8A.5, 13, and 14.
- SCORING-4.3.0 §§1 and 4.
- ADR-0028 and ADR-0029.
- `tasks/f2-f9-acceptance.md`.
- `tasks/handoffs/f2-four-instrument-authority-manifest-audit.md`.
- `tasks/handoffs/f2-adr0030-start-flow-readiness.md`.
- `tasks/handoffs/integration-2026-09-13-wave1.md` and
  `tasks/parallel-work.md`.
