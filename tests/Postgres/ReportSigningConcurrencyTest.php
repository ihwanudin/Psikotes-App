<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Domain\Eligibility\EligibilityDecisionSnapshot;
use App\Models\Admin;
use App\Security\RlsContextRunner;
use App\Services\Review\ReportSigningService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use Tests\Support\AssessmentBillingFixture;

/**
 * PostgreSQL runtime evidence that ReportSigningService::sign() actually
 * issues SELECT ... FOR UPDATE on assessment_cases - SQLite's grammar
 * compiles lockForUpdate() to an empty string (no-op), so this can only be
 * proven here, not in the SQLite-based ReportSigningPersistenceTest. That
 * file covers the unique-constraint safety net (works on both drivers);
 * this file covers the lock those tests assume is really in place.
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
        [$casePublicId, $eligibilityId, $narrativeId, $psychologist] = app(RlsContextRunner::class)->runAsService(function (): array {
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

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $result = app(ReportSigningService::class)->sign($casePublicId, $psychologist, [
                'eligibility_version_id' => $eligibilityId,
                'narrative_version_id' => $narrativeId,
                'level_overrides' => [],
                'label_override' => null,
                'g7_resolutions' => [],
                'procedure_note' => null,
                'accompaniment_conditions' => null,
                'narrative_clusters' => ['A' => 'Narasi A.', 'B' => 'Narasi B.', 'C' => 'Narasi C.', 'D' => 'Narasi D.'],
            ]);
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
}
