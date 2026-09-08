# F2 T-01 through T-11 evidence matrix

Status: evidence snapshot only — not an acceptance authority

Date: 2026-09-08

Branch: `codex/organization-payment-spec`

Observed HEAD before this report: `4a73141`

## Scope and status rules

This report maps the acceptance shorthand in `SPEC.md` section 13 to the
implementation, tests, canonical F0 data, and accepted scoring commits visible
in the current checkout. It does not amend `SPEC.md`, `SCORING_ALGORITHM.md`,
F0 data, a checklist, or an acceptance decision.

The status terms are deliberately narrow:

- `pass`: the current automated evidence directly exercises the stated rule;
- `partial`: a local component is proven, but the complete acceptance boundary
  is not;
- `blocked`: implementation or acceptance cannot proceed without an upstream
  dependency or authoritative psychometric decision;
- `not-started`: no implementation evidence exists and no recorded blocker
  prevents the next increment.

Passing a component row below does not imply that F2, F3, or the full product is
complete.

## Acceptance matrix

| Test | Canonical requirement | Status | Current evidence | Exact remaining boundary |
|---|---|---|---|---|
| T-01 | IQ value to A1 level 1–5 | `blocked` | `IstIqCalculator` and `IstIqCalculatorTest` prove exact nine-subtest raw-total-to-IQ lookup and its domain (`435e506`, `bf60d23`). | The five direct IQ bands in `SPEC.md` conflict with `ist.json:iq_score_bands` followed by the otherwise-used 1–10-to-level conversion. No IQ-to-level class exists. The expert decision and versioned data amendment in `f2-psychometric-data-conflicts.md` are required first. |
| T-02 | IST SW to level 1–5 | `pass` | `IstSwLevelCalculatorTest::test_canonical_sw_boundaries_map_to_five_levels` covers 80/81, 94/95, 104/105, and 118/119; injected-band provenance and invalid gap/overlap/config cases are also covered (`926c188`). | No gap observed within this pure transform. Integration into a future normalized result remains outside this evidence. |
| T-03 | PAPI “linear” acceptance rule | `blocked` | `PapiRawScoreCalculatorTest` proves the injected 90-item choice mapping, exactly 20 raw dimensions, ROLE/NEED totals, 0–9 raw domain, and fail-closed payload/config behavior (`9145c9d`). | `SPEC.md` names T-03 only as “PAPI linear” while section 4.2 specifies the conflicting optimal method. No authoritative linear transform or versioned raw-to-level data is defined; raw scoring evidence is not T-03 normalization evidence. |
| T-04 | PAPI optimal normalization; W raw 0/5/9 must produce 2/5/2 | `blocked` | Canonical `papi.json` contains `white_zones`, color bands, and scores; F0 proves mapping/color-band structural invariants. | The distance formula, the W fixture, and the color model produce three different results. No normalized PAPI level class exists. The expert method decision and versioned mapping described in the conflict brief are required first. |
| T-05 | RMIB rank to level 1–5 | `blocked` | `RmibRawScoreCalculatorTest` proves 108 inputs, group sums of 78, total 702, data-driven category totals, unique ordinal ranks, and tie-safe `unranked` review output (`ffafcfd`, `edbe8a8`, `b09af8f`). | Direct rank bands in `SPEC.md` conflict with `rmib.json:rank_to_score` at ranks 7–10. No rank-to-level transform exists. A versioned mapping decision remains required. |
| T-06 | Compare 18 normalized aspect levels with a versioned job-field standard to produce zone | `blocked` | No zone/eligibility implementation or T-06 test was found under `app` or `tests`; repository search found only DASS assertions proving absence of zone output. | F3 depends on stable normalized F2 inputs, while T-01, T-04, and T-05 remain blocked. Do not start the zone engine against provisional mappings. |
| T-07 | DASS Normal versus Sangat Parah must leave otherwise-identical zone and eligibility label unchanged | `partial` | `Dass21ScreeningPolicyTest::test_tied_worst_subscales_are_preserved_without_eligibility_output` proves the DASS policy emits no `eligibility`, `zone`, `label`, or `recommendation`; `Dass21Scorer` and `Dass21ScreeningPolicy` are isolated pure classes (`1bbbb70`, `39836d7`). | This is structural separation inside the scorer, not the required two-session architectural comparison. The latter cannot run until the T-06 zone/label boundary exists. DASS calculation pass status must not be used to claim T-07 pass. |
| T-08 | Score all 21 canonical DASS responses into D/A/S raw scores and apply the canonical multiplier | `pass` | `Dass21ScorerTest::test_scores_three_subscales_and_uses_worst_level_as_general_category` proves injected item mapping, raw sums, multiplier, per-item provenance, and all three outputs. Config proves exactly 21 item IDs and seven items per scale (`1bbbb70`). | No calculation gap observed in the pure scorer. |
| T-09 | Apply inclusive DASS-42 cutoff bands to the multiplied subscale scores | `pass` | `Dass21ScorerTest::test_canonical_cutoff_boundaries_are_inclusive` exercises reachable boundaries across D/A/S. Config tests reject malformed ranges, missing levels, category conflicts, and cutoff gaps (`1bbbb70`). | No cutoff gap observed for current canonical data. |
| T-10 | General DASS category is the most severe of D/A/S | `pass` | The main DASS scorer test proves a single worst scale; `test_equal_worst_levels_preserve_every_basis_scale` proves a three-way tie. Screening policy tests also preserve a two-way worst-level tie (`1bbbb70`, `39836d7`). | No pure calculation gap observed. Narrative and report visibility are explicitly outside these classes. |
| T-11 | Incomplete or invalid DASS response sets fail closed | `pass` | `Dass21ScorerTest::test_invalid_or_incomplete_responses_fail_closed` covers missing, extra, duplicate, unknown, non-integer, and out-of-domain responses (`1bbbb70`). | No pure payload-validation gap observed. Session completeness and persistence remain separate integration boundaries. |

