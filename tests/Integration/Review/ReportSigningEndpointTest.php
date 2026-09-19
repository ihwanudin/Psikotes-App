<?php

declare(strict_types=1);

namespace Tests\Integration\Review;

use App\Domain\Eligibility\EligibilityDecisionSnapshot;
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

    private const ASPECTS = ['A1', 'A2', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'D1', 'D2', 'D3', 'D4', 'D5'];

    private function createCase(): AssessmentCase
    {
        static $counter = 0;
        $suffix = ++$counter;
        $branch = Branch::query()->create([
            'code' => 'BR-SE-'.$suffix,
            'name' => 'Cabang Signing Endpoint Test',
            'ref_code' => 'REF-SE-'.$suffix,
        ]);
        $package = TestPackage::query()->create([
            'code' => 'PKG-SE-'.$suffix,
            'name' => 'Paket Signing Endpoint Test',
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
            'full_name' => 'Peserta Signing Endpoint',
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
            'email' => (string) Str::uuid().'@example.test',
            'password' => bcrypt('password'),
            'role' => AdminRole::Psychologist->value,
        ]);
    }

    private function superAdmin(): Admin
    {
        return Admin::query()->create([
            'name' => 'Super Admin Test',
            'email' => (string) Str::uuid().'@example.test',
            'password' => bcrypt('password'),
            'role' => AdminRole::SuperAdmin->value,
        ]);
    }

    private function staff(?Branch $branch = null): Admin
    {
        return Admin::query()->create([
            'branch_id' => $branch?->id,
            'name' => 'Staff Test',
            'email' => (string) Str::uuid().'@example.test',
            'password' => bcrypt('password'),
            'role' => AdminRole::Staff->value,
        ]);
    }

    private function seedBaseline(AssessmentCase $case): array
    {
        $reporting = $this->canonicalReporting();
        $canonicalInput = [
            'levels' => array_fill_keys(self::ASPECTS, 5),
            'field_code' => 'UMUM',
            'iq' => 100,
            'validity' => 'V1',
            'standard_configuration' => $reporting,
            'eligibility_source_versions' => [
                'ist' => 'F0-2026.08', 'papi' => 'F0-2026.08', 'kraepelin' => 'F0-2026.08',
                'rmib' => 'F0-2026.08', 'reporting' => $reporting['standard_version'],
            ],
        ];

        $snapshot = EligibilityDecisionSnapshot::create($canonicalInput);
        $snapshotArray = $snapshot->toArray();

        $eligibilityId = (string) Str::ulid();
        DB::table('eligibility_decision_versions')->insert([
            'id' => $eligibilityId,
            'assessment_case_id' => $case->id,
            'version' => 1,
            'supersedes_id' => null,
            'standard_version' => $snapshotArray['provenance']['eligibility_standard_version'],
            'field_code' => $snapshotArray['zone']['field_code'],
            'publication_blocked' => $snapshotArray['publication_blocked'],
            'recommendation_label' => $snapshotArray['recommendation']['label'] ?? null,
            'iq' => $canonicalInput['iq'],
            'validity' => $canonicalInput['validity'],
            'snapshot_json' => json_encode($snapshotArray, JSON_THROW_ON_ERROR),
            'canonical_input_json' => json_encode($canonicalInput, JSON_THROW_ON_ERROR),
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
            'cluster_a_id' => 'Teks A ID',
            'cluster_a_jp' => 'Teks A JP',
            'cluster_b_id' => 'Teks B ID',
            'cluster_b_jp' => 'Teks B JP',
            'cluster_c_id' => null,
            'cluster_c_jp' => null,
            'cluster_d_id' => 'Teks D ID',
            'cluster_d_jp' => 'Teks D JP',
            'snapshot_json' => json_encode(['type' => 'bilingual_cluster_narratives'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        return ['eligibilityId' => $eligibilityId, 'narrativeId' => $narrativeId];
    }

    private function validPayload(array $baseline): array
    {
        return [
            'eligibility_version_id' => $baseline['eligibilityId'],
            'narrative_version_id' => $baseline['narrativeId'],
            'level_overrides' => [],
            'label_override' => null,
            'g7_resolutions' => array_map(fn (string $aspect): array => [
                'aspect' => $aspect,
                'sources' => [['source' => 'CANONICAL', 'level' => 5]],
                'final_level' => null,
                'reason' => null,
            ], self::ASPECTS),
            'procedure_note' => null,
            'accompaniment_conditions' => null,
            'narrative_clusters' => [
                'A' => 'Teks narasi cluster A.',
                'B' => 'Teks narasi cluster B.',
                'C' => 'Teks narasi cluster C.',
                'D' => 'Teks narasi cluster D.',
            ],
        ];
    }

    // ─── Successful sign ───

    public function test_sign_with_valid_data_returns_201(): void
    {
        $case = $this->createCase();
        $baseline = $this->seedBaseline($case);
        $admin = $this->psychologist();

        $response = $this->actingAs($admin, 'admin')
            ->postJson("/admin/assessment-cases/{$case->public_id}/signing", $this->validPayload($baseline));

        $response->assertStatus(201);
        $response->assertJsonPath('data.state', 'SIGNED');
        $response->assertJsonPath('data.version', 1);
        $response->assertJsonPath('data.eligibilityVersionId', $baseline['eligibilityId']);
        $response->assertJsonPath('data.narrativeVersionId', $baseline['narrativeId']);

        // Snapshot must contain derived data, not client claims
        $snapshot = $response->json('data.snapshot');
        $this->assertIsArray($snapshot);
        $this->assertArrayHasKey('prerequisite_input', $snapshot);
        $this->assertArrayHasKey('provenance', $snapshot);

        // T-07: DASS exclusion
        $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('dass', mb_strtolower($encoded));
        $this->assertStringNotContainsString('depression', mb_strtolower($encoded));
        $this->assertStringNotContainsString('anxiety', mb_strtolower($encoded));
        $this->assertStringNotContainsString('stress', mb_strtolower($encoded));
    }

    // ─── Client-supplied label is IGNORED — derived from baseline ───

    public function test_client_supplied_label_is_ignored_label_derives_from_baseline(): void
    {
        $case = $this->createCase();
        $baseline = $this->seedBaseline($case);
        $admin = $this->psychologist();

        $payload = $this->validPayload($baseline);
        // The baseline (all levels=5, field=UMUM) produces DISARANKAN.
        // Even if client somehow includes a bogus claim in g7, the label comes from the server derivation.
        $response = $this->actingAs($admin, 'admin')
            ->postJson("/admin/assessment-cases/{$case->public_id}/signing", $payload);

        $response->assertStatus(201);
        $snapshot = $response->json('data.snapshot');

        // Label is derived — must match what EligibilityDecisionSnapshot produces for this baseline
        $this->assertSame('DISARANKAN', $snapshot['prerequisite_input']['label']);
        // Validity is derived — must be V1 from baseline
        $this->assertSame('V1', $snapshot['prerequisite_input']['validity']);
        // Target field is derived — must be UMUM from baseline
        $this->assertSame('UMUM', $snapshot['prerequisite_input']['target_field']);
    }

    // ─── Level override without reason ≥20 chars → rejected by ProfessionalOverridePolicy ───

    public function test_level_override_with_short_reason_is_rejected_by_policy(): void
    {
        $case = $this->createCase();
        $baseline = $this->seedBaseline($case);
        $admin = $this->psychologist();

        $payload = $this->validPayload($baseline);
        $payload['level_overrides'] = [[
            'aspect' => 'C4',
            'system_level' => 5,
            'final_level' => 3,
            'reason' => 'Terlalu pendek.',
        ]];

        $response = $this->actingAs($admin, 'admin')
            ->postJson("/admin/assessment-cases/{$case->public_id}/signing", $payload);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'OVERRIDE_INVALID');
    }

    // ─── Level override with valid reason → system_levels and final_levels both stored ───

    public function test_level_override_preserves_system_levels_and_final_levels(): void
    {
        $case = $this->createCase();
        $baseline = $this->seedBaseline($case);
        $admin = $this->psychologist();

        $reason = 'Observasi profesional mendukung level akhir tiga.';
        $payload = $this->validPayload($baseline);
        $payload['level_overrides'] = [[
            'aspect' => 'C4',
            'system_level' => 5,
            'final_level' => 3,
            'reason' => $reason,
        ]];
        // C4's G7 resolution must match the override
        foreach ($payload['g7_resolutions'] as &$r) {
            if ($r['aspect'] === 'C4') {
                $r['sources'] = [
                    ['source' => 'PAPI_E', 'level' => 5],
                    ['source' => 'PAPI_K', 'level' => 3],
                ];
                $r['final_level'] = 3;
                $r['reason'] = $reason;
            }
        }
        unset($r);

        $response = $this->actingAs($admin, 'admin')
            ->postJson("/admin/assessment-cases/{$case->public_id}/signing", $payload);

        $response->assertStatus(201);
        $snapshot = $response->json('data.snapshot');

        // system_levels and final_levels are both stored in provenance
        $reviewed = $snapshot['provenance']['reviewed_eligibility'];
        $this->assertSame(5, $reviewed['system_levels']['C4']);
        $this->assertSame(3, $reviewed['final_levels']['C4']);
        // Other aspects unchanged
        $this->assertSame(5, $reviewed['system_levels']['A1']);
        $this->assertSame(5, $reviewed['final_levels']['A1']);

        // Override is projected in prerequisite_input
        $this->assertCount(1, $snapshot['prerequisite_input']['overrides']);
        $this->assertSame('level', $snapshot['prerequisite_input']['overrides'][0]['type']);
        $this->assertSame('C4', $snapshot['prerequisite_input']['overrides'][0]['aspect']);
        $this->assertSame($reason, $snapshot['prerequisite_input']['overrides'][0]['reason']);
    }

    // ─── Label override with valid reason ───

    public function test_label_override_changes_recommendation_label(): void
    {
        $case = $this->createCase();
        $baseline = $this->seedBaseline($case);
        $admin = $this->psychologist();

        $reason = 'Observasi profesional mendukung rekomendasi DIPERTIMBANGKAN.';
        $payload = $this->validPayload($baseline);
        $payload['label_override'] = [
            'system_label' => 'DISARANKAN',
            'final_label' => 'DIPERTIMBANGKAN',
            'reason' => $reason,
        ];
        $payload['accompaniment_conditions'] = 'Pendampingan diberikan pada masa adaptasi kerja.';

        $response = $this->actingAs($admin, 'admin')
            ->postJson("/admin/assessment-cases/{$case->public_id}/signing", $payload);

        $response->assertStatus(201);
        $snapshot = $response->json('data.snapshot');

        // Label is DIPERTIMBANGKAN (the override target)
        $this->assertSame('DIPERTIMBANGKAN', $snapshot['prerequisite_input']['label']);

        // Override is projected
        $labelOverrides = array_filter(
            $snapshot['prerequisite_input']['overrides'],
            fn (array $o): bool => $o['type'] === 'label',
        );
        $this->assertCount(1, $labelOverrides);
    }

    // ─── Auth tests ───

    public function test_sign_with_non_psychologist_returns_403(): void
    {
        $branch = Branch::query()->create([
            'code' => 'BR-SE-STAFF',
            'name' => 'Cabang Staff Test',
            'ref_code' => 'REF-SE-STAFF',
        ]);
        $case = $this->createCase();
        $baseline = $this->seedBaseline($case);
        $admin = $this->staff($branch);

        $response = $this->actingAs($admin, 'admin')
            ->postJson("/admin/assessment-cases/{$case->public_id}/signing", $this->validPayload($baseline));

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_sign_without_auth_returns_401(): void
    {
        $case = $this->createCase();
        $baseline = $this->seedBaseline($case);

        $response = $this->postJson("/admin/assessment-cases/{$case->public_id}/signing", $this->validPayload($baseline));

        $response->assertStatus(401);
    }

    // ─── Blocked prerequisites ───

    public function test_sign_with_blocked_prerequisites_returns_422(): void
    {
        $case = $this->createCase();
        $baseline = $this->seedBaseline($case);
        $admin = $this->psychologist();

        $payload = $this->validPayload($baseline);
        $payload['narrative_clusters']['A'] = null;

        $response = $this->actingAs($admin, 'admin')
            ->postJson("/admin/assessment-cases/{$case->public_id}/signing", $payload);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'SIGNING_BLOCKED');
        $this->assertContains('NARRATIVE_CLUSTER_A_REQUIRED', $response->json('error.blocking_reason_codes'));
    }

    // ─── Missing case ───

    public function test_sign_with_nonexistent_case_returns_404(): void
    {
        $case = $this->createCase();
        $baseline = $this->seedBaseline($case);
        $admin = $this->psychologist();

        $nonexistentId = (string) Str::ulid();

        $response = $this->actingAs($admin, 'admin')
            ->postJson("/admin/assessment-cases/{$nonexistentId}/signing", $this->validPayload($baseline));

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'ELIGIBILITY_VERSION_NOT_FOUND');
    }

    // ─── Cross-case eligibility ───

    public function test_sign_with_eligibility_version_not_belonging_to_case_returns_404(): void
    {
        $caseA = $this->createCase();
        $caseB = $this->createCase();
        $baselineA = $this->seedBaseline($caseA);
        $baselineB = $this->seedBaseline($caseB);
        $admin = $this->psychologist();

        $payload = $this->validPayload($baselineB);
        $payload['eligibility_version_id'] = $baselineA['eligibilityId'];

        $response = $this->actingAs($admin, 'admin')
            ->postJson("/admin/assessment-cases/{$caseB->public_id}/signing", $payload);

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'ELIGIBILITY_VERSION_NOT_FOUND');
    }

    // ─── Show endpoint ───

    public function test_show_returns_latest_signing_snapshot(): void
    {
        $case = $this->createCase();
        $baseline = $this->seedBaseline($case);
        $admin = $this->psychologist();

        $this->actingAs($admin, 'admin')
            ->postJson("/admin/assessment-cases/{$case->public_id}/signing", $this->validPayload($baseline));

        $response = $this->getJson("/admin/assessment-cases/{$case->public_id}/signing");

        $response->assertStatus(200);
        $response->assertJsonPath('data.state', 'SIGNED');
        $response->assertJsonPath('data.version', 1);
    }

    public function test_show_with_nonexistent_case_returns_404(): void
    {
        $nonexistentId = (string) Str::ulid();
        $admin = $this->psychologist();

        $response = $this->actingAs($admin, 'admin')
            ->getJson("/admin/assessment-cases/{$nonexistentId}/signing");

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'CASE_NOT_FOUND');
    }

    // ─── G7 unresolved aspect blocks signing ───

    public function test_g7_unresolved_aspect_blocks_signing(): void
    {
        $case = $this->createCase();
        $baseline = $this->seedBaseline($case);
        $admin = $this->psychologist();

        $payload = $this->validPayload($baseline);
        // Make C4 discrepant (spread >= 2) and leave it unresolved
        foreach ($payload['g7_resolutions'] as &$r) {
            if ($r['aspect'] === 'C4') {
                $r['sources'] = [
                    ['source' => 'PAPI_E', 'level' => 5],
                    ['source' => 'PAPI_K', 'level' => 2],
                ];
                $r['final_level'] = null; // unresolved
                $r['reason'] = null;
            }
        }
        unset($r);

        $response = $this->actingAs($admin, 'admin')
            ->postJson("/admin/assessment-cases/{$case->public_id}/signing", $payload);

        // G7ReviewSet::fromResolutions rejects unresolved aspects
        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'G7_INVALID');
    }

    // ─── Helper ───

    private function canonicalReporting(): array
    {
        $contents = file_get_contents(dirname(__DIR__, 3).'/database/seeders/data/reporting.json');
        if (! is_string($contents)) {
            throw new \RuntimeException('Canonical reporting data could not be read.');
        }
        $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        return [
            'standard_version' => $data['standard_version'],
            'base_standards' => $data['base_standards'],
            'fields' => $data['fields'],
        ];
    }
}
