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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReportSigningPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private const ASPECTS = ['A1', 'A2', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'D1', 'D2', 'D3', 'D4', 'D5'];

    // ─── Test case factory ───

    private function createCase(): AssessmentCase
    {
        static $counter = 0;
        $suffix = ++$counter;
        $branch = Branch::query()->create([
            'code' => 'BR-SP-'.$suffix,
            'name' => 'Cabang Signing Persistence Test',
            'ref_code' => 'REF-SP-'.$suffix,
        ]);
        $package = TestPackage::query()->create([
            'code' => 'PKG-SP-'.$suffix,
            'name' => 'Paket Signing Persistence Test',
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
            'full_name' => 'Peserta Signing Persistence',
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

    // ─── Baseline seed (for endpoint-based persistence tests) ───

    /** @return array{eligibilityId: string, narrativeId: string} */
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

    private function psychologist(): Admin
    {
        return Admin::query()->create([
            'name' => 'Psychologist Test',
            'email' => (string) Str::uuid().'@example.test',
            'password' => bcrypt('password'),
            'role' => AdminRole::Psychologist->value,
        ]);
    }

    /** @return array<string, mixed> */
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

    // ─── Helpers for raw DB constraint tests ───

    /** @return array{case: int, participant: int, organization: int} */
    private function assessmentCase(): array
    {
        $case = $this->createCase();

        return [
            'case' => $case->id,
            'participant' => $case->participant_id,
            'organization' => $case->organization_id ?? $case->id,
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
                'prerequisite_input' => [
                    'validity' => 'V1',
                    'procedure_note' => null,
                    'label' => 'DISARANKAN',
                    'accompaniment_conditions' => null,
                    'unresolved_g7_aspects' => [],
                    'overrides' => [],
                    'target_field' => 'UMUM',
                    'narrative_clusters' => [
                        'A' => 'Teks A.',
                        'B' => 'Teks B.',
                        'C' => null,
                        'D' => 'Teks D.',
                    ],
                ],
                'provenance' => [
                    'type' => 'report_signing_snapshot',
                    'reviewed_eligibility' => [
                        'type' => 'reviewed_eligibility_decision',
                        'system_levels' => array_fill_keys(self::ASPECTS, 5),
                        'final_levels' => array_fill_keys(self::ASPECTS, 5),
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
            'signed_by_admin_id' => $adminId,
            'signed_at' => now(),
            'created_at' => now(),
        ];
    }

    // ═══════════════════════════════════════════════
    // INSERT
    // ═══════════════════════════════════════════════

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

    // ═══════════════════════════════════════════════
    // APPEND-ONLY ENFORCEMENT
    // ═══════════════════════════════════════════════

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

    // ═══════════════════════════════════════════════
    // VERSION CHAIN
    // ═══════════════════════════════════════════════

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

    // ═══════════════════════════════════════════════
    // CROSS-CASE GUARDS
    // ═══════════════════════════════════════════════

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

    // ═══════════════════════════════════════════════
    // CHECK CONSTRAINT: state must be SIGNED
    // ═══════════════════════════════════════════════

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

    // ═══════════════════════════════════════════════
    // NOT NULL ENFORCEMENT
    // ═══════════════════════════════════════════════

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

    // ═══════════════════════════════════════════════
    // UNIQUE CONSTRAINT
    // ═══════════════════════════════════════════════

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

    // ═══════════════════════════════════════════════
    // T-07: DASS EXCLUSION — proven at persistence level
    // ═══════════════════════════════════════════════

    public function test_snapshot_json_contains_no_dass_fields(): void
    {
        $case = $this->createCase();
        $baseline = $this->seedBaseline($case);
        $admin = $this->psychologist();

        // Sign through the endpoint to get real snapshot_json
        $response = $this->actingAs($admin, 'admin')
            ->postJson("/admin/assessment-cases/{$case->public_id}/signing", $this->validPayload($baseline));

        $response->assertStatus(201);
        $snapshotId = $response->json('data.id');

        // Read raw from DB — T-07 must be provable at persistence level
        $row = DB::table('report_signing_snapshots')->where('id', $snapshotId)->sole();
        $decoded = json_decode($row->snapshot_json, true, 512, JSON_THROW_ON_ERROR);

        $json = $row->snapshot_json;
        $this->assertStringNotContainsStringIgnoringCase('dass', $json);
        $this->assertStringNotContainsStringIgnoringCase('depression', $json);
        $this->assertStringNotContainsStringIgnoringCase('anxiety', $json);
        $this->assertStringNotContainsStringIgnoringCase('stress', $json);

        // Recursive check for any DASS key anywhere in the tree
        $this->assertArrayNotHasKey('dass_results', $decoded);
        $this->assertArrayNotHasKey('dass', $decoded);
        foreach (['prerequisite_input', 'provenance'] as $topKey) {
            if (isset($decoded[$topKey]) && is_array($decoded[$topKey])) {
                $this->assertArrayNotHasKey('dass_results', $decoded[$topKey]);
                $this->assertArrayNotHasKey('dass', $decoded[$topKey]);
            }
        }
    }

    // ═══════════════════════════════════════════════
    // DERIVED DATA INTEGRITY — snapshot_json must contain
    // prerequisite_input + provenance from composer, not raw client claims
    // ═══════════════════════════════════════════════

    public function test_snapshot_json_structure_is_prerequisite_input_plus_provenance(): void
    {
        $case = $this->createCase();
        $baseline = $this->seedBaseline($case);
        $admin = $this->psychologist();

        $response = $this->actingAs($admin, 'admin')
            ->postJson("/admin/assessment-cases/{$case->public_id}/signing", $this->validPayload($baseline));

        $response->assertStatus(201);
        $snapshotId = $response->json('data.id');

        $row = DB::table('report_signing_snapshots')->where('id', $snapshotId)->sole();
        $snapshotJson = json_decode($row->snapshot_json, true, 512, JSON_THROW_ON_ERROR);

        // snapshot_json has exactly two top-level keys
        $this->assertCount(2, $snapshotJson);
        $this->assertArrayHasKey('prerequisite_input', $snapshotJson);
        $this->assertArrayHasKey('provenance', $snapshotJson);

        // prerequisite_input must contain canonical keys (not arbitrary)
        $prereq = $snapshotJson['prerequisite_input'];
        $expectedKeys = ['validity', 'procedure_note', 'label', 'accompaniment_conditions',
            'unresolved_g7_aspects', 'overrides', 'target_field', 'narrative_clusters'];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $prereq, "prerequisite_input missing key: {$key}");
        }

        // provenance must contain the composer's provenance structure
        $prov = $snapshotJson['provenance'];
        $this->assertArrayHasKey('type', $prov);
        $this->assertSame('report_signing_snapshot', $prov['type']);
        $this->assertArrayHasKey('reviewed_eligibility', $prov);
        $this->assertArrayHasKey('system_levels', $prov['reviewed_eligibility']);
        $this->assertArrayHasKey('final_levels', $prov['reviewed_eligibility']);
    }

    public function test_label_and_validity_are_derived_from_baseline_not_client_input(): void
    {
        $case = $this->createCase();
        $baseline = $this->seedBaseline($case);
        $admin = $this->psychologist();

        // Sign with baseline that produces DISARANKAN / V1 / UMUM
        $response = $this->actingAs($admin, 'admin')
            ->postJson("/admin/assessment-cases/{$case->public_id}/signing", $this->validPayload($baseline));

        $response->assertStatus(201);
        $snapshotId = $response->json('data.id');

        $row = DB::table('report_signing_snapshots')->where('id', $snapshotId)->sole();
        $snapshotJson = json_decode($row->snapshot_json, true, 512, JSON_THROW_ON_ERROR);

        $prereq = $snapshotJson['prerequisite_input'];

        // All values are derived from the EligibilityDecisionSnapshot baseline
        $this->assertSame('DISARANKAN', $prereq['label'], 'Label must derive from baseline, not client');
        $this->assertSame('V1', $prereq['validity'], 'Validity must derive from baseline, not client');
        $this->assertSame('UMUM', $prereq['target_field'], 'Target field must derive from baseline, not client');

        // unresolved_g7_aspects must be empty (all aspects resolved)
        $this->assertIsArray($prereq['unresolved_g7_aspects']);
        $this->assertEmpty($prereq['unresolved_g7_aspects']);
    }

    public function test_level_override_preserves_system_levels_and_final_levels_in_persisted_snapshot(): void
    {
        $case = $this->createCase();
        $baseline = $this->seedBaseline($case);
        $admin = $this->psychologist();

        $reason = 'Observasi profesional mendukung level akhir tiga untuk aspek ini.';
        $payload = $this->validPayload($baseline);
        $payload['level_overrides'] = [[
            'aspect' => 'C4',
            'system_level' => 5,
            'final_level' => 3,
            'reason' => $reason,
        ]];
        // C4 must have a discrepant G7 resolution matching the override
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
        $snapshotId = $response->json('data.id');

        $row = DB::table('report_signing_snapshots')->where('id', $snapshotId)->sole();
        $snapshotJson = json_decode($row->snapshot_json, true, 512, JSON_THROW_ON_ERROR);

        $reviewed = $snapshotJson['provenance']['reviewed_eligibility'];

        // system_levels and final_levels are stored side by side
        $this->assertArrayHasKey('system_levels', $reviewed);
        $this->assertArrayHasKey('final_levels', $reviewed);

        // C4: system_level stays at baseline, final_level reflects override
        $this->assertSame(5, $reviewed['system_levels']['C4'], 'system_level must remain at baseline');
        $this->assertSame(3, $reviewed['final_levels']['C4'], 'final_level must reflect override');

        // Other aspects: system_level == final_level (no override)
        foreach (self::ASPECTS as $aspect) {
            if ($aspect === 'C4') {
                continue;
            }
            $this->assertSame(
                $reviewed['system_levels'][$aspect],
                $reviewed['final_levels'][$aspect],
                "Aspect {$aspect} should have matching system and final levels"
            );
        }
    }

    public function test_label_override_is_recorded_in_persisted_snapshot(): void
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
        $snapshotId = $response->json('data.id');

        $row = DB::table('report_signing_snapshots')->where('id', $snapshotId)->sole();
        $snapshotJson = json_decode($row->snapshot_json, true, 512, JSON_THROW_ON_ERROR);

        $prereq = $snapshotJson['prerequisite_input'];

        // Label reflects the override target (DIPERTIMBANGKAN)
        $this->assertSame('DIPERTIMBANGKAN', $prereq['label']);

        // Accompaniment conditions are required for DIPERTIMBANGKAN
        $this->assertNotNull($prereq['accompaniment_conditions']);

        // Override is projected in prerequisite_input
        $labelOverrides = array_filter(
            $prereq['overrides'],
            fn (array $o): bool => $o['type'] === 'label',
        );
        $this->assertCount(1, $labelOverrides);

        // Label override provenance is stored in reviewed_eligibility
        $reviewed = $snapshotJson['provenance']['reviewed_eligibility'];
        $this->assertArrayHasKey('label_override', $reviewed);
        $this->assertNotNull($reviewed['label_override']);
        $this->assertTrue($reviewed['label_override']['changed']);
        $this->assertSame($reason, $reviewed['label_override']['reason']);
    }

    public function test_version_chain_is_maintained_across_multiple_signings(): void
    {
        $case = $this->createCase();
        $baseline = $this->seedBaseline($case);
        $admin = $this->psychologist();

        // First signing
        $r1 = $this->actingAs($admin, 'admin')
            ->postJson("/admin/assessment-cases/{$case->public_id}/signing", $this->validPayload($baseline));
        $r1->assertStatus(201);
        $id1 = $r1->json('data.id');

        // Ensure distinct ULID timestamps between signings
        usleep(10000);

        // Second signing
        $r2 = $this->actingAs($admin, 'admin')
            ->postJson("/admin/assessment-cases/{$case->public_id}/signing", $this->validPayload($baseline));
        $r2->assertStatus(201);
        $id2 = $r2->json('data.id');

        $this->assertNotSame($id1, $id2);
        $this->assertSame(1, $r1->json('data.version'));
        $this->assertSame(2, $r2->json('data.version'));

        // DB: version chain is correct — each signing is an independent row
        $row1 = DB::table('report_signing_snapshots')->where('id', $id1)->sole();
        $row2 = DB::table('report_signing_snapshots')->where('id', $id2)->sole();

        $this->assertNull($row1->supersedes_id);
        $this->assertSame($id1, $row2->supersedes_id);
        $this->assertSame(1, (int) $row1->version);
        $this->assertSame(2, (int) $row2->version);

        // Both rows exist independently (id, version, supersedes chain all correct)
        $this->assertSame('SIGNED', $row1->state);
        $this->assertSame('SIGNED', $row2->state);
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