## T-07 separation versus T-08 through T-11 calculation

T-08 through T-11 concern calculation inside the isolated DASS scorer: item
mapping, multiplier, cutoff selection, worst-category selection, and incomplete
input rejection. Those four rules have direct unit evidence.

T-07 is an architectural non-interference rule. Proving that a DASS result does
not contain eligibility fields is useful negative evidence, but it does not
prove that no later service reads DASS when producing a zone or label. The
acceptance test requires two otherwise-identical psychometric sessions with
different DASS severity and identical zone/label outputs. Since no T-06
zone/label engine exists, that comparison remains unavailable and T-07 remains
`partial`.

## Supporting F0 and scoring requirements

| Requirement slice | Status | Evidence and limitation |
|---|---|---|
| IST answer key and GE dictionary | `pass` | `IstRawScoreCalculatorTest` covers exact non-GE keys, GE 0/1/2 normalization, unknown GE answers, all canonical items, and malformed/missing/duplicate/out-of-domain responses (`1e52dd9`). |
| IST raw-to-SW norms | `pass` | `IstRawScoreLookupTest` and `IstStandardScoreCalculatorTest` prove injected lookups, GE/non-GE domains, all nine subtests, and SE RW16=131 (`c36d49d`, `c9dcc11`). |
| IST raw-total-to-IQ | `pass` | Exact canonical range boundaries and per-subtest maxima are proven (`435e506`, `bf60d23`). IQ-to-level remains separately blocked under T-01. |
| PAPI raw scoring | `pass` | All 90 choices, 20 dimensions, ROLE=45, NEED=45, and strict input/config invariants are covered (`9145c9d`). PAPI normalization remains blocked under T-03/T-04. |
| RMIB raw totals and rank evidence | `pass` | The 9×12 contract, sums 78/702, injected rotation, and preservation of tied totals as typed unranked review evidence are covered (`ffafcfd`, `edbe8a8`, `b09af8f`). Rank-to-level remains blocked under T-05. |
| Kraepelin four raw factors | `partial` | Both F0 goldens, 50-column/capacity checks, final Hanker `b×50`, provenance, and `abs(Hanker)>Janker` review metadata pass (`5d8390c`, `8fdc508`). Panker wording and midpoint/rounding order remain unresolved canonical reproducibility gaps. |
| Kraepelin factor-to-band mapping | `partial` | Injected group bands, monotonic direction, coverage, edge consistency, S1/S2 IPS fallback, category, level, and provenance pass (`492a1cf`, `b395458`). The unresolved rounding rule can still affect exact adjacent-band selection. |
| DASS calculation and screening metadata | `pass` | Canonical item/cutoff/multiplier scoring and neutral follow-up/validity metadata pass (`1bbbb70`, `39836d7`). This does not elevate T-07 beyond `partial`. |
| Frozen F0 extraction gate | `pass` | The rerun recorded below passed all 11 tests: IST parsing/monotonicity/bands, PAPI and RMIB invariants, two Kraepelin factor goldens plus score bands, DASS invariants, and reporting data. |

