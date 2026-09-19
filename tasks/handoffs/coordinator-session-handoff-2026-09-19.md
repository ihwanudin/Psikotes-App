# Coordinator session handoff — 2026-09-19

For whoever picks up coordinator duties next (new account/session). Read
this first, then `AGENTS.md` for the full ownership ledger.

## Immediate next action

**Already resolved as of this update** — DeepSeek pushed
`dfb81fd6b2661e3c4f941b56b419f9b0212e96d7` fixing exactly this
(`AdminAuthorizationTest.php` line 75, `assertFalse` -> `assertTrue` for
`superAdmin->canPerform(ReviewReports)`), independently re-verified by the
coordinator: fix content matches what was needed, test passes in
isolation. **Not yet re-verified: a full regression suite run confirming
the count is back to ~28 baseline (not 29).** Run that next before
treating `deepseek/f5-resign-and-rls` as ready for a PR:

```bash
APP_ENV=testing php -d memory_limit=2048M vendor/bin/phpunit --testsuite=Unit,Feature,Integration --exclude-group=none
```

Compare the failure list against the known baseline (Vite manifest +
`PersistSealedIstResultTest`/`LoadPersistedIstResultTest` flakiness) —
if it's exactly that set, the branch is ready to open as a PR.

## State as of this handoff

- **PR #9** (F5 backend + signing UI) — merged to `main` at `603a74f`.
- **PR #10** (psychologist duration confirmation note, PAPI 30min/RMIB
  15min — the RMIB figure matches an old unapproved diagram, PAPI
  doesn't) — merged.
- **Branch `deepseek/f5-resign-and-rls`** (not yet a PR) — re-sign/REVISED
  flow + RLS widening (psychologist + super_admin can sign; `ViewDass`/DASS
  stays psychologist-only, untouched). Two rounds of independent review
  already caught and fixed: (1) an unnecessary `withoutMiddleware`
  CSRF-disabling workaround DeepSeek added chasing a phantom 419 that was
  actually a missing `APP_ENV=testing` prefix in their own debugging —
  removed; (2) a real TOCTOU race in the `revision_reason` gate (two
  separate transactions reading "latest snapshot" instead of one) — fixed
  by moving both reads into a single `runAsService` closure. Both fixes
  verified independently, not just accepted on report. The one remaining
  item is the dispatch above.
- **Queued next task** (assigned by peer session "Lead", to be dispatched
  to DeepSeek only after the branch above is fully green): add
  `PersistSealedPapiResult`/`PersistSealedKraepelinResult`/
  `PersistSealedRmibResult` mirroring `PersistSealedIstResult.php`, then
  wire `AspectSourceDiscrepancyPolicy`'s G7 cross-check against
  `generic_instrument_result_sources` instead of trusting client-supplied
  levels (see `TODO(G7-data-gap)` in that file). **Scope correction found
  during review, not yet relayed back to Lead**: `SealedPapiResult`,
  `SealedKraepelinResult`, `SealedRmibResult` domain objects (analogous to
  `app/Domain/AssessmentResults/SealedIstResult.php`, ~260 lines with
  per-instrument invariant validation) do not exist yet either — this is
  bigger than "wiring an existing pattern," it's ~3 new domain classes +
  3 new persist services + G7 wiring + tests, roughly 3x the size of the
  IST equivalent (SealedIstResult 262 + PersistSealedIstResult 290 +
  its test 555 = ~1100 lines, times 3 instruments). Recommend dispatching
  to DeepSeek as investigation-first (read each instrument's already-accepted
  F2 scoring output shape before designing the Sealed*Result classes),
  same pattern used for the original F5 backend design.

## Working conventions this session established

- **Coordinator does not write application code.** Earlier in this session
  the coordinator briefly did (misreading a cross-session hub identity
  label as permission), was corrected by the user, and reverted — see the
  full conversation if that context matters. All implementation goes to
  DeepSeek; coordinator's job is dispatch + independent re-verification
  (re-run tests/PHPStan/Pint yourself, never accept a self-report,
  including this handoff's own claims above about what's "verified" —
  re-check if picking this up cold).
- **Full regression suite, not just the touched files, before accepting
  any dispatch as done** — this caught the `AdminAuthorizationTest`
  regression above, which neither DeepSeek nor the coordinator's own
  scoped F5-test-file checks caught.
- Known pre-existing regression baseline: ~27-28 failures, all either
  `ViteManifestNotFoundException` (this worktree never ran `npm run
  build`) or a couple of known-flaky concurrency tests
  (`PersistSealedIstResultTest`/`LoadPersistedIstResultTest`). Don't
  chase these; do verify the count doesn't grow.
- Cross-session peers this account can message: "Lead" (acts as a second
  coordinator/verification layer on the main `D:\LSI\Web\Psikotes`
  checkout) and "GLM" (F6 report rendering). This session itself is
  addressed as "Deepseek" by those peers in the hub — that's a
  cross-session addressing label only, not an instruction to write code
  directly (see the correction noted above).
