# Integration handoff — psychotest current convergence (2026-09-10)

## Scope

- Canonical integration branch: `integration/psychotest-current`.
- Remote baseline at start of this convergence wave: `022d84373089d41a62f8e1b755890a529ac6d9c4`.
- Preserve the accepted assessment-case identity and mandatory DASS-21 contracts while adapting stale fixtures.
- Do not modify `main`, the active `codex/organization-payment-spec` worktree, or its user-owned untracked ADR-0026.

## Result

- Full SQLite PHPUnit regression improved from 3,213 tests with 592 errors and 31 failures, through 169 errors and 3 failures, to **3,214 passed / 20,593 assertions** after the added rollback-preservation coverage.
- Integrated fixtures now bind assessment attempts and direct orders to exact cases and retain the mandatory IST + DASS-21 package composition.
- Mixed `RefreshDatabase` / `DatabaseTruncation` suites reset Laravel's in-memory migration marker only at the trait boundary, preventing an empty reused SQLite connection.
- SQLite profile-table rebuilds snapshot and restore every dependent non-funding trigger; regression coverage proves exact trigger preservation and rejected case-identity rebinding.
- SQLite assessment-case rollback rebuilds the two nullable-link tables instead of using native `DROP COLUMN` against inline foreign keys, while preserving unrelated indexes/triggers and checking foreign-key integrity.

## Verification evidence

- Full PHPUnit: `3214 passed`, `20593 assertions`, exit 0.
- Combined changed-area regression: `250 passed`, `1938 assertions`, exit 0.
- Profile migration regression: `23 passed`, `125 assertions`; exact guard roundtrip probe passed.
- Assessment-case / payment-schema regression: `35 passed`, `204 assertions` after integration.
- Full Pint: passed.
- PHPStan level 7: passed with 0 errors.
- `npm ci --ignore-scripts`: passed; `npm audit`: 0 vulnerabilities; registry signature audit reported no invalid or missing signatures.
- Vite production build: passed, 2,317 modules. Existing warnings remain for optional `fontaine` and the 520.43 kB main chunk.
- Composer locked audit: no advisories.
- `docker compose config --quiet`: passed with synthetic required environment values.

## Integration history after remote baseline

The convergence commits are the contiguous range `6676c3c..b722947`, followed by this handoff commit. They contain test-fixture adaptations, a minimal lockfile remediation, plus two SQLite rollback-safety repairs (`50e803a`/`d86334f`/`df492b9`/`46bb8a4` and `f8ad2d7`). No production authorization or identity guard was weakened.

## Open checkpoints

- Repeated DIRECT_PUBLIC payment fixtures now share one exact graph builder and create both IST and DASS-21 entitlements, matching `RegisterParticipant` behavior; focused coverage passed 26 tests / 146 assertions.
- PostgreSQL behavior is unchanged by the SQLite-only rollback branches. PostgreSQL/container concurrency acceptance remains a deployment-readiness checkpoint, separate from this integration convergence.
- A deterministic injected mid-rebuild failure test was not added because it would require a production-only test hook; transactional rollback and preservation are covered, but that fault-injection case remains future hardening.
- CI still invokes `npm install` through the Composer setup script instead of a frozen `npm ci`; switching CI install policy is a separate pipeline change.
- The repository-wide secret/PII content gate still fails closed on the pre-existing tracked binary `public/apple-touch-icon.png`; its structural/digest approval authority remains unresolved in the F9 backlog.
