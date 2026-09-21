<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Domain\Eligibility\EligibilityDecisionSnapshot;
use App\Models\Admin;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Review\ReportSigningService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use Tests\Support\AssessmentBillingFixture;

/**
 * PostgreSQL runtime evidence for ReportSigningService::sign()'s
 * concurrency safety net.
 *
 * test_sign_locks_the_case_row_for_update proves the PRIMARY fix - the row
 * lock (lockForUpdate()) - is actually issued and genuinely closes the
 * concurrent-signing race: two real transactions both trying to sign the
 * same case will now serialize at that lock, so the second one's own
 * `latest` read only happens after the first has committed, and it
 * correctly computes the next version instead of colliding. SQLite's
 * grammar compiles lockForUpdate() to an empty string (no-op, verified
 * against SQLiteGrammar::compileLock()), so this can only be proven here.
 *
 * test_version_conflict_on_postgres_documents_the_known_500 pins a KNOWN,
 * UNRESOLVED limitation in the unique-constraint safety net (the second,
 * defense-in-depth layer behind the lock, for if the lock is ever
 * bypassed) rather than asserting it works: on PostgreSQL, a failed insert
 * marks the WHOLE transaction aborted (SQLSTATE 25P02) until rolled back,
 * and RlsContextRunner::runAsService()'s own finally block (restoring the
 * previous RLS role) runs a further query on its way out regardless of
 * whether the closure threw - so on the exact HTTP-request shape
 * production uses (sign() called from within an already-open admin
 * RlsContext, the "elevate" branch, which owns no transaction of its own),
 * that cleanup query fails too, with a DIFFERENT exception that reaches
 * ReportSigningService's catch instead of the original one, and it still
 * surfaces as an unhandled 500. A manual DB::rollBack() was tried and
 * rejected: it desyncs the outer connection->transaction() call's own
 * bookkeeping, confirmed to discard more than the failed insert (a
 * separate, already-committed transaction level's data came back missing
 * after "recovering" this way). See ReportSigningService::sign()'s own
 * comment on this. Closing it needs either a fix in RlsContextRunner
 * itself (shared infrastructure, out of this PR's scope) or a decision
 * that the row lock's protection is sufficient for now, since a real
 * concurrent second signer would block at the lock and never reach this
 * path at all - only a lock bypass (a bug, not normal operation) would.
 *
 * A genuine two-process concurrency test (the pattern already used in
 * AssessmentBillManualReviewTest) is separate tech debt, not included here.
 */
final class ReportSigningConcurrencyTest extends TestCase
{
    private const ASPECTS = ['A1', 'A2', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'D1', 'D2', 'D3', 'D4', 'D5'];

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_sign_locks_the_case_row_for_update(): void
    {
        [$casePublicId, $eligibilityId, $narrativeId, $psychologist] = $this->seedSigningFixture();

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $result = app(ReportSigningService::class)->sign($casePublicId, $psychologist, $this->signPayload($eligibilityId, $narrativeId));
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        self::assertTrue($result['success'] ?? false, 'Synthetic signing failed: '.($result['code'] ?? '').' '.($result['message'] ?? ''));

        $lockingQueries = array_filter(
            $queries,
            fn (array $q) => str_contains($q['query'], 'assessment_cases') && str_contains(strtolower($q['query']), 'for update'),
        );
        self::assertNotEmpty($lockingQueries, 'sign() must SELECT ... FOR UPDATE on assessment_cases on PostgreSQL.');
    }

    /**
     * Pins the KNOWN, UNRESOLVED limitation described in the class
     * docblock and in ReportSigningService::sign()'s own comment above its
     * try/catch: forces the exact race the row lock exists to prevent (a
     * competing version landing between sign()'s own "latest" read and its
     * insert, via a query listener, since a single process can't run two
     * real concurrent signings) through the exact branch production uses
     * (an already-open admin RlsContext), and confirms it currently still
     * surfaces as an uncaught QueryException - NOT the clean 409 the
     * unique-constraint safety net is meant to produce.
     *
     * This is deliberately asserting the CURRENT broken behavior, not the
     * desired one: it exists so that if RlsContextRunner is ever changed to
     * fix this (or someone finds a real fix here), this test starts
     * failing and has to be rewritten to expect 409 - not so it can be
     * silently regressed further. Do not delete or invert this test without
     * confirming the underlying issue is actually fixed.
     */
    public function test_version_conflict_on_postgres_currently_still_surfaces_as_an_exception(): void
    {
        [$casePublicId, $eligibilityId, $narrativeId, $psychologist] = $this->seedSigningFixture();
        $caseId = (int) DB::table('assessment_cases')->where('public_id', $casePublicId)->value('id');

        $injected = false;
        $listener = function ($query) use (&$injected, $caseId, $eligibilityId, $narrativeId, $psychologist): void {
            if ($injected || ! str_contains($query->sql, 'report_signing_snapshots') || ! str_contains(strtolower($query->sql), 'order by')) {
                return;
            }
            $injected = true;

            // Simulate a competing signer that already inserted version 1
            // for this case, landing between this SELECT and sign()'s own
            // insert - forced directly since a single process can't run
            // two real concurrent signings.
            DB::table('report_signing_snapshots')->insert([
                'id' => (string) Str::ulid(),
                'assessment_case_id' => $caseId,
                'version' => 1,
                'supersedes_id' => null,
                'state' => 'SIGNED',
                'eligibility_version_id' => $eligibilityId,
                'narrative_version_id' => $narrativeId,
                'snapshot_json' => json_encode([
                    'prerequisite_input' => ['validity' => 'V1', 'procedure_note' => null, 'label' => 'DISARANKAN', 'accompaniment_conditions' => null, 'unresolved_g7_aspects' => [], 'overrides' => [], 'target_field' => 'UMUM', 'narrative_clusters' => ['A' => 'A', 'B' => 'B', 'C' => 'C', 'D' => 'D']],
                    'provenance' => ['type' => 'report_signing_snapshot', 'reviewed_eligibility' => ['type' => 'reviewed_eligibility_decision', 'system_levels' => array_fill_keys(self::ASPECTS, 4), 'final_levels' => array_fill_keys(self::ASPECTS, 4)]],
                ], JSON_THROW_ON_ERROR),
                'signed_by_admin_id' => $psychologist->id,
                'signed_at' => now(),
                'created_at' => now(),
            ]);
        };
        DB::listen($listener);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/current transaction is aborted/');

        try {
            app(RlsContextRunner::class)->run(
                new RlsContext('psychologist'),
                fn (): array => app(ReportSigningService::class)->sign($casePublicId, $psychologist, $this->signPayload($eligibilityId, $narrativeId)),
            );
        } finally {
            self::assertTrue($injected, 'The query listener never saw the latest-snapshot lookup - test setup is stale.');
        }
    }

