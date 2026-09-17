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
use Illuminate\Support\Str;
use Tests\TestCase;

final class BilingualNarrativeEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function createCase(): AssessmentCase
    {
        $branch = Branch::query()->create([
            'code' => 'BR-BNT',
            'name' => 'Cabang Narrative Test',
            'ref_code' => 'REF-BNT',
        ]);
        $package = TestPackage::query()->create([
            'code' => 'PKG-BNT',
            'name' => 'Paket Narrative Test',
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
            'full_name' => 'Peserta Narrative',
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
            'name' => 'Admin Narrative Test',
            'email' => Str::uuid().'@example.test',
            'password' => 'password',
            'role' => AdminRole::SuperAdmin,
        ]);
    }

    private function validPayload(): array
    {
        $aspectCodes = ['A1', 'A2', 'B1', 'B2', 'B3', 'B4', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'D1', 'D2', 'D3', 'D4', 'D5'];

        return [
            'aspects' => array_map(
                static fn (string $aspect, int $index): array => [
                    'aspect' => $aspect,
                    'level' => ($index % 5) + 1,
                    'review_required' => false,
                ],
                $aspectCodes,
                array_keys($aspectCodes),
            ),
        ];
    }

    public function test_store_bilingual_narrative_with_valid_case_returns_201(): void
    {
        $case = $this->createCase();
        $admin = $this->admin();

        $response = $this->actingAs($admin, 'admin')
            ->postJson("/admin/assessment-cases/{$case->public_id}/bilingual-narratives", $this->validPayload());

        $response->assertCreated();
        $response->assertJsonStructure([
            'data' => [
                'id', 'assessmentCaseId', 'version', 'eligibilityVersionId',
                'reviewRequired', 'clusters', 'createdAt',
            ],
        ]);
        $response->assertJsonPath('data.version', 1);
        $response->assertJsonPath('data.clusters.A.id', fn (mixed $value): bool => is_string($value) || $value === null);
    }

    public function test_show_bilingual_narrative_returns_latest_version(): void
    {
        $case = $this->createCase();
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->postJson("/admin/assessment-cases/{$case->public_id}/bilingual-narratives", $this->validPayload())
            ->assertCreated();

        $response = $this->actingAs($admin, 'admin')
            ->getJson("/admin/assessment-cases/{$case->public_id}/bilingual-narratives");

        $response->assertOk();
        $response->assertJsonPath('data.version', 1);
    }

    public function test_narrative_endpoint_with_nonexistent_case_returns_404(): void
    {
        $admin = $this->admin();
        $nonexistentId = (string) Str::ulid();

        $this->actingAs($admin, 'admin')
            ->getJson("/admin/assessment-cases/{$nonexistentId}/bilingual-narratives")
            ->assertStatus(404)
            ->assertJson(['error' => ['code' => 'CASE_NOT_FOUND']]);
    }

    public function test_narrative_endpoint_without_auth_redirects_to_login(): void
    {
        $case = $this->createCase();

        $this->get("/admin/assessment-cases/{$case->public_id}/bilingual-narratives")
            ->assertRedirect('/admin/login');

        $this->post("/admin/assessment-cases/{$case->public_id}/bilingual-narratives", [])
            ->assertRedirect('/admin/login');
    }
}
