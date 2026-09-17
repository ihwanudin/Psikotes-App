<?php

declare(strict_types=1);

namespace Tests\Integration\Narrative;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AssessmentBillingFixture;
use Tests\TestCase;

final class NarrativeClusterEditPersistenceTest extends TestCase
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

    private function baselineRow(int $caseId, int $version, string $snapshotJson, ?string $supersedesId = null, ?string $eligibilityVersionId = null): array
    {
        $id = (string) Str::ulid();

        if ($eligibilityVersionId === null) {
            $eligibilityVersionId = (string) Str::ulid();
            DB::table('eligibility_decision_versions')->insert([
                'id' => $eligibilityVersionId,
                'assessment_case_id' => $caseId,
                'version' => 1,
                'supersedes_id' => null,
                'standard_version' => 'GA-2026.08',
                'field_code' => 'UMUM',
                'publication_blocked' => false,
                'recommendation_label' => 'DISARANKAN',
                'iq' => 110,
                'validity' => 'V1',
                'snapshot_json' => json_encode(['type' => 'eligibility_snapshot'], JSON_THROW_ON_ERROR),
                'canonical_input_json' => json_encode(['field_code' => 'UMUM'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
        }

        DB::table('bilingual_narrative_versions')->insert([
            'id' => $id,
            'assessment_case_id' => $caseId,
            'version' => $version,
            'supersedes_id' => $supersedesId,
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
            'snapshot_json' => $snapshotJson,
            'created_at' => now(),
        ]);

        return ['id' => $id, 'eligibilityVersionId' => $eligibilityVersionId];
    }

    private function editRow(int $caseId, string $cluster, string $editedText, string $baselineVersionId, string $checksum): array
    {
        return [
            'id' => (string) Str::ulid(),
            'assessment_case_id' => $caseId,
            'cluster' => $cluster,
            'edited_text' => $editedText,
            'baseline_snapshot_checksum' => $checksum,
            'baseline_version_id' => $baselineVersionId,
            'edited_at' => now(),
            'created_at' => now(),
        ];
    }

    // ——— INSERT ———

    public function test_insert_new_edit_succeeds(): void
    {
        $case = $this->assessmentCase();
        $snapshotJson = json_encode(['v' => 1], JSON_THROW_ON_ERROR);
        $baseline = $this->baselineRow($case['case'], 1, $snapshotJson);
        $checksum = hash('sha256', $snapshotJson);

        $edit = $this->editRow($case['case'], 'A', 'Hasil suntingan psikolog.', $baseline['id'], $checksum);
        DB::table('narrative_cluster_edits')->insert($edit);

        $this->assertDatabaseCount('narrative_cluster_edits', 1);
        $this->assertDatabaseHas('narrative_cluster_edits', [
            'id' => $edit['id'],
            'assessment_case_id' => $case['case'],
            'cluster' => 'A',
            'edited_text' => 'Hasil suntingan psikolog.',
            'baseline_version_id' => $baseline['id'],
        ]);
    }

    // ——— UPDATE (mutable, not append-only) ———

    public function test_update_overwrites_existing_edit(): void
    {
        $case = $this->assessmentCase();
        $snapshotJson = json_encode(['v' => 1], JSON_THROW_ON_ERROR);
        $baseline = $this->baselineRow($case['case'], 1, $snapshotJson);
        $checksum = hash('sha256', $snapshotJson);

        $edit = $this->editRow($case['case'], 'B', 'Versi awal suntingan.', $baseline['id'], $checksum);
        DB::table('narrative_cluster_edits')->insert($edit);

        DB::table('narrative_cluster_edits')
            ->where('id', $edit['id'])
            ->update([
                'edited_text' => 'Versi revisi suntingan.',
                'edited_at' => now(),
            ]);

        $this->assertDatabaseCount('narrative_cluster_edits', 1);
        $this->assertDatabaseHas('narrative_cluster_edits', [
            'id' => $edit['id'],
            'edited_text' => 'Versi revisi suntingan.',
        ]);
    }

    // ——— Cross-case FK + trigger ———

    public function test_cross_case_baseline_version_is_rejected(): void
    {
        $caseA = $this->assessmentCase();
        $caseB = $this->assessmentCase();

        $snapshotJsonA = json_encode(['v' => 1], JSON_THROW_ON_ERROR);
        $baselineA = $this->baselineRow($caseA['case'], 1, $snapshotJsonA);
        $snapshotJsonB = json_encode(['v' => 2], JSON_THROW_ON_ERROR);
        $this->baselineRow($caseB['case'], 1, $snapshotJsonB);

        $checksum = hash('sha256', $snapshotJsonA);

        // Edit for case B but referencing case A's baseline_version_id
        $edit = $this->editRow($caseB['case'], 'A', 'Cross-case edit.', $baselineA['id'], $checksum);

        $this->expectException(QueryException::class);

        DB::table('narrative_cluster_edits')->insert($edit);
    }

    // ——— Unique constraint ———

    public function test_unique_constraint_case_cluster_rejected(): void
    {
        $case = $this->assessmentCase();
        $snapshotJson = json_encode(['v' => 1], JSON_THROW_ON_ERROR);
        $baseline = $this->baselineRow($case['case'], 1, $snapshotJson);
        $checksum = hash('sha256', $snapshotJson);

        $edit1 = $this->editRow($case['case'], 'C', 'Suntingan pertama.', $baseline['id'], $checksum);
        DB::table('narrative_cluster_edits')->insert($edit1);

        $edit2 = $this->editRow($case['case'], 'C', 'Suntingan kedua.', $baseline['id'], $checksum);

        $this->expectException(QueryException::class);

        DB::table('narrative_cluster_edits')->insert($edit2);
    }

    // ——— isStale ———

    public function test_is_stale_detection_when_baseline_changes(): void
    {
        $case = $this->assessmentCase();

        $snapshotV1 = json_encode(['v' => 1], JSON_THROW_ON_ERROR);
        $baselineV1 = $this->baselineRow($case['case'], 1, $snapshotV1);
        $checksumV1 = hash('sha256', $snapshotV1);

        $edit = $this->editRow($case['case'], 'D', 'Suntingan cluster D.', $baselineV1['id'], $checksumV1);
        DB::table('narrative_cluster_edits')->insert($edit);

        // Baseline v2 with different snapshot → different checksum (reuse eligibility)
        $snapshotV2 = json_encode(['v' => 2], JSON_THROW_ON_ERROR);
        $this->baselineRow($case['case'], 2, $snapshotV2, $baselineV1['id'], $baselineV1['eligibilityVersionId']);

        $currentChecksum = hash('sha256', $snapshotV2);
        $editFromDb = DB::table('narrative_cluster_edits')
            ->where('id', $edit['id'])
            ->first();

        $this->assertNotNull($editFromDb);
        $this->assertNotSame($editFromDb->baseline_snapshot_checksum, $currentChecksum);
    }

    public function test_is_not_stale_when_baseline_unchanged(): void
    {
        $case = $this->assessmentCase();

        $snapshotJson = json_encode(['v' => 1], JSON_THROW_ON_ERROR);
        $baseline = $this->baselineRow($case['case'], 1, $snapshotJson);
        $checksum = hash('sha256', $snapshotJson);

        $edit = $this->editRow($case['case'], 'A', 'Suntingan cluster A.', $baseline['id'], $checksum);
        DB::table('narrative_cluster_edits')->insert($edit);

        $currentChecksum = hash('sha256', $snapshotJson);
        $editFromDb = DB::table('narrative_cluster_edits')
            ->where('id', $edit['id'])
            ->first();

        $this->assertNotNull($editFromDb);
        $this->assertSame($editFromDb->baseline_snapshot_checksum, $currentChecksum);
    }

    // ——— Cluster validation ———

    public function test_cluster_outside_abcd_is_rejected(): void
    {
        $case = $this->assessmentCase();
        $snapshotJson = json_encode(['v' => 1], JSON_THROW_ON_ERROR);
        $baseline = $this->baselineRow($case['case'], 1, $snapshotJson);
        $checksum = hash('sha256', $snapshotJson);

        $edit = $this->editRow($case['case'], 'E', 'Invalid cluster.', $baseline['id'], $checksum);

        $this->expectException(QueryException::class);

        DB::table('narrative_cluster_edits')->insert($edit);
    }

    // ——— Whitespace handling ———

    public function test_edited_text_leading_trailing_whitespace_is_rejected(): void
    {
        $case = $this->assessmentCase();
        $snapshotJson = json_encode(['v' => 1], JSON_THROW_ON_ERROR);
        $baseline = $this->baselineRow($case['case'], 1, $snapshotJson);
        $checksum = hash('sha256', $snapshotJson);

        $edit = $this->editRow($case['case'], 'A', '  text with spaces  ', $baseline['id'], $checksum);

        $this->expectException(QueryException::class);

        DB::table('narrative_cluster_edits')->insert($edit);
    }

    // ——— Multiple clusters for same case ———

    public function test_multiple_clusters_for_same_case_succeeds(): void
    {
        $case = $this->assessmentCase();
        $snapshotJson = json_encode(['v' => 1], JSON_THROW_ON_ERROR);
        $baseline = $this->baselineRow($case['case'], 1, $snapshotJson);
        $checksum = hash('sha256', $snapshotJson);

        $editA = $this->editRow($case['case'], 'A', 'Cluster A edit.', $baseline['id'], $checksum);
        $editB = $this->editRow($case['case'], 'B', 'Cluster B edit.', $baseline['id'], $checksum);
        $editC = $this->editRow($case['case'], 'C', 'Cluster C edit.', $baseline['id'], $checksum);
        $editD = $this->editRow($case['case'], 'D', 'Cluster D edit.', $baseline['id'], $checksum);

        DB::table('narrative_cluster_edits')->insert($editA);
        DB::table('narrative_cluster_edits')->insert($editB);
        DB::table('narrative_cluster_edits')->insert($editC);
        DB::table('narrative_cluster_edits')->insert($editD);

        $this->assertDatabaseCount('narrative_cluster_edits', 4);
    }

    // ——— Non-existent baseline version ———

    public function test_nonexistent_baseline_version_is_rejected(): void
    {
        $case = $this->assessmentCase();
        $fakeBaselineId = (string) Str::ulid();
        $checksum = hash('sha256', '{}');

        $edit = $this->editRow($case['case'], 'A', 'Edit with bad baseline.', $fakeBaselineId, $checksum);

        $this->expectException(QueryException::class);

        DB::table('narrative_cluster_edits')->insert($edit);
    }
}
