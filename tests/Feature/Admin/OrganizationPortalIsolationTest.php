<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Filament\Resources\AssessmentParticipants\AssessmentParticipantResource;
use App\Models\Admin;
use App\Models\AssessmentParticipant;
use App\Models\Branch;
use App\Models\IntegrationClient;
use App\Models\Participant;
use App\Models\TestPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\AssessmentBillingFixture;
use Tests\TestCase;

final class OrganizationPortalIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_portal_only_lpk_admin_sees_only_its_organizations_safe_assessment_list(): void
    {
        $package = TestPackage::query()->create([
            'code' => 'PORTAL_V1', 'name' => 'Portal Package', 'amount' => 100_000,
            'currency' => 'IDR', 'is_active' => true,
        ]);
        $package->items()->create(['test_type' => 'ist']);
        [$organizationA, $mappingA] = $this->organizationWithAssessment('A', $package);
        [, $mappingB] = $this->organizationWithAssessment('B', $package);
        $admin = Admin::query()->create([
            'branch_id' => $organizationA->id,
            'name' => 'Admin LPK A',
            'email' => 'admin-a@example.test',
            'password' => 'password',
            'role' => AdminRole::BranchAdmin,
        ]);

        $this->actingAs($admin, 'admin');

        $ids = AssessmentParticipantResource::getEloquentQuery()->pluck('id')->all();
        $this->assertSame([$mappingA->id], $ids);
        $this->assertNotContains($mappingB->id, $ids);
        $this->assertTrue(AssessmentParticipantResource::canViewAny());
    }

    /** @return array{Branch, AssessmentParticipant} */
    private function organizationWithAssessment(string $suffix, TestPackage $package): array
    {
        $organization = Branch::query()->create([
            'code' => 'LPK'.$suffix, 'name' => 'LPK '.$suffix, 'ref_code' => 'LPK-'.$suffix,
            'organization_code' => 'LPK_'.$suffix, 'organization_type' => 'EXTERNAL_LPK',
            'display_name' => 'LPK '.$suffix, 'status' => 'ACTIVE',
            'allowed_funding_modes' => ['SPONSORED'], 'is_default' => $suffix === 'A', 'is_active' => true,
        ]);
        $client = IntegrationClient::query()->create([
            'organization_id' => $organization->id,
            'client_id' => 'PORTAL_'.$suffix,
            'credential_reference' => 'unused-'.$suffix,
            'result_delivery_mode' => 'PORTAL_ONLY',
            'enabled' => true,
        ]);
        $participant = Participant::query()->create([
            'branch_id' => $organization->id, 'referral_branch_id' => $organization->id,
            'referral_source' => 'manual', 'package_id' => $package->id,
            'full_name' => 'Participant '.$suffix, 'gender' => 'male', 'birth_date' => '2000-01-01',
            'education_level' => 'SMA', 'intended_field' => 'UMUM',
            'phone' => '62811111111'.$suffix, 'test_number' => 'TEST-'.$suffix,
        ]);
        $attemptPublicId = (string) Str::ulid();
        $case = AssessmentBillingFixture::createExactIntegratedCase(
            $participant->id, $organization->id, $package->id, $attemptPublicId,
        );
        $mapping = AssessmentParticipant::query()->create([
            'integration_client_id' => $client->id,
            'organization_id' => $organization->id,
            'participant_id' => $participant->id,
            'package_id' => $package->id,
            'assessment_case_id' => $case,
            'assessment_attempt_id' => $attemptPublicId,
            'source_system' => 'PORTAL_ONLY',
            'external_candidate_id' => 'CAND-'.$suffix,
            'funding_mode' => 'SPONSORED',
            'assessment_status' => 'READY',
            'result_version' => 0,
            'idempotency_key' => 'portal-'.$suffix,
            'request_hash' => str_repeat(strtolower($suffix), 64),
            'logical_assessment_key' => hash('sha256', 'portal-'.$suffix),
        ]);

        return [$organization, $mapping];
    }
}
