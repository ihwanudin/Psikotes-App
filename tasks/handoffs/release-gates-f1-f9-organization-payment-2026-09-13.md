# Release-gate audit: F1, F9, and organization payment

Date: 2026-09-13

Requested baseline: `db8b1c1e90697669b5b5aeb311d3e63cc6e92b00`

Observed audit HEAD: `2a078dbffd0ba688891422c4201025c3f7cf495c`

Status: **read-only evidence reconciliation; no acceptance status changed**.

The branch advanced after the requested baseline through `93dd8ea` and
`2a078db`. Those commits are test/fixture work in other lanes. This audit reads
the current tree but does not treat post-baseline existence as acceptance. The
working tree also contained unrelated assessment-session changes; they were
preserved and are outside this report's evidence.

## Scope and evidence rule

The authority order used here is `SPEC.md`, `SCORING_ALGORITHM.md`, the PRD,
canonical checklists, accepted ADRs, then reviewed integration evidence. A
worker report, historical percentage, green structural test, or present file is
not by itself enough to close an acceptance item.

No `.env`, secret, live database, provider, payment, notification, scheduler,
deployment, migration, or feature activation was accessed or run. Database and
browser claims below are inherited only from already accepted reports and are
labelled accordingly. Fresh verification in this audit was limited to static
repository gates.

## Reconciled release gates

| Area | Accepted evidence | Partial evidence | Open release gates | Verdict |
| --- | --- | --- | --- | --- |
| F1 foundation | Manual-transfer registration flow and its focused security evidence are accepted in `F1_VALIDATION.md`. Package composition, free/paid rules, tenant foundations, and substantial payment/RLS behavior are implemented. Wave 1 independently accepted the full disposable PostgreSQL suite at **533 tests / 5,679 assertions**. | The full PostgreSQL run materially strengthens schema/RLS confidence, but `tasks/todo.md` still has unmapped Task 5-7 and Task 9 checkpoints. It does not prove every unchecked acceptance phrase without an exact evidence mapping. Operations documentation exists, but checkout operations remains explicitly provisional. | Xendit sandbox invoice-to-callback-to-entitlement flow; an entire required suite with no skips; repository dependency/secret/PII gates; concurrent-registration acceptance; final operations verification. | **PARTIAL** |
| F9 hardening | The disposable PostgreSQL regression is accepted. The retention action and inert command have accepted isolation evidence. Current lint, format, typecheck, and security-scanner unit tests pass. | Hardening and retention are implemented as guarded/inert building blocks. They do not establish scheduler authority, production observability, backup/restore recovery, or launch readiness. | Secret and PII repository profiles currently fail closed on `public/apple-touch-icon.png` as an unsupported encoding. Load/recovery/backup evidence, queue/cache operational evidence, observability, scheduler/config activation authority, licensed instrument material, psychologist sign-off, Japanese review, legal/privacy approval, and the final launch gate remain open. | **PARTIAL** |
| Organization payment | Canonical checklist bookkeeping is **92/120**. Local backend work through P13b, private core P14/P15, P16-pay-a-i, summary-v2 binding, privacy/consent checks, and P17a-P17b has substantial accepted synthetic evidence. Collective billing proves local composition, idempotency, concurrency/recovery, and fake settlement without live provider activity. | P14-P16 have implemented sub-slices but their top-level/checkpoint acceptance remains unchecked. P17c has several static/legacy sub-scenarios, but not one authorized secure-origin end-to-end chain. P18 has a detailed draft runbook and an architecture test, not an operational handoff. | P15, P16, P17c, and P18 remain canonically unchecked. P17c still lacks one authority-approved secure runtime covering branch selection of 10, one fake invoice, exact settlement, participant projection, desktop/mobile/keyboard, failure states, IDOR, and credential-free evidence. P18 depends on P17c and real operational decisions/evidence. All payment and active gates remain default OFF. | **PARTIAL** |

The F1 count is **81/100** and organization-payment count is **92/120**. These
are checklist bookkeeping only. The historical combined **173/220 (78.6%)** is
not a whole-PRD completion percentage and must not be used as a release signal.
`tasks/f2-f9-acceptance.md` remains the phase-level authority: F1 and F9 are
both `partial`, not accepted.

## Evidence reconciliation details

### F1

- `F1_VALIDATION.md` explicitly says manual transfer passed and Xendit awaits
  sandbox credentials. Its historical 293-pass/3-skip suite cannot close the
  current no-skip gate.
- `tasks/handoffs/integration-2026-09-13-wave1.md` is accepted evidence for the
  fresh isolated PostgreSQL result, including cleanup. It is not browser,
  provider, dependency-audit, or production evidence.
- `tasks/todo.md` still leaves the exact concurrent-registration scenario open:
  first-touch, unknown, and expired attribution are green, while concurrent
  registration is not. No current PostgreSQL test was found that closes that
  exact public-registration graph race.
- Existing operational documents are useful partial evidence. They do not
  override unchecked Task 19 or the explicit provisional status of organization
  checkout operations.

### F9

Fresh static commands at the observed HEAD produced:

| Command | Result | What it proves |
| --- | --- | --- |
| `npm run lint:check` | PASS | Current frontend lint gate only. |
| `npm run format:check` | PASS | Current Prettier gate only. |
| `npm run types:check` | PASS | Current TypeScript check only. |
| `npm run security:scan:test` | PASS, 43/43 | Scanner behavior/unit evidence only. |
| `npm run security:scan:secret` | FAIL closed | Repository gate stops at unsupported-encoding `public/apple-touch-icon.png`. |
| `npm run security:scan:pii` | FAIL closed | Same unsupported-encoding blocker; no clean PII verdict was produced. |

