# Note: how to actually prove a Postgres test ran (2026-09-22)

Surfaced while verifying PR #95 (RLS-GAP-01..04): grepping a full
`tools/testing/run-org-postgres.ps1` (no `-Filter`) run's output for a new
test class's name is **not** valid evidence that it ran. PHPUnit's default
reporter only prints test names for **failures** (and errors) — a passing
test shows up only as a `.` in the progress bar, never by name. Grepping
for a passing test's class name will always return zero matches, with or
without the test actually executing.

To confirm a specific test genuinely ran (and, separately, that PHPUnit's
configuration discovers it at all), use one of:

- `vendor/bin/phpunit --configuration phpunit.organization-postgres.xml --list-tests`
  (inside the disposable runner) — enumerates every discovered test by
  name, independent of pass/fail. Confirms discovery.
- `tools/testing/run-org-postgres.ps1 -Filter <ClassName>` — actually runs
  just that class against the real disposable database and reports
  pass/fail directly. Confirms execution and correctness.
- `--testdox` output (if needed) prints every test's description
  regardless of outcome, unlike the default dot/failure-only reporter.

Do not treat "my grep found nothing in the full-suite output" as evidence
a new test didn't run — check with one of the above instead.
