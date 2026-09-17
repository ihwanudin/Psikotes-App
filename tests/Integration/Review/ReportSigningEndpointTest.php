<?php

declare(strict_types=1);

namespace Tests\Integration\Review;

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

final class ReportSigningEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function createCase(string $suffix = ''): AssessmentCase
    {
        $branch = Branch::query()->create([
            'code' => 'BR-RSS'.$suffix,
            'name' => 'Cabang Signing Test',
            'ref_code' => 'REF-RSS'.$suffix,
        ]);
        $package = TestPackage::query()->create([
            'code' => 'PKG-RSS'.$suffix,
            'name' => 'Paket Signing Test',
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
            'full_name' => 'Peserta Signing Test',
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

    private function psychologist(): Admin
    {
        return Admin::query()->create([
            'name' => 'Psychologist Test',
            'email' => Str::uuid().'@example.test',
            'password' => 'password',
            'role' => AdminRole::Psychologist,
        ]);
    }

    private function superAdmin(): Admin
    {
        return Admin::query()->create([
            'name' => 'Super Admin Test',
            'email' => Str::uuid().'@example.test',
            'password' => 'password',
            'role' => AdminRole::SuperAdmin,
        ]);
    }

    /** @return array{eligibilityId: string, narrativeId: string} */
    private function seedBaseline(AssessmentCase $case): array
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

        $narrativeId = (string) Str::ulid();
        DB::table('bilingual_narrative_versions')->insert([
            'id' => $narrativeId,
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
            'snapshot_json' => json_encode(['type' => 'bilingual_cluster_narratives'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        return ['eligibilityId' => $eligibilityId, 'narrativeId' => $narrativeId];
    }

    /** @return array<string, mixed> */
    private function validPayload(string $eligibilityVersionId, string $narrativeVersionId): array
    {
        return [
            'eligibility_version_id' => $eligibilityVersionId,
            'narrative_version_id' => $narrativeVersionId,
            'validity' => 'V1',
            'procedure_note' => null,
            'label' => 'DISARANKAN',
            'accompaniment_conditions' => null,
            'unresolved_g7_aspects' => [],
            'overrides' => [],
            'target_field' => 'UMUM',
            'narrative_clusters' => [
                'A' => 'Kemampuan umum telah dirangkum.',
                'B' => 'Cara kerja telah dirangkum.',
                'C' => 'Kepribadian telah dirangkum.',
                'D' => 'Minat kerja telah dirangkum.',
            ],
        ];
    }

    // ——— SIGN (POST) ———

    public function test_sign_with_valid_data_returns_201(): void
    {
        $case = $this->createCase();
        $admin = $this->psychologist();
        $baseline = $this->seedBaseline($case);

        $response = $this->actingAs($admin, 'admin')
            ->postJson("/admin/assessment-cases/{$case->public_id}/review/signing", $this->validPayload($baseline['eligibilityId'], $baseline['narrativeId']));

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => [
                'id', 'assessmentCaseId', 'version', 'state',
                'eligibilityVersionId', 'narrativeVersionId',
                'snapshot', 'signedByAdminId', 'signedAt', 'createdAt',
            ],
        ]);
        $response->assertJsonPath('data.state', 'SIGNED');
        $response->assertJsonPath('data.version', 1);
        $response->assertJsonPath('data.signedByAdminId', $admin->id);
        $response->assertJsonPath('data.eligibilityVersionId', $baseline['eligibilityId']);
        $response->assertJsonPath('data.narrativeVersionId', $baseline['narrativeId']);

        $snapshot = $response->json('data.snapshot');
        $this->assertIsArray($snapshot);
        $this->assertSame('report_signing_snapshot', $snapshot['type']);
        $this->assertArrayHasKey('prerequisite_input', $snapshot);
        $this->assertArrayHasKey('provenance', $snapshot);
        $this->assertArrayHasKey('narrative_cluster_checksums', $snapshot['provenance']);

        // Verify DASS exclusion in the response
        $responseJson = $response->getContent();
        $this->assertStringNotContainsStringIgnoringCase('dass', $responseJson);
    }

    public function test_sign_with_nonexistent_case_returns_404(): void
    {
        $admin = $this->psychologist();
        $nonexistentId = (string) Str::ulid();

        $this->actingAs($admin, 'admin')
            ->postJson("/admin/assessment-cases/{$nonexistentId}/review/signing", [
                'eligibility_version_id' => (string) Str::ulid(),
                'narrative_version_id' => (string) Str::ulid(),
                'validity' => 'V1',
                'procedure_note' => null,
                'label' => 'DISARANKAN',
                'accompaniment_conditions' => null,
                'unresolved_g7_aspects' => [],
                'overrides' => [],
                'target_field' => 'UMUM',
                'narrative_clusters' => [
                    'A' => 'Kemampuan umum telah dirangkum.',
                    'B' => 'Cara kerja telah dirangkum.',
                    'C' => 'Kepribadian telah dirangkum.',
                    'D' => 'Minat kerja telah dirangkum.',
                ],
            ])
            ->assertStatus(404)
            ->assertJson(['error' => ['code' => 'CASE_NOT_FOUND']]);
    }

    public function test_sign_with_blocked_prerequisites_returns_422(): void
    {
        $case = $this->createCase();
        $admin = $this->psychologist();
        $baseline = $this->seedBaseline($case);

        $payload = $this->validPayload($baseline['eligibilityId'], $baseline['narrativeId']);
        $payload['narrative_clusters']['A'] = null;

        $response = $this->actingAs($admin, 'admin')
            ->postJson("/admin/assessment-cases/{$case->public_id}/review/signing", $payload);

        $response->assertStatus(422);
        $response->assertJson(['error' => ['code' => 'SIGNING_BLOCKED']]);
        $response->assertJsonPath('error.blocking_reason_codes', ['NARRATIVE_CLUSTER_A_REQUIRED']);
    }

    // ——— AUTH: FORBIDDEN ———

    public function test_sign_with_non_psychologist_returns_403_with_envelope(): void
    {
        $case = $this->createCase();
        $admin = $this->superAdmin();
        $baseline = $this->seedBaseline($case);

        $response = $this->actingAs($admin, 'admin')
            ->postJson("/admin/assessment-cases/{$case->public_id}/review/signing", $this->validPayload($baseline['eligibilityId'], $baseline['narrativeId']));

        $response->assertStatus(403);
        $response->assertJson([
            'error' => [
                'code' => 'FORBIDDEN',
                'message' => 'Only psychologists may sign reports.',
            ],
        ]);
    }

    public function test_sign_without_auth_returns_401(): void
    {
        $case = $this->createCase();
        $baseline = $this->seedBaseline($case);

        $this->postJson("/admin/assessment-cases/{$case->public_id}/review/signing", $this->validPayload($baseline['eligibilityId'], $baseline['narrativeId']))
            ->assertUnauthorized();
    }

    // ——— Eligibility/narrative version validation ———

    public function test_sign_with_eligibility_version_not_belonging_to_case_returns_404(): void
    {
        $caseA = $this->createCase('A');
        $caseB = $this->createCase('B');
        $admin = $this->psychologist();

        $baselineA = $this->seedBaseline($caseA);
        $this->seedBaseline($caseB);

        $this->actingAs($admin, 'admin')
            ->postJson("/admin/assessment-cases/{$caseB->public_id}/review/signing", $this->validPayload($baselineA['eligibilityId'], $baselineA['narrativeId']))
            ->assertStatus(404)
            ->assertJson(['error' => ['code' => 'ELIGIBILITY_VERSION_NOT_FOUND']]);
    }

    // ——— SHOW (GET) ———

    public function test_show_returns_latest_signing_snapshot(): void
    {
        $case = $this->createCase();
        $admin = $this->psychologist();
        $baseline = $this->seedBaseline($case);

        // Sign first
        $this->actingAs($admin, 'admin')
            ->postJson("/admin/assessment-cases/{$case->public_id}/review/signing", $this->validPayload($baseline['eligibilityId'], $baseline['narrativeId']))
            ->assertStatus(201);

        $response = $this->actingAs($admin, 'admin')
            ->getJson("/admin/assessment-cases/{$case->public_id}/review/signing");

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'id', 'assessmentCaseId', 'version', 'state',
                'eligibilityVersionId', 'narrativeVersionId',
                'snapshot', 'signedByAdminId', 'signedAt', 'createdAt',
            ],
        ]);
        $response->assertJsonPath('data.state', 'SIGNED');
        $response->assertJsonPath('data.version', 1);
    }

    public function test_show_with_nonexistent_case_returns_404(): void
    {
        $admin = $this->psychologist();
        $nonexistentId = (string) Str::ulid();

        $this->actingAs($admin, 'admin')
            ->getJson("/admin/assessment-cases/{$nonexistentId}/review/signing")
            ->assertStatus(404)
            ->assertJson(['error' => ['code' => 'CASE_NOT_FOUND']]);
    }

    public function test_show_without_auth_redirects_to_login(): void
    {
        $case = $this->createCase();

        $this->get("/admin/assessment-cases/{$case->public_id}/review/signing")
            ->assertRedirect('/admin/login');
    }
}
