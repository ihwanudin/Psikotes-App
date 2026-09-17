# F2 four-instrument session-definition authority manifest audit

Date: 2026-09-13

Requested baseline: `a522f47`

Scope: IST, PAPI Kostick, RMIB, and Kraepelin session-definition authority only

## Result

**Import-ready: 0/4. Start-ready: 0/4.**

The repository has a strict, immutable catalog and substantial versioned
**scoring** data, but it does not yet have an approved four-entry
**session-definition** manifest. No active catalog row can be derived from the
available sources without either inventing an identity, treating scoring data
as item authority, omitting a required timer, or choosing between conflicting
Kraepelin administration rules.

This report does not authorize an import. Raw source-file SHA-256 values below
identify the files inspected; they are **not** the catalog
`template_checksum`. That checksum can only be computed after every field of an
approved canonical template is complete.

## Authority and catalog boundary

- Source precedence is PRD latest -> final psychologist confirmation -> Tabel
  Lookup v1.1 -> golden test -> older technical documents, per PRD 1.3
  "Rumus & skoring" and ADR-0028.
- The catalog requires exact `instrument`, `version`, `provenance`,
  `total_duration_seconds`, a non-empty ordered `subtests` list containing
  `code`, `duration_seconds`, and `item_count`, plus `randomization`, `seed`,
  and `generator`; it recomputes a canonical SHA-256 over all fields
  (`app/Domain/AssessmentSessions/SessionDefinition.php:12-36, 73-120,
  173-231`).
- IST, PAPI, and RMIB must be fixed with no seed/generator; the current typed
  contract requires Kraepelin to be seeded with named algorithm/version and the
  exact 50 x 15-second x 28/27 shape
  (`SessionDefinition.php:239-312`).
- The database permits one active definition per instrument and immutable
  history (`database/migrations/2026_09_10_000200_create_assessment_session_definitions.php:17-46,
  116-160`). The provider fails closed when there is no unique active row and
  validates scalar identity plus checksum before issue
  (`app/Services/AssessmentSessions/DatabaseAssessmentSessionDefinitionAuthority.php:33-76,
  83-122`).
- ADR-0030 requires this server-side authority inside the trusted atomic start
  command. ADR-0031 does not allow a caller to supply or guess it. Passing a
  scoring fixture therefore cannot make a session start-ready.

## Required four-entry manifest

`Required definition version` and `required provenance` below deliberately say
**unassigned** where no approver has supplied a canonical identity. A filename,
workbook version, Git hash, or SHA-256 must not be promoted to that identity
silently.

