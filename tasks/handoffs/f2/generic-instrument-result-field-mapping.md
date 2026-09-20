# Generic instrument result ledger — per-instrument field mapping

Status: accepted (Lead-approved 2026-09-20, F2 lane G7 increment)

## Why this document exists

`generic_instrument_result_sources`
(`database/migrations/2026_09_13_000100_create_generic_instrument_result_ledger.php`)
has ONE fixed column shape (`source_code`, `raw_score`, `standard_score`,
`source_score`, `level`, `category`, `band_low`, `band_high`) shared by all
four instruments. It was designed for IST first. PAPI, RMIB, and Kraepelin do
not have native equivalents for every column, so each instrument's persistence
service (`app/Services/AssessmentResults/PersistSealed{Ist,Papi,Rmib}Result.php`,
Kraepelin pending its own migration) makes an explicit, documented choice
about what each column means for that instrument. **The same column name means
a different thing depending on `instrument_code`.** Any code that reads this
table across instruments — a G7 aggregator most of all — must read this
document first, not assume the columns are directly comparable.

## Hard rules for any future G7 aggregator

1. **Never compare `standard_score` across instruments.** It is only
   meaningful within one instrument (IST: standard/T-score; PAPI: distance
   from the white zone; RMIB: competition rank; Kraepelin: TBD with its own
   migration). Comparing, say, IST's `standard_score` to PAPI's is comparing
   two unrelated quantities that happen to share a column name.
2. **Never compare or trust `band_low`/`band_high` for RMIB as a range.**
   RMIB's band is degenerate (`lo = hi = rank`) purely to satisfy the column's
   NOT-NULL-either check constraint. It is not a norm, zone, or range, and
   must never be read as one — see the RMIB section below.
3. Only `level` (1-5, the accepted 18-aspect aggregation anchor per
   `SCORING_ALGORITHM.md` §8) and, with the two exceptions above, `source_code`
   are safe to read the same way across instruments. `source_code` itself
   must match exactly what `database/seeders/data/aspect_sources.json`
   expects for that instrument (see "source_code cross-check" below) — a
   mismatch does not error, it silently drops a source from HPP aggregation.

## IST (`app/Domain/AssessmentResults/SealedIstResult.php`, `ScoreSealedIstAnswerSet.php`)

Source: `app/Services/Scoring/IstSwLevelCalculator.php` and
`IstStandardScoreCalculator.php`.

| Column | Meaning for IST |
|---|---|
| `source_code` | Subtest code (`SE`, `WA`, `AN`, `GE`, `RA`, `ZR`, `FA`, `WU`, `ME`) |
| `raw_score` | Raw correct-answer count for the subtest, 0-20 |
| `standard_score` | The norm-table standard score for the subtest |
| `source_score` | The raw score used as the standard-score lookup input |
| `level` | SW level, 1-5 |
| `category` | SW category label from the norm table |
| `band_low`/`band_high` | The SW score band the subtest's level falls in |

## PAPI (`app/Domain/AssessmentResults/SealedPapiResult.php`, `ScoreSealedPapiAnswerSet.php`)

