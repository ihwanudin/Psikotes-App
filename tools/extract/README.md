# F0 instrument extraction

Run with the bundled Python environment:

```text
python -m tools.extract.run_all "D:\LSI\Psikotes\PSIKOTEST LSI"
python -m unittest tools.extract.tests.test_f0 -v
python -m tools.extract.audit_historical_ist "D:\LSI\Psikotes\PSIKOTEST LSI"
```

Only instrument definitions and synthetic/golden fixtures are written to
`database/seeders/data`. Participant identities and responses are never written.

The IST extractor preserves Excel's displayed `m, d` value for the RA key that
Excel internally stores as a date serial. Tests cover this regression together
with norm monotonicity, score-band coverage, PAPI 0–9 color-band coverage,
DASS-21 narratives, and both Kraepelin golden fixtures.
