<?php

declare(strict_types=1);

namespace Tests\Integration\Narrative;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AssessmentCase;
use App\Models\Branch;
use App\Models\Participant;
use App\Models\TestPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class NarrativeClusterEditEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function createCase(string $suffix = ''): AssessmentCase
    {
        $branch = Branch::query()->create([
            'code' => 'BR-NCE'.$suffix,
            'name' => 'Cabang Cluster Edit Test',
            'ref_code' => 'REF-NCE'.$suffix,
        ]);
        $package = TestPackage::query()->create([
            'code' => 'PKG-NCE'.$suffix,
            'name' => 'Paket Cluster Edit Test',
            'amount' => 250_000,
            'currency' => 'IDR',
            'is_active' => true,
        ]);
        $package->items()->create(['test_type' => 'ist']);
        $participant = Participant::query()->create([
            'branch_id' => $branch->id,
            'referral_branch_id' => $branch->id,
            'referral_source' => 'default',
            'package_id' => $package->id,
            'source_system' => 'DIRECT_PUBLIC',
            'full_name' => 'Peserta Cluster Edit',
            'gender' => 'female',
            'birth_date' => '2001-04-15',
            'education_level' => 'SMA/SMK',
            'intended_field' => 'UMUM',
            'phone' => '+6281234567890',
        ]);

        return AssessmentCase::query()->create([
            'public_id' => (string) Str::ulid(),
            'participant_id' => $participant->id,
            'organization_id' => $branch->id,
            'package_id' => $package->id,
            'origin' => 'DIRECT_PUBLIC',
            'intended_field_snapshot' => 'UMUM',
        ]);
    }

    private function admin(): Admin
    {
        return Admin::query()->create([
            'name' => 'Admin Cluster Edit Test',
            'email' => Str::uuid().'@example.test',
            'password' => 'password',
            'role' => AdminRole::Psychologist,
        ]);
    }

    private function seedBaseline(AssessmentCase $case): object
    {
        $eligibilityId = (string) Str::ulid();
        DB::table('eligibility_decision_versions')->insert([
            'id' => $eligibilityId,
            'assessment_case_id' => $case->id,
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

        $baselineId = (string) Str::ulid();
        $snapshotJson = json_encode(['type' => 'bilingual_cluster_narratives'], JSON_THROW_ON_ERROR);
        DB::table('bilingual_narrative_versions')->insert([
            'id' => $baselineId,
            'assessment_case_id' => $case->id,
            'version' => 1,
            'supersedes_id' => null,
            'eligibility_version_id' => $eligibilityId,
            'review_required' => false,
            'cluster_a_id' => 'Teks baseline cluster A.',
            'cluster_a_jp' => 'クラスターA。',
            'cluster_b_id' => 'Teks baseline cluster B.',
            'cluster_b_jp' => 'クラスターB。',
            'cluster_c_id' => null,
            'cluster_c_jp' => null,
            'cluster_d_id' => 'Teks baseline cluster D.',
            'cluster_d_jp' => 'クラスターD。',
            'snapshot_json' => $snapshotJson,
            'created_at' => now(),
        ]);

        return (object) [
            'id' => $baselineId,
            'snapshotJson' => $snapshotJson,
        ];
    }

    private function validPayload(string $baselineVersionId): array
    {
        return [
            'edited_text' => 'Hasil suntingan psikolog untuk cluster A.',
            'baseline_version_id' => $baselineVersionId,
        ];
    }

    // ——— UPDATE ———

    public function test_update_with_valid_data_returns_200(): void
    {
        $case = $this->createCase();
        $admin = $this->admin();
        $baseline = $this->seedBaseline($case);

        $response = $this->actingAs($admin, 'admin')
            ->putJson("/admin/assessment-cases/{$case->public_id}/narrative-cluster-edits/A", $this->validPayload($baseline->id));

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'id', 'assessmentCaseId', 'cluster', 'editedText',
                'baselineSnapshotChecksum', 'baselineVersionId', 'editedAt',
            ],
        ]);
        $response->assertJsonPath('data.cluster', 'A');
        $response->assertJsonPath('data.editedText', 'Hasil suntingan psikolog untuk cluster A.');
    }

    public function test_update_with_nonexistent_case_returns_404(): void
    {
        $admin = $this->admin();
        $nonexistentId = (string) Str::ulid();
        $fakeBaselineId = (string) Str::ulid();

        $this->actingAs($admin, 'admin')
            ->putJson("/admin/assessment-cases/{$nonexistentId}/narrative-cluster-edits/A", [
                'edited_text' => 'Some text.',
                'baseline_version_id' => $fakeBaselineId,
            ])
            ->assertStatus(404)
            ->assertJson(['error' => ['code' => 'CASE_NOT_FOUND']]);
    }

    public function test_update_with_invalid_cluster_returns_422(): void
    {
        $case = $this->createCase();
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->putJson("/admin/assessment-cases/{$case->public_id}/narrative-cluster-edits/E", [
                'edited_text' => 'Some text.',
                'baseline_version_id' => (string) Str::ulid(),
            ])
            ->assertStatus(422)
            ->assertJson(['error' => ['code' => 'VALIDATION_FAILED']]);
    }

    public function test_update_with_empty_edited_text_after_trim_returns_422(): void
    {
        $case = $this->createCase();
        $admin = $this->admin();
        $baseline = $this->seedBaseline($case);

        $this->actingAs($admin, 'admin')
            ->putJson("/admin/assessment-cases/{$case->public_id}/narrative-cluster-edits/A", [
                'edited_text' => '   ',
                'baseline_version_id' => $baseline->id,
            ])
            ->assertStatus(422);
    }

    public function test_update_with_cross_case_baseline_returns_422(): void
    {
        $caseA = $this->createCase('A');
        $caseB = $this->createCase('B');
        $admin = $this->admin();

        $baselineA = $this->seedBaseline($caseA);
        // Also seed a baseline for caseB so the case exists with data
        $this->seedBaseline($caseB);

        // Try to update case B using case A's baseline_version_id
        $this->actingAs($admin, 'admin')
            ->putJson("/admin/assessment-cases/{$caseB->public_id}/narrative-cluster-edits/A", $this->validPayload($baselineA->id))
            ->assertStatus(422)
            ->assertJson(['error' => ['code' => 'BASELINE_CASE_MISMATCH']]);
    }

    public function test_update_with_nonexistent_baseline_version_id_returns_404(): void
    {
        $case = $this->createCase();
        $admin = $this->admin();
        $fakeBaselineId = (string) Str::ulid();

        $this->actingAs($admin, 'admin')
            ->putJson("/admin/assessment-cases/{$case->public_id}/narrative-cluster-edits/A", [
                'edited_text' => 'Some text.',
                'baseline_version_id' => $fakeBaselineId,
            ])
            ->assertStatus(404)
            ->assertJson(['error' => ['code' => 'BASELINE_NOT_FOUND']]);
    }

    // ——— SHOW ———

    public function test_show_returns_clusters_with_baseline_and_edits(): void
    {
        $case = $this->createCase();
        $admin = $this->admin();
        $baseline = $this->seedBaseline($case);

        // Create an edit for cluster A
        $this->actingAs($admin, 'admin')
            ->putJson("/admin/assessment-cases/{$case->public_id}/narrative-cluster-edits/A", $this->validPayload($baseline->id))
            ->assertOk();

        $response = $this->actingAs($admin, 'admin')
            ->getJson("/admin/assessment-cases/{$case->public_id}/narrative-cluster-edits");

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'clusters' => [
                    'A' => ['baseline', 'edited', 'isStale'],
                    'B' => ['baseline', 'edited', 'isStale'],
                    'C' => ['baseline', 'edited', 'isStale'],
                    'D' => ['baseline', 'edited', 'isStale'],
                ],
                'baselineVersionId',
            ],
        ]);
        $response->assertJsonPath('data.clusters.A.baseline', 'Teks baseline cluster A.');
        $response->assertJsonPath('data.clusters.A.edited', 'Hasil suntingan psikolog untuk cluster A.');
        $response->assertJsonPath('data.clusters.A.isStale', false);
        $response->assertJsonPath('data.clusters.B.edited', null);
    }

    public function test_show_with_nonexistent_case_returns_404(): void
    {
        $admin = $this->admin();
        $nonexistentId = (string) Str::ulid();

        $this->actingAs($admin, 'admin')
            ->getJson("/admin/assessment-cases/{$nonexistentId}/narrative-cluster-edits")
            ->assertStatus(404)
            ->assertJson(['error' => ['code' => 'CASE_NOT_FOUND']]);
    }

    // ——— AUTH ———

    public function test_endpoint_without_auth_redirects_to_login(): void
    {
        $case = $this->createCase();

        $this->get("/admin/assessment-cases/{$case->public_id}/narrative-cluster-edits")
            ->assertRedirect('/admin/login');

        $this->put("/admin/assessment-cases/{$case->public_id}/narrative-cluster-edits/A", [])
            ->assertRedirect('/admin/login');
    }
}