| Instrument | Required definition version | Required provenance / checksum | Timing authority | Item or generator authority | Scoring-data authority | Unresolved conflict or missing approval | Import-ready | Start-ready |
|---|---|---|---|---|---|---|---|---|
| IST | **Unassigned.** Must identify the approved administration form, not reuse scoring version `F2-2026.09`. | **Unassigned.** Approval must bind the complete fixed item/option assets, ordered subtests, timers (including ME phases), and rights basis. Only then compute canonical `template_checksum`. Inspected timing workbook SHA-256: `23ceadf36db46a08a4466515f3ff794fce2f6126e10f5fbe8c8455bffff47bf8`. | Strong evidence for ordered SE 360s, WA 360s, AN 420s, GE 480s, RA 600s, ZR 600s, FA 420s, WU 540s, ME 540s; total 4,320s: `Master Kamus Tes IST.xlsx`, sheet `Struktur Tes IST`, `A7:C17`; also SPEC §4.1 and SCORING_ALGORITHM §3. PRD FR-08 additionally requires **two timers** for ME, but no source splits its 540s into learn/recall phases. The same workbook `A4` says “sekitar 75 menit”, while `C17` totals 72 minutes; older technical-session diagram says IST 90 minutes. | Fixed/no randomization under PRD FR-08b and final confirmation item 2. SCORING_ALGORITHM §3 establishes counts SE20, WA20, AN20, GE16, RA20, ZR20, FA20, WU20, ME20 (176), and `ist.json` contains answer keys/norms, but the inspected workbook contains structure/norms rather than the licensed 176 item texts/options and figural FA/WU assets. PRD FR-08 explicitly requires those assets; PRD go-live prerequisites require IST usage rights. | `database/seeders/data/ist.json`, version `F2-2026.09`, SHA-256 `283f59df8c5a48c4c581e0e7db3014cf125275f0ab7b793300396619f5a7e6f9`; Tabel Lookup v1.1 sheets `01`-`04`; ADR-0028. This authorizes scoring transforms, not session content. | ME phase durations and representability are unresolved; approved item/form version, full item/option/figural asset manifest, immutable provenance, and usage-right evidence are absent. The catalog's one-duration-per-subtest shape also cannot express FR-08's two ME timers without an approved encoding/contract decision. | **No** | **No** |
| PAPI | **Unassigned.** Must identify the licensed fixed 90-pair form separately from scoring version `F2-2026.09`. | **Unassigned.** Approval must bind all 90 statement pairs in order, choice labels, duration, and rights basis. Inspected mapping workbook SHA-256: `9c97a8d84f8a89f86536d475a68d96e88cdb598de7215302da1a42da3e5056a6`. | PRD has no duration. The older technical-session diagram writes `PAPI (20)` but does not establish a current approved timer unit/definition; it loses to newer sources and cannot be imported as 1,200s by assumption. No approved subtest partition/code is supplied either. | Fixed/no item or option randomization under PRD FR-08b and final confirmation item 2. `Master Kamus Tes PAPI Kostick.xlsx`, sheet `Dimensi Mapping`, `A4` and `A7:D97`, provides the 90 ROLE/NEED choice-to-dimension mapping, but not the actual paired statement texts. The JSON preserves mappings, not a licensed deliverable form. PRD go-live prerequisites explicitly require PAPI usage rights. | `database/seeders/data/papi.json`, version `F2-2026.09`, SHA-256 `f5ee0ff8f5ad9f01cfec3de45cfa2c2248c21921307e52e7bef064958a10e28a`; Tabel Lookup v1.1 sheets `08`-`09`; ADR-0028. | Approved duration, subtest encoding, statement-pair form/version, immutable provenance, and usage-right evidence are absent. Choice mapping alone is insufficient item authority. | **No** | **No** |
| RMIB | **Unassigned.** Must identify the approved fixed sex-specific 9 x 12 occupation form separately from scoring version `F2-2026.09`. | **Unassigned.** Approval must bind the 108 ordered male/female labels, rotation/category key, duration, and rights basis. Inspected workbook SHA-256: `53844211fbec5d99ce7a4c91f9f97b379035752ded76351ad347b6943121e5b9`. | PRD has no duration. The older technical-session diagram writes `RMIB (15)`, but no current approved timer unit/definition or subtest partition/code is supplied; 900s cannot be inferred into an active row. | Fixed/no item or option randomization under PRD FR-08b and final confirmation item 2. `RMIB_Master_Formula_Skoring.xlsx`, sheet `Input Jawaban`, `A1:F109`, contains groups A-I, positions 1-12, male/female occupation labels, rotation formulas, and rank input; `Panduan!A16:A26` explains the 9 x 12 rotation. This is the closest complete content candidate, but no psychologist-approved administration-form version/provenance manifest or rights determination binds it for delivery. | `database/seeders/data/rmib.json`, version `F2-2026.09`, SHA-256 `fc5d6db938fed495892be64566e505e9403a83368c2166f3bfb99d6d29e969d9`; Tabel Lookup v1.1 sheets `10`-`11`; ADR-0028. | Approved duration, subtest encoding, administration-form/version identity, immutable provenance, and rights determination are absent. The workbook's content is evidence, not approval to activate it. | **No** | **No** |
| Kraepelin | **Unassigned.** Must identify an approved administration form/generator independently of scoring version `F2-2026.09`. | **Unassigned.** Approval must bind either a deterministic generator specification and test vectors or an exact fixed 50 x 28 digit matrix, together with administration rules and rights basis. Inspected workbook SHA-256: `6cb7455a35c72488abf0627e91a256ece21667e753d6975b3779392e1271407d`. | Authoritative shape is 50 columns x 15s = 750s, 28 digits/27 answer slots per column, bottom-to-top unit-digit entry: PRD FR-06, SPEC §4.3, SCORING_ALGORITHM §6, and `Alat Tes Kraeplin Final.xlsx`, sheet `3. 〔Tinjau〕 Param Algoritma`, `A1:C8` and `A14`. | **Conflict.** Latest PRD FR-08b says seeded digits per participant, and current domain/database contract requires seeded randomization. Earlier psychologist confirmation item 8, paragraphs 60-65, says the normalized worksheet must be fixed/no randomization; `Alat Tes Kraeplin Final.xlsx`, `3. 〔Tinjau〕 Param Algoritma!A14`, repeats “angka nya tetap, seperti yang di lembar soal”. The workbook does not contain the referenced exact 50 x 28 digit sheet, while no deterministic seeded algorithm, generator version, digit-distribution rules, or golden generation vectors were found. By source precedence the PRD's seeded rule is current, but it is not executable without those generator details and an explicit resolution of the psychometric contradiction. | `database/seeders/data/kraepelin.json`, version `F2-2026.09`, SHA-256 `ddc4cdfb1f95fbf6aa655a24da74cb8a116bb4a573879ffa55bbcc5abead7858`; Tabel Lookup v1.1 sheets `05`-`07`; accepted goldens; ADR-0028. These define factor/band scoring, not digits. | Seeded-vs-fixed administration conflict remains materially unresolved; no generator algorithm/version/test vectors or exact fixed matrix is present; definition/provenance identity and rights basis are absent. The catalog cannot accept a fixed Kraepelin template under its current contract. | **No** | **No** |

