# F2 psychometric data conflict decision brief

Status: **Decision required — this brief does not choose a psychometric result**

Scope: the minimum source-of-truth decisions required before implementing the
IQ, PAPI, and RMIB normalized scoring boundaries. This brief also records two
Kraepelin ambiguities that affect reproducibility. It does not amend
`SPEC.md`, `SCORING_ALGORITHM.md`, extracted JSON, acceptance checklists, or
runtime code.

## Decision rule for this checkpoint

The implementation must not select whichever source is easiest to code. The
psychologist must identify the intended rule; engineering can then encode that
rule as versioned data and update the canonical documents and tests together.
Until that happens, raw-score calculators may proceed where their outputs are
unambiguous, but the conflicting normalization step must fail closed or remain
unimplemented.

## 1. IST IQ to level: T-01 and `iq_score_bands` disagree

### Exact sources

- `SPEC.md` section 4.1 defines T-01 as `IQ<79 -> 1`, `79-89 -> 2`,
  `90-109 -> 3`, `110-119 -> 4`, and `>=120 -> 5`.
- `SCORING_ALGORITHM.md` sections 2 and 6.2 direct IQ through sheet 04 to a
  score from 1 through 10.
- `database/seeders/data/ist.json` field `iq_score_bands` contains the extracted
  sheet-04 bands. When the general 1-10-to-level rule `ceil(score/2)` is
  applied, those bands become: IQ `<=90 -> 1`, `91-102 -> 2`, `103-114 -> 3`,
  `115-126 -> 4`, and `>=127 -> 5`.

### Concrete examples

| IQ | SPEC T-01 level | JSON score | `ceil(score/2)` | Conflict |
|---:|---:|---:|---:|---|
| 89 | 2 | 2 | 1 | yes |
| 90 | 3 | 2 | 1 | yes |
| 109 | 3 | 6 | 3 | no |
| 110 | 4 | 6 | 3 | yes |
| 120 | 5 | 7 | 4 | yes |
| 127 | 5 | 9 | 5 | no |

This is not a boundary-only discrepancy: it changes A1 across substantial IQ
ranges and can therefore change its Grey Area zone and the critical-aspect
guardrail.

### Minimum psychologist decision

Which exact mapping is authoritative for A1: the five T-01 IQ bands, or the
sheet-04 1-10 score followed by a specified conversion to level 1-5? If both
must be retained, which one is diagnostic display data and which one is the
input to A1 eligibility?

### Non-hardcoded data amendment options

- If T-01 is authoritative, add a versioned `iq_level_bands` array to
  `ist.json`; keep `iq_score_bands` under an explicitly different display or
  archival purpose.
- If sheet 04 is authoritative, add a versioned, explicit score-to-level table
  to data rather than relying on an implicit `ceil(score/2)` convention.

Either option needs boundary tests for 78/79, 89/90, 109/110, 119/120, and
126/127, plus a test proving the unused representation cannot affect A1.

## 2. PAPI: the optimal formula, T-04, and color model produce three results

### Exact sources

- `SPEC.md` section 4.2 defines raw-step distance from the dimension's white
  zone: `level = max(1, 5 - min(distance, 4))`.
- The same section and acceptance T-04 require PAPI W raw scores `0/5/9` to
  produce levels `2/5/2`.
- `database/seeders/data/papi.json` defines W's `white_zones` entry as `[4,7]`.
- `SCORING_ALGORITHM.md` sections 3 and 6.2 instead use color bands and the
  JSON `band_scores`: white `8`, blue `7`, low-yellow `3`, high-yellow `5`.

### Concrete example: dimension W

| W raw | Distance from `[4,7]` | SPEC formula level | T-04 level | JSON color -> score |
|---:|---:|---:|---:|---|
| 0 | 4 | 1 | 2 | yellow-low -> 3 |
| 5 | 0 | 5 | 5 | white -> 8 |
| 9 | 2 | 3 | 2 | yellow-high -> 5 |

Even converting the JSON score with `ceil(score/2)` gives `2/4/3`, so it does
not reconcile either SPEC representation. The conflict changes both magnitude
and symmetry: the fixed color model intentionally gives different low- and
high-yellow scores, while the SPEC prose says upward and downward deviations
are equivalent.

### Minimum psychologist decision

For HPP aspect inputs, is PAPI normalized by raw distance from the white zone,
by the extracted color-to-score table, or by another reviewed table that makes
T-04 `2/5/2` true? Confirm whether low and high deviations are symmetric and
whether the normalized output is directly level 1-5 or score 1-10.

### Non-hardcoded data amendment options