Source: `app/Services/Scoring/PapiLevelCalculator.php` and
`PapiRawScoreCalculator.php`. All 20 dimensions are persisted, including `G`,
`I`, `X`, `Z` (`SCORING_ALGORITHM.md`: "Dimensi G, I, X, dan Z tetap dihitung
dan terlihat oleh psikolog, tetapi tidak masuk agregasi HPP").

| Column | Meaning for PAPI |
|---|---|
| `source_code` | Dimension letter (`W`, `X`, `G`, `A`, ... — the same short code `aspect_sources.json` uses as `PAPI_<code>`) |
| `raw_score` | Raw forced-choice count for the dimension, 0-9 |
| `standard_score` | **Distance from the white (optimal) zone**, not a standard score in the psychometric sense — chosen because PAPI has no native standard-score concept and this is the nearest already-computed quantity |
| `source_score` | The same raw forced-choice count as `raw_score` (PAPI has no separate lookup input; deliberately identical to `raw_score` for this instrument) |
| `level` | The psychologist-approved distance-to-level mapping, 1-5 |
| `category` | The dimension's fixed type, `"ROLE"` or `"NEED"` — not a severity/quality label like IST's `category` |
| `band_low`/`band_high` | The white zone `[lo, hi]` for the dimension |

HPP exclusion for `G`/`I`/`X`/`Z` is enforced entirely by their absence from
`aspect_sources.json`'s PAPI entries — there is no `hpp_excluded` column, and
none should be added; the seed data already encodes exclusion by omission.

## RMIB (`app/Domain/AssessmentResults/SealedRmibResult.php`, `ScoreSealedRmibAnswerSet.php`)

Source: `app/Services/Scoring/RmibRawScoreCalculator.php`,
`RmibRankLevelCalculator.php`, and the new `RmibScoreCalculator.php` (a pure
lookup over `database/seeders/data/rmib.json`'s existing, approved
`rank_to_score` table — Sheet 11 — which had no calculator wired to it before
this increment). All 12 categories are persisted, including the seven that
are not D1-D5 (`SCORING_ALGORITHM.md`: "tujuh kategori lain disimpan untuk
tinjauan psikolog").

| Column | Meaning for RMIB |
|---|---|
| `source_code` | The category's **short** code (`Out`, `Me`, `Comp`, ..., `S.Se` — the same short code `aspect_sources.json` uses as `RMIB_<code>`). Using the long name here instead would make an aggregator silently find no matching source. |
| `raw_score` | The category's rank total: sum of nine ranks across the nine groups, 9-108 |
| `standard_score` | The category's competition rank, 1-12 (ties share a rank, per `SCORING_ALGORITHM.md`'s competition-ranking rule — **not comparable to any other instrument's `standard_score`**) |
| `source_score` | The Sheet 11 rank→score lookup value, 1-10 |
| `level` | The Sheet 11 rank→level lookup value, 1-5 |
| `category` | The category's long descriptive name (e.g. `"Outdoor"`) — for display/review, not for matching against `aspect_sources.json` (that uses `source_code`) |
| `band_low`/`band_high` | **Degenerate: always `[rank, rank]`.** RMIB has no norm or zone concept anywhere in `SCORING_ALGORITHM.md` or its calculators (unlike IST's score bands or PAPI's white zones). This value exists ONLY to satisfy the ledger's `band_low IS NOT NULL OR band_high IS NOT NULL` check constraint. **It is not a range and must never be read, displayed, or compared as one.** |

All 12 categories are persisted, but only 5 (`Out`, `Me`, `Prac`, `Med`,
`S.Se` — D1-D5) are referenced by `aspect_sources.json`. The other 7
(`Comp`, `Sci`, `Prs`, `Aesth`, `Lit`, `Mus`, `Cler`) are intentionally
absent from it, mirroring PAPI's `G`/`I`/`X`/`Z` treatment — HPP exclusion
by omission, not by a column, per `SCORING_ALGORITHM.md`'s "tujuh kategori
lain disimpan untuk tinjauan psikolog."

## Kraepelin (`app/Domain/AssessmentResults/SealedKraepelinResult.php`, `SealPrecomputedKraepelinFactors.php`)

**Known gap, intentional — read this before wiring anything to Kraepelin.**
There is no code anywhere in this repository that grades a participant's raw
Kraepelin digit answers (50 columns) into
`{achievement, correct, incorrect, skipped}` per column —
`app/Services/Scoring/KraepelinFactorCalculator.php`'s actual required
input. That derivation needs the exact seeded number-generation algorithm,
and the Kraepelin authority pack
(`tasks/handoffs/authority-pack/kraepelin.md`) is **BLOCKED 0/4**: the
seeded-versus-fixed generator algorithm has no psychologist approval.
Building that derivation would mean inventing instrument generation/grading
logic without authority. **`SealPrecomputedKraepelinFactors` does not do
that** — it is deliberately not named `ScoreSealedKraepelinAnswerSet`,
because it does not score raw answers. It seals four **already-computed**
factors that the caller must produce by running
`KraepelinFactorCalculator::calculate()` on real graded column data first.
**Whoever builds the raw-digit-grading step in the future must call
`KraepelinFactorCalculator` then `KraepelinBandMapper`, then hand the four
results to `SealPrecomputedKraepelinFactors`** — not build a competing
seal/persist path.

Schema note: `raw_score`, `band_low`, and `band_high` on
`generic_instrument_result_sources` were `integer` and are now
`numeric(8,3)` (migration
`2026_09_20_000200_widen_generic_instrument_result_source_fractional_columns.php`),
because Panker and Hanker are fractional by design
(`SCORING_ALGORITHM.md` golden test: "Panker 15,86... Hanker -0,622"; norm
bands in `database/seeders/data/kraepelin.json` have boundaries like `16.7`,
`-0.761`). That same migration also drops the `raw_score >= 0` clause from
`generic_instrument_result_sources_contract_check` (found failing for real
in PostgreSQL, not just theorized — Hanker is routinely negative) — every
other clause is unchanged. `standard_score` and `source_score` were
deliberately **not** widened.

| Column | Meaning for Kraepelin |
|---|---|
| `source_code` | Factor name, uppercase (`PANKER`, `TIANKER`, `HANKER`, `JANKER` — the same short code `aspect_sources.json` uses as `KRAEPELIN_<code>`) |
| `raw_score` | `KraepelinBandMapper`'s `raw_factor` — the exact value classified, int\|float, may be fractional and may be negative (Hanker) |
| `standard_score` | **Deliberately identical to `source_score`** (Lead-approved 2026-09-20, after an earlier proposal to duplicate the fractional `raw_score` here was correctly rejected — that would have silently truncated Panker/Hanker in this un-widened integer column). Kraepelin has no second native metric distinct from the Sheet-style band score, unlike PAPI's `distance`. |
| `source_score` | `KraepelinBandMapper`'s `source_score` — native integer, 1-10 |
| `level` | `KraepelinBandMapper`'s `level`, 1-5 |
| `category` | `KraepelinBandMapper`'s `category` (Indonesian severity label, e.g. "Baik", "Sedang", "Kurang" — comparable in spirit to IST's `category`) |
| `band_low`/`band_high` | `KraepelinBandMapper`'s `band` — REAL norm-table boundaries (unlike RMIB's degenerate band), may be fractional, may be `null` at an open domain edge (the top/bottom band per factor per group is unbounded on one side) |