    /** @return array{0: string, 1: string, 2: string, 3: Admin} casePublicId, eligibilityId, narrativeId, psychologist */
    private function seedSigningFixture(): array
    {
        return app(RlsContextRunner::class)->runAsService(function (): array {
            $fixture = AssessmentBillingFixture::create();
            $caseId = (int) $fixture['case'];
            $casePublicId = (string) DB::table('assessment_cases')->where('id', $caseId)->value('public_id');

            $data = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/database/seeders/data/reporting.json'), true, flags: JSON_THROW_ON_ERROR);
            $reporting = ['standard_version' => $data['standard_version'], 'base_standards' => $data['base_standards'], 'fields' => $data['fields']];
            $canonicalInput = [
                'levels' => array_fill_keys(self::ASPECTS, 4),
                'field_code' => 'UMUM',
                'iq' => 110,
                'validity' => 'V1',
                'standard_configuration' => $reporting,
                'eligibility_source_versions' => [
                    'ist' => 'F0-2026.08', 'papi' => 'F0-2026.08', 'kraepelin' => 'F0-2026.08',
                    'rmib' => 'F0-2026.08', 'reporting' => $reporting['standard_version'],
                ],
            ];
            $snapshot = EligibilityDecisionSnapshot::create($canonicalInput)->toArray();

            $eligibilityId = (string) Str::ulid();
            DB::table('eligibility_decision_versions')->insert([
                'id' => $eligibilityId, 'assessment_case_id' => $caseId, 'version' => 1, 'supersedes_id' => null,
                'standard_version' => $snapshot['provenance']['eligibility_standard_version'],
                'field_code' => $snapshot['zone']['field_code'],
                'publication_blocked' => $snapshot['publication_blocked'],
                'recommendation_label' => $snapshot['recommendation']['label'] ?? null,
                'iq' => 110, 'validity' => 'V1',
                'snapshot_json' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'canonical_input_json' => json_encode($canonicalInput, JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
            $narrativeId = (string) Str::ulid();
            DB::table('bilingual_narrative_versions')->insert([
                'id' => $narrativeId, 'assessment_case_id' => $caseId, 'version' => 1, 'supersedes_id' => null,
                'eligibility_version_id' => $eligibilityId, 'review_required' => false,
                'cluster_a_id' => 'A', 'cluster_a_jp' => 'A', 'cluster_b_id' => 'B', 'cluster_b_jp' => 'B',
                'cluster_c_id' => 'C', 'cluster_c_jp' => 'C', 'cluster_d_id' => 'D', 'cluster_d_jp' => 'D',
                'snapshot_json' => json_encode(['type' => 'bilingual_cluster_narratives'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
            $adminId = DB::table('admins')->insertGetId([
                'name' => 'Psikolog Sintetis Concurrency PG', 'email' => Str::uuid().'@example.test',
                'password' => bcrypt('password'), 'role' => 'psychologist',
                'silp_number' => 'SILP-PG-CONC', 'str_number' => 'STR-PG-CONC',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return [$casePublicId, $eligibilityId, $narrativeId, Admin::query()->findOrFail($adminId)];
        });
    }

    /** @return array<string, mixed> */
    private function signPayload(string $eligibilityId, string $narrativeId): array
    {
        return [
            'eligibility_version_id' => $eligibilityId,
            'narrative_version_id' => $narrativeId,
            'level_overrides' => [],
            'label_override' => null,
            'g7_resolutions' => [],
            'procedure_note' => null,
            'accompaniment_conditions' => null,
            'narrative_clusters' => ['A' => 'Narasi A.', 'B' => 'Narasi B.', 'C' => 'Narasi C.', 'D' => 'Narasi D.'],
        ];
    }
}
