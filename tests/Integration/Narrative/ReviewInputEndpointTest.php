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
use RuntimeException;
use Tests\TestCase;

final class ReviewInputEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function createCase(): AssessmentCase
    {
        $branch = Branch::query()->create([
            'code' => 'BR-RI',
            'name' => 'Cabang Review Input Test',
            'ref_code' => 'REF-RI',
        ]);
        $package = TestPackage::query()->create([
            'code' => 'PKG-RI',
            'name' => 'Paket Review Input Test',
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
            'full_name' => 'Peserta Review',
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
            'name' => 'Admin Review Input Test',
            'email' => Str::uuid().'@example.test',
            'password' => 'password',
            'role' => AdminRole::SuperAdmin,
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
        return [
            'aspects' => array_map(
                static fn (string $aspect, int $index): array => [
                    'aspect' => $aspect,
                    'level' => ($index % 5) + 1,
                    'review_required' => false,
                ],
                $this->aspectCodes(),
                array_keys($this->aspectCodes()),
            ),
        ];
    }

    public function test_review_input_with_no_data_returns_404(): void
    {
        $case = $this->createCase();
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->getJson("/admin/assessment-cases/{$case->public_id}/review-input")
            ->assertNotFound();
    }

    public function test_review_input_returns_bundled_response(): void
    {
        $case = $this->createCase();
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->postJson("/admin/assessment-cases/{$case->public_id}/eligibility-decisions", $this->eligibilityPayload())
            ->assertCreated();

        $this->actingAs($admin, 'admin')
            ->postJson("/admin/assessment-cases/{$case->public_id}/bilingual-narratives", $this->narrativePayload())
            ->assertCreated();

        $response = $this->actingAs($admin, 'admin')
            ->getJson("/admin/assessment-cases/{$case->public_id}/review-input");

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => ['eligibility', 'narrative'],
        ]);
        $this->assertNotNull($response->json('data.eligibility'));
        $this->assertNotNull($response->json('data.narrative'));
    }

    public function test_review_input_with_nonexistent_case_returns_404(): void
    {
        $admin = $this->admin();
        $nonexistentId = (string) Str::ulid();

        $this->actingAs($admin, 'admin')
            ->getJson("/admin/assessment-cases/{$nonexistentId}/review-input")
            ->assertStatus(404)
            ->assertJson(['error' => ['code' => 'NOT_FOUND']]);
    }

    public function test_review_input_without_auth_redirects_to_login(): void
    {
        $case = $this->createCase();

        $this->get("/admin/assessment-cases/{$case->public_id}/review-input")
            ->assertRedirect('/admin/login');
    }
}