## Source-file fingerprints and exact evidence locations

The following lower-case SHA-256 values make the audit reproducible but do not
declare any file an approved session definition:

| Source | SHA-256 | Relevant evidence |
|---|---|---|
| `PRD_Sistem_Psikotes_CPMI.docx` (PRD 1.3 FINAL, 2026-09-08) | `9e0d994c98b531a019a2927d1a44a7313a112f11e628a6ed8abe10538029be65` | FR-05, FR-06, FR-08, FR-08b; go-live prerequisite for IST/PAPI rights |
| `D:/LSI/Psikotes/PSIKOTEST LSI/Update DASS/Spesifikasi Tim Teknis - Engineer Sistem_Psikotes.docx` | `d293a56f2cd080e1a24a9d05e1871b52a878f67737d453fd6e3de506f903441f` | §4.1 and table 9 IST timings; table 15 Kraepelin shape; end-to-end diagram's older 90/20/12.5/15 timing shorthand |
| `D:/LSI/Psikotes/PSIKOTEST LSI/PSIKOTEST/Konfirmasi Jawaban dari Pertanyaan Skoring untuk Psikolog.docx` | `15f7b0584e154425dcbee8d031f4d7159e5b195f839f9d5973de19d8cdb6b491` | item 6b PAPI form shape; item 8 paragraphs 60-65 fixed Kraepelin decision |
| `D:/LSI/Psikotes/PSIKOTEST LSI/Konfirm Akhir/Konfirmasi_Akhir_Terisi.docx` | `5288f130621a889f807500077d4147b50bdcb6443055ec6b4fb224f40d1ff253` | item 2 paragraphs 11-13: whole battery fixed/no randomization; item 3 documents IST norm provenance limitation |
| `D:/LSI/Psikotes/PSIKOTEST LSI/PSIKOTEST/Skoring/Tabel Lookup Skoring Psikotes v1.1.xlsx` | `39dcbfdb2b15275f53bccceebaeb7e8b09e901793ca02bd4b8d7cabaa48ec7e2` | scoring sheets `01`-`11`; not item/timing authority |

## Minimum approval needed before import

One authorized approver must return four complete manifest entries. Each entry
must provide, without placeholders:

1. canonical `instrument`, `version`, and `provenance` identities;
2. ordered subtest codes with exact duration seconds and item counts;
3. an immutable content reference/checksum covering all participant-visible
   items, options, instructions, and figural assets, plus the rights basis;
4. fixed/seeded administration rule; for Kraepelin, the deterministic generator
   algorithm, version, and golden test vectors if seeded;
5. for IST ME, the approved learn/recall timer split and its representation in
   the runtime contract;
6. for PAPI and RMIB, approved total duration and subtest encoding;
7. psychologist sign-off resolving Kraepelin seeded vs fixed in a source at
   least as authoritative as PRD 1.3.

After approval, construct the canonical payload, compute
`SessionDefinition::checksumFor(...)`, validate it through the typed contract,
use an explicitly reviewed catalog activation procedure, and run disposable
PostgreSQL acceptance before any start wiring is enabled.
No production catalog row, seed, feature gate, or endpoint activation is
authorized by this audit.

## Static audit evidence

- Reviewed SPEC v4.3 §§4.1-4.4, 8A.5, 14; SCORING_ALGORITHM §§3-6; PRD 1.3
  FR-05/06/08/08b and go-live prerequisites; ADR-0028, ADR-0030, ADR-0031;
  current domain, provider, migration, scoring JSON, and the supporting sources
  fingerprinted above.
- This is a documentation-only audit. No production code, config, seed data,
  migration, route, active database, or participant data was modified.