- Store an explicit versioned `raw_to_level` table for every dimension. This
  can express T-04 exactly without embedding thresholds in PHP.
- Alternatively, retain `bands` plus `band_scores`, add a method/version
  discriminator, and provide an explicit score-to-level table if the color
  model is authoritative.
- If distance is authoritative, store its distance-to-level lookup and white
  zones as one versioned ruleset; acceptance tests must be generated from that
  same ruleset.

Tests must cover all raw values 0-9 for all 20 dimensions, the exact W T-04
fixture, symmetry/asymmetry as decided, and the four dimensions excluded from
HPP without discarding their raw archival scores.

## 3. RMIB: rank-to-level conflicts with `rank_to_score`; ties are undefined

### Exact sources

- `SPEC.md` section 4.4 maps ranks `1-2 -> level 5`, `3-4 -> 4`, `5-6 -> 3`,
  `7-8 -> 2`, and `9-12 -> 1`.
- `SCORING_ALGORITHM.md` sections 4 and 6.2 direct rank 1-12 through sheet 11
  to score 1-10.
- `database/seeders/data/rmib.json` field `rank_to_score` contains
  `1:10, 2:9, 3:8, 4:7, 5:6, 6:6, 7:5, 8:5, 9:4, 10:3, 11:2, 12:1`.

### Concrete examples

| RMIB rank | SPEC level | JSON score | `ceil(score/2)` | Conflict |
|---:|---:|---:|---:|---|
| 2 | 5 | 9 | 5 | no |
| 6 | 3 | 6 | 3 | no |
| 7 | 2 | 5 | 3 | yes |
| 9 | 1 | 4 | 2 | yes |
| 10 | 1 | 3 | 2 | yes |
| 12 | 1 | 1 | 1 | no |

Ranks 7-10 therefore change level depending on the chosen path. Separately,
neither canonical document nor JSON defines what rank two categories receive
when their nine-cell totals are equal. For example, totals `54` and `54` do
not determine whether ranks should be shared, skipped, dense, fractional, or
withheld. Category order is not psychometric authority and must not be used as
a tie-breaker.

### Minimum psychologist decision

Is the direct five-band mapping or sheet-11 `rank_to_score` authoritative for
the five HPP interest aspects? For equal category totals, should the system use
a specified ranking convention or emit an unresolved result for psychologist
review, and what downstream HPP behavior is allowed while unresolved?

### Non-hardcoded data amendment options

- Add a versioned `rank_to_level` table if the SPEC five-band mapping is
  authoritative, while retaining `rank_to_score` only for its stated purpose.
- If sheet 11 is authoritative, add an explicit score-to-level table and name
  the purpose of both representations.
- Add a versioned `tie_policy` discriminator and any required parameters. A
  review-required policy should return category totals and typed review state,
  not invent ranks or discard the evidence as malformed input.

Tests must cover every rank 1-12, especially 6/7 and 8/9, and at least one
valid 108-response fixture that produces tied category totals.

## 4. Additional reproducibility gaps

### Kraepelin rounding

The canonical documents and JSON use three-decimal factor cutoffs, but do not
freeze when rounding occurs or which midpoint rule applies. The F0 validator
uses Python `round` (ties to even), while PHP's default `round` uses half-up.
For an intermediate value `1.2345`, those rules can yield `1.234` and `1.235`
respectively, potentially selecting different adjacent bands.

Minimum decision: specify whether factors are compared as unrounded values or
rounded first; if rounded, specify precision and midpoint behavior for positive
and negative values. Encode this as versioned scoring metadata and add exact
midpoint/band-edge fixtures rather than a language-default implementation.

### Panker wording

`SPEC.md` section 4.3 defines Panker achievement as correct plus incorrect,
whereas `SCORING_ALGORITHM.md` section 5 describes `Y` as correct achievement.
Golden F0 number one resolves the calculation currently used: total achievement
is `793`, including seven incorrect responses, so Panker is `793/50 = 15.86`.
Using correct-only would be `786/50 = 15.72`.

Minimum decision: confirm that `Y` means attempted achievement
(`correct + incorrect`) and amend the wording, or provide replacement golden
evidence if correct-only is intended. The resolved definition should be stored
with the Kraepelin formula version/provenance; the numeric formula itself need
not be hardcoded outside the versioned ruleset.

## Required coordinated follow-up

After psychologist decisions, the coordinator should amend the canonical
documents and extracted data in one reviewed contract change, bump the required
engine/data version, and regenerate acceptance fixtures. Only then should the
normalization implementations proceed. No decision in this brief authorizes a
particular mapping, migration, route, UI, report, or production activation.
