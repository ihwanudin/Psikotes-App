<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Filament\Resources\IntegrationClients\Pages\CreateIntegrationClient;
use App\Filament\Resources\IntegrationSources\Pages\CreateIntegrationSource;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\IntegrationClient;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class IntegrationRegistryManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_super_admin_can_register_client_without_storing_a_secret(): void
    {
        $organization = $this->organization();
        $this->actingAs($this->admin(AdminRole::SuperAdmin), 'admin');

        Livewire::test(CreateIntegrationClient::class)
            ->fillForm([
                'organization_id' => $organization->id,
                'client_id' => 'partner-lpk-01',
                'credential_reference' => 'partner_lpk_01',
                'callback_base_url' => 'https://partner.example.test',
                'result_delivery_mode' => 'CALLBACK_AND_POLL',
                'rate_limit_policy' => ['requestsPerMinute' => 90],
                'enabled' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertDatabaseHas('integration_clients', [
            'organization_id' => $organization->id,
            'client_id' => 'partner-lpk-01',
            'credential_reference' => 'partner_lpk_01',
            'enabled' => false,
        ]);
        $this->assertDatabaseMissing('integration_clients', ['credential_reference' => 'a-real-secret-value']);
    }

    public function test_callback_base_url_rejects_private_or_unsafe_destinations(): void
    {
        $this->actingAs($this->admin(AdminRole::SuperAdmin), 'admin');

        foreach (['http://partner.example.test', 'https://127.0.0.1', 'https://localhost/callback', 'https://user:pass@partner.example.test'] as $url) {
            Livewire::test(CreateIntegrationClient::class)
                ->fillForm([
                    'organization_id' => $this->organization()->id,
                    'client_id' => 'unsafe-'.md5($url),
                    'credential_reference' => 'unsafe_reference',
                    'callback_base_url' => $url,
                    'result_delivery_mode' => 'CALLBACK',
                    'rate_limit_policy' => ['requestsPerMinute' => 90],
                ])
                ->call('create')
                ->assertHasFormErrors(['callback_base_url']);
        }
    }

    public function test_super_admin_can_register_a_versioned_source_with_relative_callback_path(): void
    {
        $this->actingAs($this->admin(AdminRole::SuperAdmin), 'admin');
        $client = $this->client();

        Livewire::test(CreateIntegrationSource::class)
            ->fillForm([
                'integration_client_id' => $client->id,
                'source_system' => 'LPK_PARTNER',
                'contract_version' => 'v1',
                'authentication_mode' => 'HMAC_SHA256',
                'allowed_assessment_packages' => ['WORK_V1'],
                'allowed_funding_modes' => ['INVOICED_TO_ORGANIZATION'],
                'participant_provisioning_mode' => 'API',
                'commercial_mode' => 'CONTRACT',
                'callback_path' => '/api/psychotest/events',
                'status' => 'DRAFT',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertDatabaseHas('integration_sources', [
            'integration_client_id' => $client->id,
            'source_system' => 'LPK_PARTNER',
            'contract_version' => 'v1',
            'callback_path' => '/api/psychotest/events',
            'status' => 'DRAFT',
        ]);
    }

    #[DataProvider('nonSuperAdminRoles')]
    public function test_non_super_admin_cannot_access_registry(AdminRole $role): void
    {
        $branch = in_array($role, [AdminRole::BranchAdmin, AdminRole::Staff], true) ? $this->organization() : null;
        $this->actingAs($this->admin($role, $branch), 'admin');

        $this->get('/admin/integration-clients')->assertForbidden();
        $this->get('/admin/integration-sources')->assertForbidden();
    }

    private function organization(): Branch
    {
        return Branch::query()->create([
            'code' => 'ORG-'.str()->random(8),
            'name' => 'Organisasi Mitra',
            'ref_code' => 'REF-'.str()->random(8),
            'organization_code' => 'ORG-'.str()->random(8),
            'organization_type' => 'EXTERNAL_LPK',
            'display_name' => 'Organisasi Mitra',
            'status' => 'ACTIVE',
        ]);
    }

    private function client(): IntegrationClient
    {
        return IntegrationClient::query()->create([
            'organization_id' => $this->organization()->id,
            'client_id' => 'client-'.str()->random(8),
            'credential_reference' => 'client_reference',
            'result_delivery_mode' => 'PORTAL_ONLY',
            'enabled' => false,
        ]);
    }

    private function admin(AdminRole $role, ?Branch $branch = null): Admin
    {
        return Admin::query()->create([
            'branch_id' => $branch?->id,
            'name' => 'Registry Admin',
            'email' => str()->random(8).'@example.test',
            'password' => 'not-a-real-password',
            'role' => $role,
            'has_email_authentication' => true,
        ]);
    }

    /** @return iterable<string, array{AdminRole}> */
    public static function nonSuperAdminRoles(): iterable
    {
        yield 'branch admin' => [AdminRole::BranchAdmin];
        yield 'staff' => [AdminRole::Staff];
        yield 'psychologist' => [AdminRole::Psychologist];
    }
}