## Smallest next executable increments

There is no currently unblocked implementation gap among T-01 through T-07
that can be completed without crossing the recorded dependency or psychometric
authority boundaries:

- T-01, T-03, T-04, and T-05 require the expert decisions and versioned data
  changes enumerated in `tasks/handoffs/f2-psychometric-data-conflicts.md`.
- T-06 must wait for stable normalized F2 outputs.
- The missing architectural half of T-07 must wait for the T-06 zone/label
  boundary. Once that boundary exists, the smallest test increment is one
  integration test with identical non-DASS inputs and Normal versus Sangat
  Parah DASS results, asserting byte-for-byte identical zone and label fields.

T-02 and T-08 through T-11 have no unblocked pure-scoring gap visible in this
snapshot. Their next work is integration against a coordinator-frozen result
contract, not an extension of the scoring rules.

After the required expert decisions, the smallest executable increments are:

1. add one versioned canonical mapping for T-01 and boundary tests at 78/79,
   89/90, 109/110, and 119/120;
2. add one versioned PAPI normalization method/mapping and test all 0–9 raw
   values for every dimension, including exact W 0/5/9;
3. add one versioned RMIB rank-to-level mapping with tests for all ranks 1–12,
   especially 6/7 and 8/9, while preserving unresolved ties;
4. freeze Kraepelin Panker semantics and rounding metadata, then add midpoint
   and exact-band-edge fixtures before changing factor/band code.

## Reproducible verification snapshot

The following commands were rerun from the repository root on 2026-09-08:

```powershell
php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php tests/Unit/Scoring
python -m unittest tools.extract.tests.test_f0 -v
php vendor/bin/pint --test app/Services/Scoring tests/Unit/Scoring
$env:APP_ENV='testing'; php vendor/bin/phpstan analyse --no-progress app/Services/Scoring tests/Unit/Scoring
```

Observed results:

- scoring PHPUnit: 168 tests, 408 assertions, all passed;
- F0 unittest: 11 tests, all passed;
- Pint: passed;
- scoped PHPStan: passed with zero errors.

These counts are point-in-time evidence, not completion percentages.

## Evidence sources

- `SPEC.md` sections 4, 7, 13, and 14;
- `SCORING_ALGORITHM.md` sections 1 through 7;
- `database/seeders/data/ist.json`, `papi.json`, `rmib.json`,
  `kraepelin.json`, `dass21.json`, and `reporting.json`;
- `tools/extract/tests/test_f0.py`;
- `app/Services/Scoring/**` and `tests/Unit/Scoring/**`;
- `tasks/handoffs/f2-psychometric-data-conflicts.md`;
- `tasks/handoffs/integration-2026-09-08-f2-continuation.md`.
