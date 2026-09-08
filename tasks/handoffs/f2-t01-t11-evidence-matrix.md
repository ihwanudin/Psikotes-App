# F2 T-01 through T-11 evidence matrix

Status: evidence snapshot only — not an acceptance authority

Date: 2026-09-08

Branch: `codex/organization-payment-spec`

Latest reviewed scoring implementation: `10bc513`

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
| T-01 | IQ value to A1 level 1–5 | `pass` | `IstIqLevelCalculatorTest` covers every canonical boundary from IQ 90 through 127 against versioned `iq_level_bands` (`10bc513`). Existing raw-total-to-IQ evidence remains `435e506` and `bf60d23`. | Pure transform is complete; normalized-result composition remains a later integration boundary. |
| T-02 | IST SW to level 1–5 | `pass` | `IstSwLevelCalculatorTest::test_canonical_sw_boundaries_map_to_five_levels` covers 80/81, 94/95, 104/105, and 118/119; injected-band provenance and invalid gap/overlap/config cases are also covered (`926c188`). | No gap observed within this pure transform. Integration into a future normalized result remains outside this evidence. |
| T-03 | PAPI raw-score calculation | `pass` | `PapiRawScoreCalculatorTest` proves the injected 90-item choice mapping, exactly 20 raw dimensions, ROLE/NEED totals, 0–9 raw domain, and fail-closed payload/config behavior (`9145c9d`). | Pure raw scoring is complete; normalized-result composition remains separate. |
| T-04 | PAPI white-zone-distance normalization; W raw 0/5/9 produces 1/5/3 | `pass` | `PapiLevelCalculatorTest` exercises all 200 dimension/raw combinations, exact W acceptance values, exclusions G/I/X/Z, and invalid inputs against versioned `papi.json` (`10bc513`). | Pure transform is complete; HPP composition remains later work. |
| T-05 | RMIB rank to level 1–5 | `pass` | `RmibRankLevelCalculatorTest` covers all ranks 1–12. `RmibRawScoreCalculatorTest` proves Excel-compatible competition ranking for tied totals while preserving 108 inputs and sums 78/702 (`10bc513`). | Pure transform is complete; normalized-result composition remains later work. |
| T-06 | Compare 18 normalized aspect levels with a versioned job-field standard to produce zone | `not-started` | T-01 through T-05 normalization inputs are now stable and versioned; no zone/eligibility implementation or direct T-06 test exists yet. | Implement the F3 eligibility boundary against the frozen normalized result contract and versioned job-field standards. |
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
| IST raw-total-to-IQ | `pass` | Exact canonical range boundaries and per-subtest maxima are proven (`435e506`, `bf60d23`); IQ-to-level boundaries pass in `10bc513`. |
| PAPI raw scoring and normalization | `pass` | All 90 choices, 20 dimensions, ROLE=45, NEED=45, strict raw invariants, and every white-zone-distance output are covered (`9145c9d`, `10bc513`). |
| RMIB raw totals, rank, and level | `pass` | The 9×12 contract, sums 78/702, injected rotation, competition ties, and all rank-to-level outputs are covered (`ffafcfd`, `edbe8a8`, `b09af8f`, `10bc513`). |
| Kraepelin four raw factors | `pass` | Both F0 goldens, 50-column/capacity checks, Panker `correct + incorrect`, Hanker `b×50`, provenance, and review metadata pass (`5d8390c`, `8fdc508`, `10bc513`). |
| Kraepelin factor-to-band mapping | `pass` | Injected group bands, direction, coverage, fallback, category/level provenance, and half-up three-decimal lookup pass (`492a1cf`, `b395458`, `10bc513`). |
| DASS calculation and screening metadata | `pass` | Canonical item/cutoff/multiplier scoring and neutral follow-up/validity metadata pass (`1bbbb70`, `39836d7`). This does not elevate T-07 beyond `partial`. |
| Frozen F0 extraction gate | `pass` | The rerun recorded below passed all 11 tests: IST parsing/monotonicity/bands, PAPI and RMIB invariants, two Kraepelin factor goldens plus score bands, DASS invariants, and reporting data. |

## Smallest next executable increments

The prior psychometric authority blockers are resolved by ADR-0028 and
`10bc513`. The smallest next dependency-safe increment is T-06: compose the 18
normalized aspect levels against versioned job-field standards in the F3
eligibility boundary. Once T-06 exists, complete T-07 with two otherwise
identical sessions whose DASS results differ from Normal to Sangat Parah and
assert identical zone and eligibility label outputs.

T-01 through T-05 and T-08 through T-11 have no remaining pure-scoring gap in
this snapshot. Their next work is integration through the coordinator-frozen
normalized result contract.

## Reproducible verification snapshot

The following commands were rerun from the repository root on 2026-09-08:

```powershell
php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php tests/Unit/Scoring
python -m unittest tools.extract.tests.test_f0 -v
php vendor/bin/pint --test app/Services/Scoring tests/Unit/Scoring
$env:APP_ENV='testing'; php vendor/bin/phpstan analyse --no-progress app/Services/Scoring tests/Unit/Scoring
```

Observed results:

- scoring PHPUnit: 194 tests, 875 assertions, all passed;
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