`normGroup` (e.g. `"S1/S2 (IPA)"`, `"D3 (IPS) Laki-laki"`) is not a ledger
column — it is stored only inside the sealed `result_payload` (via
`SealedKraepelinResult::toArray()`'s `normGroup` field), since the ledger's
child-row shape has no slot for it. Resolving *which* group a given
participant belongs to is a case/participant demographic concern, entirely
out of this lane's scope — `SealPrecomputedKraepelinFactors::execute()`
requires it as an explicit, required caller-supplied argument and validates
it only against the groups the versioned Kraepelin authority actually
configures, never guesses or defaults it.

**A sixth psychologist question, separate from Q1-Q4 already raised**: the
Kraepelin seeded number-generation algorithm (seeded versus fixed) has no
approval, and without it the path from a participant's actual answers to a
scoreable result cannot be built at all — Kraepelin cannot be completed
end-to-end by a participant no matter how ready the session engine is,
until this is decided.

## `source_code` cross-check against `aspect_sources.json`

Every `source_code` persisted for PAPI and RMIB is required to exactly match
a code `database/seeders/data/aspect_sources.json` references (as
`PAPI_<code>` / `RMIB_<code>`), with the sole documented exception of PAPI's
`G`/`I`/`X`/`Z` (intentionally absent — HPP-excluded by design, not a
mismatch). This is proven by
`tests/Unit/AssessmentResults/GenericInstrumentSourceCodeAspectMappingTest.php`,
which reads `aspect_sources.json` directly (not a copy) and asserts every
non-excluded PAPI/RMIB source code the sealed-result classes can produce is
referenced there, and that the four PAPI exclusions are exactly `G`, `I`,
`X`, `Z` — no more, no fewer.