The two failing profiles keep the corresponding F1 Task 18/F9 release checks
open. The failure is not evidence of a discovered secret or PII leak; it is an
incomplete scan result that must stay fail-closed.

### Organization payment

- `tasks/organization-payment/todo.md` is canonical and deliberately retains
  unchecked top-level P15/P16/P17c/P18 despite many green sub-slices.
- `tasks/organization-payment/reports/browser-p17c-readiness-gap.md` concludes
  runtime P17c is blocked. Static PASS rows do not replace the missing unified
  browser chain.
- `tasks/organization-payment/reports/f1-p17c-participant-secure-origin-runtime-gap.md`
  records 66 structural/static tests passing but keeps P17c/P18 open because
  trust, revocation, key custody, native process binding, and effective secure
  browser configuration are not an accepted runtime authority.
- `docs/ORGANIZATION_CHECKOUT_OPERATIONS.md` declares itself
  `DRAFT / PROVISIONAL (P18)`. Its architecture test enforces safe defaults and
  absence of scheduler wiring; it therefore proves preparation, not P18 closeout.

## Stale coordination artifacts

| Artifact | Current interpretation |
| --- | --- |
| `tasks/organization-payment/reports/integration-wave-29.md` | **Stale historical checkpoint.** It predates later accepted P12c/P17 work and cannot describe current release status. |
| Repeated `173/220 = 78.6%` entries in organization-payment reports | **Historical bookkeeping, not project progress.** Use current canonical per-file counts and phase exit criteria. |
| `tasks/organization-payment/parallel-work.md` cursors for backend `01a05839-3b48-7801-8175-0392e8764c23`, frontend `01a05839-3b39-7d83-b59f-9e7432d7883e`, and portal `01a05839-3b18-73e0-8fdc-8db3b02f835d` | **Historical/completed cursor records.** Later mainline integration and Wave 1 evidence supersede them; do not redispatch from those cursor numbers. |
| `tasks/organization-payment/parallel-work.md` as an operational cursor | **Stale for current dispatch.** Its last commit is `e3fd242` from 2026-09-07, while canonical main coordination and acceptance were updated later. It remains useful provenance only. |
| Detached worktrees listed by `git worktree list` | **Preserved, not accepted by presence.** Most point to older detached commits. Their files/claims require patch-equivalence and review before use. |
| `result-contract` at `06a17101` and `result-persistence` at `93826263` | **Explicitly stale/preserved** per `tasks/handoffs/f2-authoritative-result-persistence-readiness.md`; do not cherry-pick. |
| Wave 1 worker rows whose report outputs were still marked running | **Report cursor stale.** The resulting reports (`5afcc1e`, `17cc3e4`, `21b9c73`, `84f5ed3`, `0d14519`) are now committed; future work must start from their reviewed conclusions, not the older running labels. |

The large worktree inventory also includes named repair and final-gate
worktrees. No worktree was deleted, reset, merged, or elevated to evidence in
this audit.

## Exactly one next executable non-overlap slice

**F1 Task 9 — PostgreSQL concurrent public-registration/referral regression.**

This is the smallest release-gate slice that is dependency-unblocked and does
not require live credentials, a browser authority, production configuration, or
a human operational decision.

### Exclusive ownership

- Add only `tests/Postgres/DirectPublicRegistrationConcurrencyTest.php`.
- Do not edit production registration/referral actions, routes, migrations,
  runner/config, canonical checklists, or files currently modified by the
  assessment-session/F5 lanes.

### Required proof

Using only a fresh disposable PostgreSQL database and synthetic records, race
two independent connections/processes with the same trusted positive
registration token and canonical payload behind a deterministic barrier. Prove:

1. exactly one participant, assessment case, order, required consent pair, and
   exact DASS-plus-generic entitlement graph is created;
2. both callers converge on the same canonical registration outcome rather than
   producing duplicates or cross-tenant attribution;
3. first-touch referral branch/source remains immutable;
4. replay with a conflicting payload or referral fails closed and leaves the
   complete original graph unchanged, with no partial mutation.

### Tests and dependencies

- RED then GREEN focused disposable PostgreSQL test for the new file.
- Existing `tests/Feature/Referral/ReferralAttributionTest.php` and the focused
  public-registration feature tests.
- Full disposable PostgreSQL suite, then PHP syntax, scoped PHPStan, Pint, and
  `git diff --check`.
- Dependencies: accepted registration/referral schema and action, accepted case
  and entitlement foundations, synthetic seeded package/branch definitions,
  and an available disposable PostgreSQL runner. No live database or feature
  activation is permitted.

If the regression exposes a production defect, stop at RED and dispatch a
separate, explicitly owned repair. Do not expand this test-only slice in place.

## Audit conclusion

F1, F9, and organization payment all remain **PARTIAL**. The strongest new
cross-cutting evidence is the accepted 533/5,679 disposable PostgreSQL run, but
the release remains blocked by unmapped/open F1 items, failed-closed repository
security profiles, secure unified P17c browser authority/evidence, P18
operations closure, and external/human launch gates. No status should be raised
until the exact canonical acceptance item has matching reviewed evidence.
