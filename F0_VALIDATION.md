# F0 Validation Report

Status: **PASS — mandatory kickoff gate complete.**

## Generated data

- `database/seeders/data/ist.json`
- `database/seeders/data/papi.json`
- `database/seeders/data/rmib.json`
- `database/seeders/data/kraepelin.json`
- `database/seeders/data/dass21.json`
- `database/seeders/data/reporting.json`

## Gate evidence

- Kraepelin golden #1: Panker 15.86, Tianker 7, Hanker -0.622, Janker 7; S1/S2 IPA scores 7/6/4/6.
- Kraepelin golden #2: Panker 13.12, Tianker 5, Janker 6, slope b 0.100648, Hanker 5.032; levels in P/T/J/H order 4/4/4/5.
- PAPI: 90 mappings; ROLE=45 and NEED=45; every dimension occurs exactly nine times; all five color-band positions cover scores 0–9 exactly once for each of 20 dimensions.
- RMIB: 108 rotation cells; every group sums to 78 and the total is 702.
- DASS-21: 21 items; D/A/S each contain seven items; 15 subscale narratives, five general narratives, and two bilingual follow-up instructions.
- IST: 160 non-GE keys, 16 GE dictionaries, separate RW 0-20 and GE RW 0-32 blocks; SE RW16=131; ten SW score/category bands and ten IQ score/category bands.
- Monotonicity and coverage: every extracted IST RW-to-SW table is non-decreasing; RW-total 28–151 maps once and monotonically to IQ; SW/IQ score bands cover the tested domain without gaps or overlaps.
- Reporting: GA-2026.08, six fields, critical aspects A1/B2/C4/C5, 90 aspect narratives.

## Historical audit

Five complete, anonymized IST rows with non-trivial GE matched all nine cached raw subtest totals, with zero cached RA anomalies. The former +1 discrepancy was traced to Excel storing the intended RA key `3, 5` as a date serial while displaying it with the `m, d` number format. The extractor and audit now normalize that cell to its intended displayed key. No participant identity or response is written to the repository.

## Decisions

- `SPEC.md` overrides the legacy knockout/total-threshold sections in `SCORING_ALGORITHM.md`.
- Lookup v1.1 overrides the older IST master cell SE RW16=127; the corrected value is 131.
- Hanker is regression slope `b * 50`.
- Golden #2 level order is reported as Panker/Tianker/Janker/Hanker to match 4/4/4/5.
- Outputs are JSON because the Laravel application has not been initialized; a Laravel seeder can consume these files in F1.
