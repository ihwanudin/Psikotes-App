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
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class ReviewAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers ───────────────────────────────────────────────────────────────

    private function createCase(?Branch $branch = null, string $suffix = ''): AssessmentCase
    {
        $branch ??= $this->branch($suffix ?: 'DEF');

        $package = TestPackage::query()->create([
            'code' => 'PKG-RA'.$suffix,
            'name' => 'Paket Auth Test '.$suffix,
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
            'full_name' => 'Peserta Auth Test '.$suffix,
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

    private function branch(string $suffix): Branch
    {
        return Branch::query()->create([
            'code' => 'BR-RA-'.$suffix,
            'name' => 'Branch Auth Test '.$suffix,
            'ref_code' => 'REF-RA-'.$suffix,
        ]);
    }

    private function admin(AdminRole $role, ?Branch $branch = null): Admin
    {
        return Admin::query()->create([
            'branch_id' => $branch?->id,
            'name' => "Admin {$role->value}",
            'email' => $role->value.'-'.($branch?->id ?? 'central').'-'.Str::uuid().'@auth.test',
            'password' => 'not-a-real-password',
            'role' => $role,
        ]);
    }

    /** @return list<string> */
    private function aspectCodes(): array
    {
        return ['A1', 'A2', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'D1', 'D2', 'D3', 'D4', 'D5'];
    }

    /** @return array{standard_version: string, base_standards: array<mixed>, fields: array<mixed>} */
    private function canonicalReporting(): array
    {
        $contents = file_get_contents(dirname(__DIR__, 3).'/database/seeders/data/reporting.json');
        if (! is_string($contents)) {
            throw new RuntimeException('Canonical reporting data could not be read.');
        }

        $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data)
            || ! is_string($data['standard_version'] ?? null)
            || ! is_array($data['base_standards'] ?? null)
            || ! is_array($data['fields'] ?? null)) {
            throw new RuntimeException('Canonical reporting data is incomplete.');
        }

        return [
            'standard_version' => $data['standard_version'],
            'base_standards' => $data['base_standards'],
            'fields' => $data['fields'],
        ];
    }

    private function eligibilityPayload(): array
    {
        $reporting = $this->canonicalReporting();

        return [
            'levels' => array_fill_keys($this->aspectCodes(), 5),
            'field_code' => 'UMUM',
            'iq' => 100,
            'validity' => 'V1',
            'standard_configuration' => $reporting,
            'eligibility_source_versions' => [
                'ist' => 'F0-2026.08',
                'papi' => 'F0-2026.08',
                'kraepelin' => 'F0-2026.08',
                'rmib' => 'F0-2026.08',
                'reporting' => $reporting['standard_version'],
            ],
        ];
    }

    private function narrativePayload(): array
    {
        $aspects = [];
        foreach ($this->aspectCodes() as $i => $aspect) {
            $aspects[] = [
                'aspect' => $aspect,
                'level' => ($i % 5) + 1,
                'review_required' => false,
            ];
        }

        return ['aspects' => $aspects];
    }

    /**
     * Seed eligibility + bilingual narrative for a case (as super_admin),
     * returning the narrative version ID for use as baseline_version_id.
     */
    private function seedBaseline(AssessmentCase $case): string
    {
        $superAdmin = $this->admin(AdminRole::SuperAdmin);

        $this->actingAs($superAdmin, 'admin')
            ->postJson("/admin/assessment-cases/{$case->public_id}/eligibility-decisions", $this->eligibilityPayload())
            ->assertCreated();

        $response = $this->actingAs($superAdmin, 'admin')
            ->postJson("/admin/assessment-cases/{$case->public_id}/bilingual-narratives", $this->narrativePayload())
            ->assertCreated();

        /** @var string */
        return $response->json('data.id');
    }

    private function assertNoRowsInReviewTables(): void
    {
        $this->assertSame(0, DB::table('eligibility_decision_versions')->count(), 'eligibility_decision_versions should be empty');
        $this->assertSame(0, DB::table('bilingual_narrative_versions')->count(), 'bilingual_narrative_versions should be empty');
        $this->assertSame(0, DB::table('narrative_cluster_edits')->count(), 'narrative_cluster_edits should be empty');
    }

    // ─── EligibilityDecisionController ─────────────────────────────────────────

    /** @return iterable<string, array{0: AdminRole}> */
    public static function authorizedRoles(): iterable
    {
        yield 'psychologist' => [AdminRole::Psychologist];
        yield 'super_admin' => [AdminRole::SuperAdmin];
    }

    /** @return iterable<string, array{0: AdminRole}> */
    public static function unauthorizedRoles(): iterable
    {
        yield 'branch_admin' => [AdminRole::BranchAdmin];
        yield 'staff' => [AdminRole::Staff];
    }

    /** @return iterable<string, array{0: AdminRole}> */
    public static function allRoles(): iterable
    {
        yield from self::authorizedRoles();
        yield from self::unauthorizedRoles();
    }

    #[DataProvider('authorizedRoles')]
    public function test_eligibility_store_returns_201_for_authorized_roles(AdminRole $role): void
    {
        $case = $this->createCase();
        $admin = $this->admin($role);

        $response = $this->actingAs($admin, 'admin')
            ->postJson("/admin/assessment-cases/{$case->public_id}/eligibility-decisions", $this->eligibilityPayload());

        $response->assertCreated();
        $response->assertJsonPath('data.version', 1);
    }

    #[DataProvider('unauthorizedRoles')]
    public function test_eligibility_store_returns_404_for_unauthorized_roles(AdminRole $role): void
    {
        $branch = $this->branch('X');
        $case = $this->createCase($branch);
        $admin = $this->admin($role, $branch);

        $this->actingAs($admin, 'admin')
            ->postJson("/admin/assessment-cases/{$case->public_id}/eligibility-decisions", $this->eligibilityPayload())
            ->assertStatus(404);

        $this->assertNoRowsInReviewTables();
    }

    #[DataProvider('authorizedRoles')]
    public function test_eligibility_show_returns_200_for_authorized_roles(AdminRole $role): void
    {
        $case = $this->createCase();
        $admin = $this->admin($role);

        // Seed via super_admin first
        $this->seedBaseline($case);

        $response = $this->actingAs($admin, 'admin')
            ->getJson("/admin/assessment-cases/{$case->public_id}/eligibility-decisions");

        $response->assertOk();
        $response->assertJsonPath('data.version', 1);
    }

    #[DataProvider('unauthorizedRoles')]
    public function test_eligibility_show_returns_404_for_unauthorized_roles(AdminRole $role): void
    {
        $branch = $this->branch('X');
        $case = $this->createCase($branch);
        $admin = $this->admin($role, $branch);

        // Seed via super_admin first
        $this->seedBaseline($case);

        $this->actingAs($admin, 'admin')
            ->getJson("/admin/assessment-cases/{$case->public_id}/eligibility-decisions")
            ->assertStatus(404);
    }

    // ─── BilingualNarrativeController ───────────────────────────────────────────

    #[DataProvider('authorizedRoles')]
    public function test_bilingual_narrative_store_returns_201_for_authorized_roles(AdminRole $role): void
    {
        $case = $this->createCase();
        $admin = $this->admin($role);

        $response = $this->actingAs($admin, 'admin')
            ->postJson("/admin/assessment-cases/{$case->public_id}/bilingual-narratives", $this->narrativePayload());

        $response->assertCreated();
        $response->assertJsonPath('data.version', 1);
    }

    #[DataProvider('unauthorizedRoles')]
    public function test_bilingual_narrative_store_returns_404_for_unauthorized_roles(AdminRole $role): void
    {
        $branch = $this->branch('X');
        $case = $this->createCase($branch);
        $admin = $this->admin($role, $branch);

        $this->actingAs($admin, 'admin')
            ->postJson("/admin/assessment-cases/{$case->public_id}/bilingual-narratives", $this->narrativePayload())
            ->assertStatus(404);

        $this->assertNoRowsInReviewTables();
    }

    #[DataProvider('authorizedRoles')]
    public function test_bilingual_narrative_show_returns_200_for_authorized_roles(AdminRole $role): void
    {
        $case = $this->createCase();
        $admin = $this->admin($role);

        // Seed via super_admin first
        $this->seedBaseline($case);

        $response = $this->actingAs($admin, 'admin')
            ->getJson("/admin/assessment-cases/{$case->public_id}/bilingual-narratives");

        $response->assertOk();
        $response->assertJsonPath('data.version', 1);
    }

    #[DataProvider('unauthorizedRoles')]
    public function test_bilingual_narrative_show_returns_404_for_unauthorized_roles(AdminRole $role): void
    {
        $branch = $this->branch('X');
        $case = $this->createCase($branch);
        $admin = $this->admin($role, $branch);

        // Seed via super_admin first
        $this->seedBaseline($case);

        $this->actingAs($admin, 'admin')
            ->getJson("/admin/assessment-cases/{$case->public_id}/bilingual-narratives")
            ->assertStatus(404);
    }

    // ─── NarrativeClusterEditController ─────────────────────────────────────────

    #[DataProvider('authorizedRoles')]
    public function test_narrative_cluster_edit_show_returns_200_for_authorized_roles(AdminRole $role): void
    {
        $case = $this->createCase();
        $admin = $this->admin($role);

        // Seed baseline via super_admin
        $this->seedBaseline($case);

        $response = $this->actingAs($admin, 'admin')
            ->getJson("/admin/assessment-cases/{$case->public_id}/narrative-cluster-edits");

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['clusters', 'baselineVersionId']]);
    }

    #[DataProvider('unauthorizedRoles')]
    public function test_narrative_cluster_edit_show_returns_404_for_unauthorized_roles(AdminRole $role): void
    {
        $branch = $this->branch('X');
        $case = $this->createCase($branch);
        $admin = $this->admin($role, $branch);

        // Seed baseline via super_admin
        $this->seedBaseline($case);

        $this->actingAs($admin, 'admin')
            ->getJson("/admin/assessment-cases/{$case->public_id}/narrative-cluster-edits")
            ->assertStatus(404);
    }

    #[DataProvider('authorizedRoles')]
    public function test_narrative_cluster_edit_update_returns_200_for_authorized_roles(AdminRole $role): void
    {
        $case = $this->createCase();
        $baselineId = $this->seedBaseline($case);
        $admin = $this->admin($role);

        $response = $this->actingAs($admin, 'admin')
            ->putJson("/admin/assessment-cases/{$case->public_id}/narrative-cluster-edits/A", [
                'edited_text' => 'Edited cluster A text',
                'baseline_version_id' => $baselineId,
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.cluster', 'A');
        $response->assertJsonPath('data.editedText', 'Edited cluster A text');
    }

    #[DataProvider('unauthorizedRoles')]
    public function test_narrative_cluster_edit_update_returns_404_for_unauthorized_roles(AdminRole $role): void
    {
        $branch = $this->branch('X');
        $case = $this->createCase($branch);
        $baselineId = $this->seedBaseline($case);
        $admin = $this->admin($role, $branch);

        $this->actingAs($admin, 'admin')
            ->putJson("/admin/assessment-cases/{$case->public_id}/narrative-cluster-edits/A", [
                'edited_text' => 'Should not be saved',
                'baseline_version_id' => $baselineId,
            ])
            ->assertStatus(404);

        // Verify no cluster edits were created by the unauthorized user
        $this->assertSame(0, DB::table('narrative_cluster_edits')->count(), 'narrative_cluster_edits should be empty');
    }

    // ─── ReviewInputController ──────────────────────────────────────────────────

    #[DataProvider('authorizedRoles')]
    public function test_review_input_returns_200_for_authorized_roles(AdminRole $role): void
    {
        $case = $this->createCase();
        $admin = $this->admin($role);

        // Seed baseline via super_admin
        $this->seedBaseline($case);

        $response = $this->actingAs($admin, 'admin')
            ->getJson("/admin/assessment-cases/{$case->public_id}/review-input");

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['eligibility', 'narrative']]);
    }

    #[DataProvider('unauthorizedRoles')]
    public function test_review_input_returns_404_for_unauthorized_roles(AdminRole $role): void
    {
        $branch = $this->branch('X');
        $case = $this->createCase($branch);
        $admin = $this->admin($role, $branch);

        // Seed baseline via super_admin
        $this->seedBaseline($case);

        $this->actingAs($admin, 'admin')
            ->getJson("/admin/assessment-cases/{$case->public_id}/review-input")
            ->assertStatus(404);
    }

    // ─── Cross-branch: psychologist & super_admin are lintas cabang ─────────────

    #[DataProvider('authorizedRoles')]
    public function test_authorized_roles_can_access_case_in_any_branch(AdminRole $role): void
    {
        $branchA = $this->branch('A');
        $branchB = $this->branch('B');
        $caseInB = $this->createCase($branchB, 'B');

        // Admin is in branch A (or central), case is in branch B
        $admin = $role === AdminRole::SuperAdmin
            ? $this->admin($role)
            : $this->admin($role);

        // Seed baseline via super_admin
        $this->seedBaseline($caseInB);

        // All read endpoints should succeed
        $this->actingAs($admin, 'admin')
            ->getJson("/admin/assessment-cases/{$caseInB->public_id}/eligibility-decisions")
            ->assertOk();

        $this->actingAs($admin, 'admin')
            ->getJson("/admin/assessment-cases/{$caseInB->public_id}/bilingual-narratives")
            ->assertOk();

        $this->actingAs($admin, 'admin')
            ->getJson("/admin/assessment-cases/{$caseInB->public_id}/narrative-cluster-edits")
            ->assertOk();

        $this->actingAs($admin, 'admin')
            ->getJson("/admin/assessment-cases/{$caseInB->public_id}/review-input")
            ->assertOk();
    }

    // ─── Guest / unauthenticated ────────────────────────────────────────────────

    public function test_all_routes_redirect_unauthenticated_users(): void
    {
        $case = $this->createCase();

        $this->get("/admin/assessment-cases/{$case->public_id}/eligibility-decisions")
            ->assertRedirect('/admin/login');

        $this->post("/admin/assessment-cases/{$case->public_id}/eligibility-decisions", [])
            ->assertRedirect('/admin/login');

        $this->get("/admin/assessment-cases/{$case->public_id}/bilingual-narratives")
            ->assertRedirect('/admin/login');

        $this->post("/admin/assessment-cases/{$case->public_id}/bilingual-narratives", [])
            ->assertRedirect('/admin/login');

        $this->get("/admin/assessment-cases/{$case->public_id}/narrative-cluster-edits")
            ->assertRedirect('/admin/login');

        $this->put("/admin/assessment-cases/{$case->public_id}/narrative-cluster-edits/A", [])
            ->assertRedirect('/admin/login');

        $this->get("/admin/assessment-cases/{$case->public_id}/review-input")
            ->assertRedirect('/admin/login');
    }

    // ─── Nonexistent case ───────────────────────────────────────────────────────

    #[DataProvider('authorizedRoles')]
    public function test_nonexistent_case_returns_404_for_authorized_roles(AdminRole $role): void
    {
        $admin = $this->admin($role);
        $nonexistentId = (string) Str::ulid();

        $this->actingAs($admin, 'admin')
            ->getJson("/admin/assessment-cases/{$nonexistentId}/eligibility-decisions")
            ->assertStatus(404);

        $this->actingAs($admin, 'admin')
            ->getJson("/admin/assessment-cases/{$nonexistentId}/bilingual-narratives")
            ->assertStatus(404);

        $this->actingAs($admin, 'admin')
            ->getJson("/admin/assessment-cases/{$nonexistentId}/narrative-cluster-edits")
            ->assertStatus(404);

        $this->actingAs($admin, 'admin')
            ->getJson("/admin/assessment-cases/{$nonexistentId}/review-input")
            ->assertStatus(404);
    }
}
