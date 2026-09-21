# F0 instrument extraction

Run with the bundled Python environment:

```text
python -m tools.extract.run_all "D:\LSI\Psikotes\PSIKOTEST LSI"
python -m unittest discover -s tools/extract/tests -t . -v
python -m tools.extract.audit_historical_ist "D:\LSI\Psikotes\PSIKOTEST LSI"
```

Only instrument definitions and synthetic/golden fixtures are written to
`database/seeders/data`. Participant identities and responses are never written.

The IST extractor preserves Excel's displayed `m, d` value for the RA key that
Excel internally stores as a date serial. Tests cover this regression together
with norm monotonicity, score-band coverage, PAPI 0–9 color-band coverage,
DASS-21 narratives, and both Kraepelin golden fixtures.

## CI and the two kinds of test in `tools/extract/tests/`

`.github/workflows/tests.yml`'s `ci` job runs every test module under
`tools/extract/tests/` automatically (`unittest discover`, extra deps from
`tools/extract/requirements.txt`) - this is the fail-closed hash/structural
gate over the committed instrument data in `database/seeders/data/*.json`, so
a manual edit to that data (not run through an `extract_*.py` script) turns CI
red. Keep this true when adding a new instrument's items:

- **Tests that only read already-committed files** (hash-pin, byte length,
  item counts, cross-file invariants against other `database/seeders/data/*.json`
  files) belong in `tools/extract/tests/test_*.py` same as today - they need no
  source PDFs/xlsx and CI runs them on every push/PR.
- **Tests that need the real source PDFs/xlsx** (e.g. re-running an
  `extract_*.py` function against the actual booklet to prove the extractor
  itself is still correct, not just its last output) cannot run in CI - those
  files live outside the repo. Such a test must detect a missing source with
  an explicit, loud `self.skipTest(f"... not available at {path}")` (visible
  in the test report as SKIP with that reason), never a silent
  `if not path.exists(): return` that would make an unrun test look green.
  None of the current test modules need this yet, but the next one that does
  should follow this pattern from the start.
