<?php

declare(strict_types=1);

namespace Tests\Integration\Review;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AssessmentBillingFixture;
use Tests\TestCase;

final class ReportSigningPersistenceTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{case: int, participant: int, organization: int} */
    private function assessmentCase(): array
    {
        $fixture = AssessmentBillingFixture::create();

        return [
            'case' => (int) $fixture['case'],
            'participant' => (int) $fixture['participant'],
            'organization' => (int) $fixture['organization'],
        ];
    }

    private function eligibilityRow(int $caseId, int $version = 1): array
    {
        $id = (string) Str::ulid();

        DB::table('eligibility_decision_versions')->insert([
            'id' => $id,
            'assessment_case_id' => $caseId,
            'version' => $version,
            'supersedes_id' => null,
            'standard_version' => 'GA-2026.08',
            'field_code' => 'UMUM',
            'publication_blocked' => false,
            'recommendation_label' => 'DISARANKAN',
            'iq' => 110,
            'validity' => 'V1',
            'snapshot_json' => json_encode(['type' => 'eligibility_snapshot', 'levels' => []], JSON_THROW_ON_ERROR),
            'canonical_input_json' => json_encode(['field_code' => 'UMUM'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        return ['id' => $id];
    }

    private function narrativeRow(int $caseId, string $eligibilityVersionId, int $version = 1): array
    {
        $id = (string) Str::ulid();

        DB::table('bilingual_narrative_versions')->insert([
            'id' => $id,
            'assessment_case_id' => $caseId,
            'version' => $version,
            'supersedes_id' => null,
            'eligibility_version_id' => $eligibilityVersionId,
            'review_required' => false,
            'cluster_a_id' => 'Teks cluster A.',
            'cluster_a_jp' => 'クラスターAのテキスト。',
            'cluster_b_id' => 'Teks cluster B.',
            'cluster_b_jp' => 'クラスターBのテキスト。',
            'cluster_c_id' => null,
            'cluster_c_jp' => null,
            'cluster_d_id' => 'Teks cluster D.',
            'cluster_d_jp' => 'クラスターDのテキスト。',
            'snapshot_json' => json_encode(['type' => 'bilingual_cluster_narratives'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        return ['id' => $id];
    }

    private function adminRow(): array
    {
        $id = DB::table('admins')->insertGetId([
            'name' => 'Psychologist Test',
            'email' => Str::uuid().'@example.test',
            'password' => 'password',
            'role' => 'psychologist',
        ]);

        return ['id' => (int) $id];
    }

    /** @return array<string, mixed> */
    private function snapshotRow(
        int $caseId,
        string $eligibilityVersionId,
        string $narrativeVersionId,
        int $adminId,
        int $version = 1,
        ?string $supersedesId = null,
    ): array {
        return [
            'id' => (string) Str::ulid(),
            'assessment_case_id' => $caseId,
            'version' => $version,
            'supersedes_id' => $supersedesId,
            'state' => 'SIGNED',
            'eligibility_version_id' => $eligibilityVersionId,
            'narrative_version_id' => $narrativeVersionId,
            'snapshot_json' => json_encode([
                'type' => 'report_signing_snapshot',
                'prerequisite_input' => ['validity' => 'V1', 'label' => 'DISARANKAN'],
                'provenance' => [],
                'signed_by_admin_id' => $adminId,
                'signed_at' => now()->toISOString(),
            ], JSON_THROW_ON_ERROR),
            'signed_by_admin_id' => $adminId,
            'signed_at' => now(),
            'created_at' => now(),
        ];
    }

    // ——— INSERT ———

    public function test_insert_signing_snapshot_succeeds(): void
    {
        $case = $this->assessmentCase();
        $eligibility = $this->eligibilityRow($case['case']);
        $narrative = $this->narrativeRow($case['case'], $eligibility['id']);
        $admin = $this->adminRow();

        $snapshot = $this->snapshotRow($case['case'], $eligibility['id'], $narrative['id'], $admin['id']);
        DB::table('report_signing_snapshots')->insert($snapshot);

        $this->assertDatabaseCount('report_signing_snapshots', 1);
        $this->assertDatabaseHas('report_signing_snapshots', [
            'id' => $snapshot['id'],
            'assessment_case_id' => $case['case'],
            'version' => 1,
            'state' => 'SIGNED',
            'eligibility_version_id' => $eligibility['id'],
            'narrative_version_id' => $narrative['id'],
            'signed_by_admin_id' => $admin['id'],
        ]);
    }

    // ——— Append-only ———

    public function test_update_is_rejected(): void
    {
        $case = $this->assessmentCase();
        $eligibility = $this->eligibilityRow($case['case']);
        $narrative = $this->narrativeRow($case['case'], $eligibility['id']);
        $admin = $this->adminRow();

        $snapshot = $this->snapshotRow($case['case'], $eligibility['id'], $narrative['id'], $admin['id']);
        DB::table('report_signing_snapshots')->insert($snapshot);

        $this->expectException(QueryException::class);

        DB::table('report_signing_snapshots')
            ->where('id', $snapshot['id'])
            ->update(['state' => 'REVISED']);
    }

    public function test_delete_is_rejected(): void
    {
        $case = $this->assessmentCase();
        $eligibility = $this->eligibilityRow($case['case']);
        $narrative = $this->narrativeRow($case['case'], $eligibility['id']);
        $admin = $this->adminRow();

        $snapshot = $this->snapshotRow($case['case'], $eligibility['id'], $narrative['id'], $admin['id']);
        DB::table('report_signing_snapshots')->insert($snapshot);

        $this->expectException(QueryException::class);

        DB::table('report_signing_snapshots')
            ->where('id', $snapshot['id'])
            ->delete();
    }

    // ——— Version chain ———

    public function test_version_chain_succeeds(): void
    {
        $case = $this->assessmentCase();
        $eligibility = $this->eligibilityRow($case['case']);
        $narrative = $this->narrativeRow($case['case'], $eligibility['id']);
        $admin = $this->adminRow();

        $v1 = $this->snapshotRow($case['case'], $eligibility['id'], $narrative['id'], $admin['id'], 1);
        DB::table('report_signing_snapshots')->insert($v1);

        $v2 = $this->snapshotRow($case['case'], $eligibility['id'], $narrative['id'], $admin['id'], 2, $v1['id']);
        DB::table('report_signing_snapshots')->insert($v2);

        $this->assertDatabaseCount('report_signing_snapshots', 2);
        $this->assertDatabaseHas('report_signing_snapshots', [
            'id' => $v2['id'],
            'version' => 2,
            'supersedes_id' => $v1['id'],
        ]);
    }

    public function test_version_chain_broken_sequence_is_rejected(): void
    {
        $case = $this->assessmentCase();
        $eligibility = $this->eligibilityRow($case['case']);
        $narrative = $this->narrativeRow($case['case'], $eligibility['id']);
        $admin = $this->adminRow();

        $v1 = $this->snapshotRow($case['case'], $eligibility['id'], $narrative['id'], $admin['id'], 1);
        DB::table('report_signing_snapshots')->insert($v1);

        $this->expectException(QueryException::class);

        $v3 = $this->snapshotRow($case['case'], $eligibility['id'], $narrative['id'], $admin['id'], 3, $v1['id']);
        DB::table('report_signing_snapshots')->insert($v3);
    }

    public function test_initial_version_with_supersedes_is_rejected(): void
    {
        $case = $this->assessmentCase();
        $eligibility = $this->eligibilityRow($case['case']);
        $narrative = $this->narrativeRow($case['case'], $eligibility['id']);
        $admin = $this->adminRow();

        $this->expectException(QueryException::class);

        $snapshot = $this->snapshotRow($case['case'], $eligibility['id'], $narrative['id'], $admin['id'], 1, (string) Str::ulid());
        DB::table('report_signing_snapshots')->insert($snapshot);
    }

    // ——— Cross-case guard ———

    public function test_cross_case_eligibility_version_is_rejected(): void
    {
        $caseA = $this->assessmentCase();
        $caseB = $this->assessmentCase();

        $eligibilityA = $this->eligibilityRow($caseA['case']);
        $eligibilityB = $this->eligibilityRow($caseB['case']);
        $narrativeB = $this->narrativeRow($caseB['case'], $eligibilityB['id']);
        $admin = $this->adminRow();

        $this->expectException(QueryException::class);

        $snapshot = $this->snapshotRow($caseB['case'], $eligibilityA['id'], $narrativeB['id'], $admin['id']);
        DB::table('report_signing_snapshots')->insert($snapshot);
    }

    public function test_cross_case_narrative_version_is_rejected(): void
    {
        $caseA = $this->assessmentCase();
        $caseB = $this->assessmentCase();

        $eligibilityA = $this->eligibilityRow($caseA['case']);
        $narrativeA = $this->narrativeRow($caseA['case'], $eligibilityA['id']);
        $eligibilityB = $this->eligibilityRow($caseB['case']);
        $admin = $this->adminRow();

        $this->expectException(QueryException::class);

        $snapshot = $this->snapshotRow($caseB['case'], $eligibilityB['id'], $narrativeA['id'], $admin['id']);
        DB::table('report_signing_snapshots')->insert($snapshot);
    }

    // ——— CHECK constraint: state must be SIGNED ———

    public function test_non_signed_state_is_rejected(): void
    {
        $case = $this->assessmentCase();
        $eligibility = $this->eligibilityRow($case['case']);
        $narrative = $this->narrativeRow($case['case'], $eligibility['id']);
        $admin = $this->adminRow();

        $snapshot = $this->snapshotRow($case['case'], $eligibility['id'], $narrative['id'], $admin['id']);
        $snapshot['state'] = 'PUBLISHED';

        $this->expectException(QueryException::class);

        DB::table('report_signing_snapshots')->insert($snapshot);
    }

    // ——— NOT NULL enforcement ———

    public function test_null_signed_by_admin_id_is_rejected(): void
    {
        $case = $this->assessmentCase();
        $eligibility = $this->eligibilityRow($case['case']);
        $narrative = $this->narrativeRow($case['case'], $eligibility['id']);
        $admin = $this->adminRow();

        $snapshot = $this->snapshotRow($case['case'], $eligibility['id'], $narrative['id'], $admin['id']);
        $snapshot['signed_by_admin_id'] = null;

        $this->expectException(QueryException::class);

        DB::table('report_signing_snapshots')->insert($snapshot);
    }

    public function test_null_signed_at_is_rejected(): void
    {
        $case = $this->assessmentCase();
        $eligibility = $this->eligibilityRow($case['case']);
        $narrative = $this->narrativeRow($case['case'], $eligibility['id']);
        $admin = $this->adminRow();

        $snapshot = $this->snapshotRow($case['case'], $eligibility['id'], $narrative['id'], $admin['id']);
        $snapshot['signed_at'] = null;

        $this->expectException(QueryException::class);

        DB::table('report_signing_snapshots')->insert($snapshot);
    }

    // ——— T-07: DASS exclusion ———

    public function test_snapshot_json_contains_no_dass_fields(): void
    {
        $case = $this->assessmentCase();
        $eligibility = $this->eligibilityRow($case['case']);
        $narrative = $this->narrativeRow($case['case'], $eligibility['id']);
        $admin = $this->adminRow();

        $snapshot = $this->snapshotRow($case['case'], $eligibility['id'], $narrative['id'], $admin['id']);
        DB::table('report_signing_snapshots')->insert($snapshot);

        $row = DB::table('report_signing_snapshots')->where('id', $snapshot['id'])->sole();
        $decoded = json_decode($row->snapshot_json, true, 512, JSON_THROW_ON_ERROR);

        $json = $row->snapshot_json;
        $this->assertStringNotContainsStringIgnoringCase('dass', $json);
        $this->assertStringNotContainsStringIgnoringCase('depression', $json);
        $this->assertStringNotContainsStringIgnoringCase('anxiety', $json);
        $this->assertStringNotContainsStringIgnoringCase('stress', $json);

        $this->assertArrayNotHasKey('dass_results', $decoded);
        $this->assertArrayNotHasKey('dass', $decoded);
        if (isset($decoded['provenance']) && is_array($decoded['provenance'])) {
            $this->assertArrayNotHasKey('dass_results', $decoded['provenance']);
            $this->assertArrayNotHasKey('dass', $decoded['provenance']);
        }
    }

    // ——— Unique constraints ———

    public function test_duplicate_case_version_is_rejected(): void
    {
        $case = $this->assessmentCase();
        $eligibility = $this->eligibilityRow($case['case']);
        $narrative = $this->narrativeRow($case['case'], $eligibility['id']);
        $admin = $this->adminRow();

        $v1 = $this->snapshotRow($case['case'], $eligibility['id'], $narrative['id'], $admin['id'], 1);
        DB::table('report_signing_snapshots')->insert($v1);

        $this->expectException(QueryException::class);

        $duplicate = $this->snapshotRow($case['case'], $eligibility['id'], $narrative['id'], $admin['id'], 1);
        DB::table('report_signing_snapshots')->insert($duplicate);
    }
}
