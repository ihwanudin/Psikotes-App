<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Actions\Integrations\IssueAssessmentInvitation;
use App\Enums\AdminRole;
use App\Filament\Resources\AssessmentParticipants\Pages\ListAssessmentParticipants;
use App\Models\Admin;
use App\Models\AssessmentParticipant;
use App\Models\Branch;
use App\Models\IntegrationClient;
use App\Models\Participant;
use App\Models\TestPackage;
use App\Services\ParticipantAuth\ParticipantJwt;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class OrganizationInvitationTest extends TestCase
{
    use RefreshDatabase;

    private Branch $organizationA;

    private AssessmentParticipant $assessmentA;

    private AssessmentParticipant $assessmentB;

    private Admin $adminA;

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-08-29 12:00:00+07:00');
        config()->set('participant_auth.jwt.secret', 'base64:'.base64_encode(str_repeat('I', 32)));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();

        $package = TestPackage::query()->create([
            'code' => 'INVITE_V1', 'name' => 'Invitation Package', 'amount' => 100_000,
            'currency' => 'IDR', 'is_active' => true,
        ]);
        $package->items()->create(['test_type' => 'ist']);
        [$this->organizationA, $this->assessmentA] = $this->assessment('A', $package);
        [, $this->assessmentB] = $this->assessment('B', $package);
        $this->adminA = Admin::query()->create([
            'branch_id' => $this->organizationA->id,
            'name' => 'Admin Organization A',
            'email' => 'admin-a@example.test',
            'password' => 'password',
            'role' => AdminRole::BranchAdmin,
        ]);
    }

    public function test_organization_admin_can_issue_and_consume_a_one_time_invitation(): void
    {
        $issued = app(IssueAssessmentInvitation::class)->handle($this->adminA, $this->assessmentA);

        $this->assertStringContainsString('/assessment/invitations/', $issued->url);
        $this->assertStringNotContainsString($issued->token, json_encode(
            $this->getConnection()->table('assessment_invitations')->first(),
            JSON_THROW_ON_ERROR,
        ));
        $this->assertDatabaseHas('assessment_invitations', [
            'assessment_participant_id' => $this->assessmentA->id,
            'status' => 'PENDING',
            'issue_number' => 1,
        ]);

        $invitationUrl = strtok($issued->url, '#');
        $this->assertIsString($invitationUrl);
        $response = $this->get($invitationUrl)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertDontSee($issued->token);

        $consumeUrl = $invitationUrl.'/consume';
        $participantToken = $this->postJson($consumeUrl, ['token' => $issued->token])
            ->assertOk()
            ->assertJsonStructure(['participantToken'])
            ->json('participantToken');
        $this->assertIsString($participantToken);
        $principal = app(ParticipantJwt::class)->verify($participantToken);
        $this->assertSame($this->assessmentA->participant_id, $principal->participantId);
        $this->assertDatabaseHas('assessment_invitations', [
            'assessment_participant_id' => $this->assessmentA->id,
            'status' => 'CONSUMED',
            'active_marker' => null,
        ]);

        $this->postJson($consumeUrl, ['token' => $issued->token])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Tautan psikotes sudah digunakan atau tidak lagi berlaku.');
    }

    public function test_retry_revokes_the_old_link_and_creates_one_new_active_invitation(): void
    {
        $first = app(IssueAssessmentInvitation::class)->handle($this->adminA, $this->assessmentA);
        $second = app(IssueAssessmentInvitation::class)->handle($this->adminA, $this->assessmentA);

        $this->assertNotSame($first->url, $second->url);
        $this->assertDatabaseHas('assessment_invitations', ['issue_number' => 1, 'status' => 'REVOKED', 'active_marker' => null]);
        $this->assertDatabaseHas('assessment_invitations', ['issue_number' => 2, 'status' => 'PENDING', 'active_marker' => 1]);
        $this->assertSame(1, $this->getConnection()->table('assessment_invitations')->where('active_marker', true)->count());

        $firstUrl = strtok($first->url, '#');
        $secondUrl = strtok($second->url, '#');
        $this->assertIsString($firstUrl);
        $this->assertIsString($secondUrl);
        $this->postJson($firstUrl.'/consume', ['token' => $first->token])->assertStatus(409);
        $this->postJson($secondUrl.'/consume', ['token' => $second->token])->assertOk();
    }

    public function test_admin_cannot_issue_an_invitation_for_another_organization(): void
    {
        $this->expectException(AuthorizationException::class);

        app(IssueAssessmentInvitation::class)->handle($this->adminA, $this->assessmentB);
    }

    public function test_safe_export_is_tenant_scoped_and_excludes_sensitive_fields(): void
    {
        $response = $this->actingAs($this->adminA, 'admin')
            ->get('/admin/assessment-participants/export.csv?status=READY')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();
        $this->assertStringContainsString('CAND-A', $csv);
        $this->assertStringNotContainsString('CAND-B', $csv);
        foreach (['private-a@example.test', '628111111110', 'full_name', 'email', 'phone', 'raw_answer', 'object_key', 'signed_url'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, strtolower($csv));
        }
    }

    public function test_portal_exposes_the_invitation_action_for_an_active_owned_assessment(): void
    {
        $this->actingAs($this->adminA, 'admin');

        Livewire::test(ListAssessmentParticipants::class)
            ->assertCanSeeTableRecords([$this->assessmentA])
            ->assertCanNotSeeTableRecords([$this->assessmentB])
            ->assertActionVisible(TestAction::make('invitation')->table($this->assessmentA))
            ->callAction(TestAction::make('invitation')->table($this->assessmentA))
            ->assertNotified();

        $this->assertDatabaseHas('assessment_invitations', [
            'assessment_participant_id' => $this->assessmentA->id,
            'status' => 'PENDING',
        ]);
    }

    /** @return array{Branch, AssessmentParticipant} */
    private function assessment(string $suffix, TestPackage $package): array
    {
        $organization = Branch::query()->create([
            'code' => 'ORG-'.$suffix, 'name' => 'Organization '.$suffix, 'ref_code' => 'ORG-'.$suffix,
            'organization_code' => 'ORGANIZATION_'.$suffix, 'organization_type' => 'EXTERNAL_LPK',
            'display_name' => 'Organization '.$suffix, 'status' => 'ACTIVE',
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
            'source_system' => 'PORTAL_ONLY', 'attribution_source' => $organization->ref_code,
            'full_name' => 'Private '.$suffix, 'gender' => 'female', 'birth_date' => '2000-01-01',
            'education_level' => 'SMA', 'intended_field' => 'UMUM',
            'phone' => '62811111111'.$suffix, 'email' => 'private-'.strtolower($suffix).'@example.test',
            'test_number' => 'TEST-'.$suffix,
        ]);
        $mapping = AssessmentParticipant::query()->create([
            'integration_client_id' => $client->id,
            'organization_id' => $organization->id,
            'participant_id' => $participant->id,
            'package_id' => $package->id,
            'assessment_attempt_id' => (string) Str::ulid(),
            'source_system' => 'PORTAL_ONLY',
            'external_candidate_id' => 'CAND-'.$suffix,
            'external_process_id' => 'PROCESS-'.$suffix,
            'assessment_round_id' => 'ROUND-2026-08',
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
