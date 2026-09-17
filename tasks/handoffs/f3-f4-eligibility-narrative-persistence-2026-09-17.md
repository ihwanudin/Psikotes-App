# F3/F4 Eligibility + Narrative persistence — 2026-09-17

## Worker handoff record

```
Task/thread ID: deepseek/f3-f4-eligibility-narrative
Lane and phase: F3 Eligibility (Grey Area) + F4 Narrative, Third Wave
Branch/worktree: deepseek/f3-f4-eligibility-narrative
  D:\LSI\Web\Psikotes-worktrees\deepseek-f3-f4-eligibility-narrative
Baseline commit: 3e1538d (docs: onboard DeepSeek and lock Claude to coordinator-only role)
Owned files/directories:
  database/migrations/2026_09_16_000100_create_eligibility_decision_versions.php
  database/migrations/2026_09_16_000200_create_bilingual_narrative_versions.php
  app/Http/Controllers/EligibilityDecisionController.php
  app/Http/Controllers/BilingualNarrativeController.php
  app/Http/Controllers/ReviewInputController.php
  tests/Integration/Eligibility/EligibilityDecisionPersistenceTest.php
  tests/Integration/Narrative/BilingualNarrativePersistenceTest.php

Acceptance criteria:
  1. Migration eligibility_decision_versions: append-only, versioned, ULID PK,
     JSONB driver-conditional, check constraints (field_code, validity,
     recommendation_label, iq, standard_version), trigger guard UPDATE/DELETE
     reject + chain-invariant validation, RLS service-only, FK RESTRICT to
     assessment_cases, UNIQUE(assessment_case_id, version),
     UNIQUE(supersedes_id).
  2. Migration bilingual_narrative_versions: append-only, versioned, ULID PK,
     FK RESTRICT to eligibility_decision_versions (eligibility_version_id),
     FK RESTRICT to assessment_cases, denormalized cluster_a_id/jp through
     cluster_d_id/jp, review_required flag, same trigger guard + RLS pattern.
  3. Both migrations: php artisan migrate (full chain from earliest to new)
     SUCCESS; php artisan migrate:rollback --step=2 SUCCESS.
  4. EligibilityDecisionController: store() validates input, creates
     EligibilityDecisionSnapshot, persists via RlsContextRunner::runAsService().
     show() returns latest version per assessment_case_id.
  5. BilingualNarrativeController: store() composes bilingual narrative via
     BilingualClusterNarrativeComposer + ReportingNarrativeCatalog, persists
     via runAsService(). show() returns latest version.
  6. ReviewInputController: bundled F5 review endpoint returning latest
     eligibility + narrative in single response via runAsService().
  7. Integration tests: INSERT v1 valid → sukses; UPDATE ditolak;
     DELETE ditolak; invalid field_code ditolak; broken chain (wrong
     supersedes_id) ditolak; correct chain v2 → sukses; invalid
     eligibility_version_id FK ditolak; supersedes unique constraint;
     latest version retrieval; version must be positive.
  8. All 360 tests pass (302 Unit + 58 Integration).

Verification commands:
  php vendor/bin/phpunit tests/Unit/Eligibility/ tests/Unit/Narrative/
  php vendor/bin/phpunit tests/Integration/
  php vendor/bin/phpunit tests/Integration/Eligibility/EligibilityDecisionPersistenceTest.php tests/Integration/Narrative/BilingualNarrativePersistenceTest.php

Result commit: d1ad798 (feat: add F3 eligibility + F4 narrative persistence,
  controllers, and integration tests)

Tests and evidence:
  Unit (Eligibility + Narrative): 302 tests, 302 passed, 581 assertions, ~1s
    → {"tool":"phpunit","result":"passed","tests":302,"passed":302,"assertions":581,"duration_ms":973}

  Integration (all): 58 tests, 58 passed, 399 assertions, ~27s
    → {"tool":"phpunit","result":"passed","tests":58,"passed":58,"assertions":399,"duration_ms":26928}

  New persistence tests: 21 tests, 21 passed, 29 assertions, ~37s
    → {"tool":"phpunit","result":"passed","tests":21,"passed":21,"assertions":29,"duration_ms":37329}
    Breakdown:
      EligibilityDecisionPersistenceTest: 10 tests, 14 assertions
        - test_insert_version_1_valid_succeeds
        - test_update_is_rejected_append_only
        - test_delete_is_rejected_append_only
        - test_insert_with_invalid_field_code_is_rejected
        - test_insert_version_2_with_wrong_supersedes_id_is_rejected
        - test_insert_version_2_with_correct_chain_succeeds
        - test_version_1_cannot_have_supersedes_id
        - test_version_must_be_positive
        - test_show_returns_latest_version
        - test_supersedes_unique_constraint
      BilingualNarrativePersistenceTest: 11 tests, 15 assertions
        - test_insert_version_1_valid_succeeds
        - test_update_is_rejected_append_only
        - test_delete_is_rejected_append_only
        - test_insert_version_2_with_wrong_supersedes_id_is_rejected
        - test_insert_version_2_with_correct_chain_succeeds
        - test_eligibility_version_id_fk_valid_reference_succeeds
        - test_eligibility_version_id_fk_invalid_reference_rejected
        - test_version_1_cannot_have_supersedes_id
        - test_version_must_be_positive
        - test_show_returns_latest_version
        - test_supersedes_unique_constraint

  Total: 360 tests, 360 passed, 980 assertions, 0 failures, 0 errors, 0 skipped.

  Coordinator verified independently:
    - composer install sukses, vendor/bin/phpunit ada
    - 320 existing Unit Eligibility+Narrative tests: ALL PASS, 631 assertions
    - php artisan migrate (full chain) SUCCESS
    - php artisan migrate:rollback --step=2 SUCCESS

Route registration — 2026-09-17 (same session, same branch)
====================================================================

Worker handoff record (increment 2)

Task/thread ID: deepseek/f3-f4-eligibility-narrative
Lane and phase: F3 Eligibility (Grey Area) + F4 Narrative, Third Wave
  — Route registration increment
Branch/worktree: deepseek/f3-f4-eligibility-narrative
  D:\LSI\Web\Psikotes-worktrees\deepseek-f3-f4-eligibility-narrative
Baseline commit: d1ad798 (feat: add F3 eligibility + F4 narrative persistence...)

Changes in this increment:
  routes/web.php: +3 use statements, +5 route definitions (POST+GET
    eligibility-decisions, POST+GET bilingual-narratives, GET review-input)
    with whereUlid('case'), 4-item middleware stack, named routes.
  app/Providers/AppServiceProvider.php: +3 RateLimiter::for definitions
    (eligibility-decision-access, bilingual-narrative-access,
    review-input-access) — 30/min by admin ID or IP.
  app/Http/Controllers/EligibilityDecisionController.php:
    store()/show() signature: int $caseId → string $case.
    ULID→integer ID resolution inside runAsService() closure.
    Fixed $input reference bug → $canonical inside closure.
  app/Http/Controllers/BilingualNarrativeController.php:
    store()/show() signature: int $caseId → string $case.
    ULID→integer ID resolution inside runAsService() closure.
  app/Http/Controllers/ReviewInputController.php:
    __invoke signature: int $caseId → string $case.
    ULID→integer ID resolution inside runAsService() closure.

New files:
  tests/Integration/Eligibility/EligibilityDecisionEndpointTest.php (4 tests)
  tests/Integration/Narrative/BilingualNarrativeEndpointTest.php (4 tests)
  tests/Integration/Narrative/ReviewInputEndpointTest.php (4 tests)

Route::bind('case', ...) was explicitly REJECTED by coordinator because
SubstituteBindings (pos 9 in $middlewarePriority) runs before ApplyRlsContext
(non-priority), meaning any model binding query would execute without
'service' RLS context on assessment_cases → always 0 rows → 404.

Test evidence — 2026-09-17 (increment 2):
  Unit (all): 1185 tests, 1176 passed (+9 skipped), 3228 assertions
    → {"tool":"phpunit","result":"passed","tests":1185,"passed":1176,"assertions":3228,"duration_ms":18598}

  Integration (all): 70 tests, 70 passed, 453 assertions
    → {"tool":"phpunit","result":"passed","tests":70,"passed":70,"assertions":453,"duration_ms":6722}

  New endpoint tests: 12 tests, 12 passed, 54 assertions
    → {"tool":"phpunit","result":"passed","tests":12,"passed":12,"assertions":54,"duration_ms":136882}
    Breakdown:
      EligibilityDecisionEndpointTest: 4 tests
        - test_store_eligibility_decision_with_valid_case_returns_201
        - test_show_eligibility_decision_returns_latest_version
        - test_eligibility_endpoint_with_nonexistent_case_returns_404
        - test_eligibility_endpoint_without_auth_redirects_to_login
      BilingualNarrativeEndpointTest: 4 tests
        - test_store_bilingual_narrative_with_valid_case_returns_201
        - test_show_bilingual_narrative_returns_latest_version
        - test_narrative_endpoint_with_nonexistent_case_returns_404
        - test_narrative_endpoint_without_auth_redirects_to_login
      ReviewInputEndpointTest: 4 tests
        - test_review_input_with_no_data_returns_404
        - test_review_input_returns_bundled_response
        - test_review_input_with_nonexistent_case_returns_404
        - test_review_input_without_auth_redirects_to_login

  Feature (Security + Payments subset): 11 tests, 11 passed, 44 assertions
    → Full Feature suite has pre-existing issues (Vite manifest missing,
      OOM at 512MB) — not caused by this increment.

Verification commands:
  php vendor/bin/phpunit --testsuite Unit
  php vendor/bin/phpunit tests/Integration/
  php vendor/bin/phpunit tests/Integration/Eligibility/EligibilityDecisionEndpointTest.php tests/Integration/Narrative/BilingualNarrativeEndpointTest.php tests/Integration/Narrative/ReviewInputEndpointTest.php

Result commit: e84b8cd (feat: register F3/F4 eligibility-narrative routes
  with middleware, rate limiters, controller ULID resolution, and HTTP
  endpoint tests)

Resolved blockers:
  1. Route registration: COMPLETED. 5 routes registered in routes/web.php
     with correct middleware stack, named routes, and rate limiters.
  2. Controller ULID resolution: COMPLETED. All 3 controllers resolve
     ULID→integer ID inside runAsService() closures (after RLS context).

Remaining blockers:
  1. Gap INTEGRATION text: bilingual_narrative_versions adalah system baseline
     only. Tidak ada tabel untuk teks naratif hasil edit/integrasi psikolog.
     Direkomendasikan tabel terpisah di domain F5/F6.
  2. Gap level_sistem vs level_final: eligibility_decision_versions tidak
     menerima UPDATE dari psikolog override. F5 (ReviewedEligibilityDecision)
     menyimpan override secara terpisah — confirmed as non-goal.

Next dependency or increment: F5 integration (review/signing state machine),
  F6 result documents.

Review status: REJECTED (e84b8cd) — ModelNotFoundException throws not removed, tests missing error-code body assertions

RLS closure exception removal — 2026-09-17 (same session, same branch)
====================================================================

Worker handoff record (increment 3)

Task/thread ID: deepseek/f3-f4-eligibility-narrative
Lane and phase: F3 Eligibility (Grey Area) + F4 Narrative, Third Wave
  — RLS closure exception removal increment
Branch/worktree: deepseek/f3-f4-eligibility-narrative
  D:\LSI\Web\Psikotes-worktrees\deepseek-f3-f4-eligibility-narrative
Baseline commit: e84b8cd (feat: register F3/F4 eligibility-narrative routes...)

Changes in this increment:
  EligibilityDecisionController:
    - store(): split into two runAsService() calls — first resolves
      case ULID→int (returns ?int), check null → CASE_NOT_FOUND 404,
      second does insert (starts directly from $latest query, no
      case-resolution block needed inside).
    - show(): throw ModelNotFoundException → return null inside closure.
      Error code NOT_FOUND → CASE_NOT_FOUND.
  BilingualNarrativeController:
    - store(): identical two-phase runAsService() pattern.
    - show(): identical throw→return null + CASE_NOT_FOUND.
  ReviewInputController:
    - __invoke(): throw ModelNotFoundException → return [null, null].
      Existing null-check `if ($eligibility === null && $narrative === null)`
      handles [null, null] → returns NOT_FOUND error (unchanged, correct).

  Tests (3 files):
    - EligibilityDecisionEndpointTest::test_eligibility_endpoint_with_nonexistent_case_returns_404:
      assertNotFound() → assertStatus(404) + assertJson(['error' => ['code' => 'CASE_NOT_FOUND']])
    - BilingualNarrativeEndpointTest::test_narrative_endpoint_with_nonexistent_case_returns_404:
      assertNotFound() → assertStatus(404) + assertJson(['error' => ['code' => 'CASE_NOT_FOUND']])
    - ReviewInputEndpointTest::test_review_input_with_nonexistent_case_returns_404:
      assertNotFound() → assertStatus(404) + assertJson(['error' => ['code' => 'NOT_FOUND']])

Test evidence — 2026-09-17 (increment 3):
  12 endpoint tests: 12 passed, 57 assertions (+3 from increment 2: error code checks)
    → {"tool":"phpunit","result":"passed","tests":12,"passed":12,"assertions":57,"duration_ms":3917}

  Unit (all): 1185 tests, 1176 passed (+9 skipped), 3228 assertions
    → {"tool":"phpunit","result":"passed","tests":1185,"passed":1176,"assertions":3228,"duration_ms":10688,"skipped":9}

  Integration (all): 70 tests, 70 passed, 456 assertions (+3 from increment 2: error code checks)
    → {"tool":"phpunit","result":"passed","tests":70,"passed":70,"assertions":456,"duration_ms":5752}

  Feature (Security): 8 tests, 8 passed, 20 assertions
    → {"tool":"phpunit","result":"passed","tests":8,"passed":8,"assertions":20,"duration_ms":807}

  Feature (Payments): pre-existing Vite manifest issue — not caused by this increment.

Verification commands:
  php vendor/bin/phpunit --testsuite Unit
  php vendor/bin/phpunit tests/Integration/
  php vendor/bin/phpunit tests/Integration/Eligibility/EligibilityDecisionEndpointTest.php tests/Integration/Narrative/BilingualNarrativeEndpointTest.php tests/Integration/Narrative/ReviewInputEndpointTest.php

Result commit: 2fdcdf9 (fix: replace ModelNotFoundException throws with
  return-null in RLS closures, split store() into two runAsService() calls)

Resolved blockers:
  1. ModelNotFoundException throws removed: COMPLETED. All 5 throw
     statements across 3 controllers replaced with return-null patterns.
     No exceptions are thrown from inside runAsService() closures.
  2. Test error-code body assertions: COMPLETED. All 3 nonexistent-case
     tests now verify JSON body error code (CASE_NOT_FOUND or NOT_FOUND),
     not just HTTP status.

Remaining blockers:
  1. Gap INTEGRATION text: bilingual_narrative_versions adalah system baseline
     only. Tidak ada tabel untuk teks naratif hasil edit/integrasi psikolog.
     Direkomendasikan tabel terpisah di domain F5/F6.
  2. Gap level_sistem vs level_final: eligibility_decision_versions tidak
     menerima UPDATE dari psikolog override. F5 (ReviewedEligibilityDecision)
     menyimpan override secara terpisah — confirmed as non-goal.

Next dependency or increment: F5 integration (review/signing state machine),
  F6 result documents.

Review status: pending
```
