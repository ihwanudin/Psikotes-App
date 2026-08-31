<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Filament\Resources\IntegrationClients\Pages\ListIntegrationClients;
use App\Filament\Resources\IntegrationSources\Pages\EditIntegrationSource;
use App\Filament\Resources\IntegrationSources\Pages\ListIntegrationSources;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\IntegrationClient;
use App\Models\IntegrationSource;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\OrganizationPaymentTestCase;

final class FundingPolicyPanelTest extends OrganizationPaymentTestCase
{
    use RefreshDatabase;

    private Branch $organization;

    private IntegrationClient $client;

    private IntegrationSource $source;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        $this->organization = Branch::create([
            'code' => 'PANEL', 'ref_code' => 'PANEL', 'name' => 'Lembaga Uji',
            'organization_code' => 'PANEL', 'display_name' => 'Lembaga Uji',
            'allowed_payer_types' => ['self'],
        ])->refresh();
        $this->client = IntegrationClient::create([
            'organization_id' => $this->organization->id, 'client_id' => 'panel-client',
            'credential_reference' => 'synthetic-only', 'rate_limit_policy' => ['requestsPerMinute' => 90],
        ])->refresh();
        $this->source = IntegrationSource::create([
            'integration_client_id' => $this->client->id, 'source_system' => 'PANEL_SOURCE',
            'allowed_assessment_packages' => ['WORK_V1'], 'allowed_funding_modes' => ['SPONSORED'],
            'allowed_payer_types' => ['self'],
        ])->refresh();
        $this->admin = Admin::create([
            'name' => 'Admin Uji', 'email' => 'panel@example.test',
            'password' => 'synthetic-test-password', 'role' => AdminRole::SuperAdmin,
        ]);
        $this->actingAs($this->admin, 'admin');
    }

    public function test_organization_form_saves_via_audited_action(): void
    {
        Livewire::test(ListIntegrationClients::class)
            ->assertActionVisible(TestAction::make('fundingPolicy')->table($this->client))
            ->callAction(TestAction::make('fundingPolicy')->table($this->client), data: [
                'allowed_payer_types' => ['self', 'organization'],
            ])
            ->assertHasNoFormErrors()->assertNotified();
        $this->assertSame(['self', 'organization'], $this->organization->refresh()->allowed_payer_types);
        $this->assertSame(['self'], $this->source->refresh()->allowed_payer_types);
        $this->assertDatabaseHas('audit_logs', ['action' => 'funding_policy.updated', 'subject_type' => Branch::class]);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_source_form_saves_lock_and_can_disable_all_choices(): void
    {
        Livewire::test(ListIntegrationSources::class)
            ->callAction(TestAction::make('fundingPolicy')->table($this->source), data: [
                'allowed_payer_types' => ['organization'], 'locked_payer_type' => 'organization',
            ])->assertHasNoFormErrors()->assertNotified();
        $this->assertSame('organization', $this->source->refresh()->locked_payer_type);
        Livewire::test(ListIntegrationSources::class)
            ->callAction(TestAction::make('fundingPolicy')->table($this->source), data: [
                'allowed_payer_types' => [], 'locked_payer_type' => null,
            ])->assertHasNoFormErrors()->assertNotified();
        $this->assertSame([], $this->source->refresh()->allowed_payer_types);
        $this->assertNull($this->source->locked_payer_type);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_source_edit_page_has_the_same_policy_action(): void
    {
        Livewire::test(EditIntegrationSource::class, ['record' => $this->source->id])
            ->assertActionVisible('fundingPolicy')
            ->callAction('fundingPolicy', data: ['allowed_payer_types' => ['self'], 'locked_payer_type' => 'self'])
            ->assertHasNoFormErrors()->assertNotified();
        $this->assertSame('self', $this->source->refresh()->locked_payer_type);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_lock_outside_allowed_list_shows_inline_error_without_mutation(): void
    {
        $component = Livewire::test(ListIntegrationSources::class)
            ->callAction(TestAction::make('fundingPolicy')->table($this->source), data: [
                'allowed_payer_types' => ['self'], 'locked_payer_type' => 'organization',
            ])->assertHasFormErrors(['locked_payer_type']);
        $this->assertSame(
            'Pembayar terkunci harus diizinkan. Aktifkan kembali pilihannya, lalu hapus kunci sebelum mematikannya.',
            $component->instance()->getErrorBag()->first('mountedActions.0.data.locked_payer_type'),
        );
        $this->assertNull($this->source->refresh()->locked_payer_type);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_unknown_payer_shows_inline_error_without_mutation(): void
    {
        Livewire::test(ListIntegrationClients::class)
            ->callAction(TestAction::make('fundingPolicy')->table($this->client), data: [
                'allowed_payer_types' => ['SPONSORED'],
            ])->assertHasFormErrors(['allowed_payer_types']);
        $this->assertSame(['self'], $this->organization->refresh()->allowed_payer_types);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_cancel_does_not_replace_unconfigured_policy_with_off(): void
    {
        $this->organization->update(['allowed_payer_types' => null]);
        $component = Livewire::test(ListIntegrationClients::class)
            ->mountAction(TestAction::make('fundingPolicy')->table($this->client))
            ->assertActionMounted(TestAction::make('fundingPolicy')->table($this->client));
        $this->assertStringContainsString('Belum dikonfigurasi', $component->instance()->getMountedAction()->getModalDescription());
        $component->unmountAction();
        $this->assertNull($this->organization->refresh()->allowed_payer_types);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    #[DataProvider('restrictedRoles')]
    public function test_non_super_admin_cannot_open_policy_panels(AdminRole $role): void
    {
        $this->admin->update(['role' => $role, 'branch_id' => $this->organization->id, 'can_verify_payments' => true]);
        $this->get('/admin/integration-clients')->assertForbidden();
        $this->get('/admin/integration-sources')->assertForbidden();
        $this->get('/admin/integration-sources/'.$this->source->id.'/edit')->assertForbidden();
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function restrictedRoles(): iterable
    {
        yield [AdminRole::BranchAdmin];
        yield [AdminRole::Staff];
        yield [AdminRole::Psychologist];
    }

    public function test_general_source_save_cannot_bypass_policy_audit(): void
    {
        Livewire::test(EditIntegrationSource::class, ['record' => $this->source->id])
            ->set('data.allowed_payer_types', ['organization'])
            ->set('data.locked_payer_type', 'organization')
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame(['self'], $this->source->refresh()->allowed_payer_types);
        $this->assertNull($this->source->locked_payer_type);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'funding_policy.updated')->count());
    }

    public function test_duplicate_payers_are_reported_by_the_audited_action(): void
    {
        Livewire::test(ListIntegrationClients::class)
            ->callAction(TestAction::make('fundingPolicy')->table($this->client), data: [
                'allowed_payer_types' => ['self', 'self'],
            ])->assertHasFormErrors(['allowed_payer_types']);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_nested_input_cannot_be_silently_converted_to_off(): void
    {
        Livewire::test(ListIntegrationClients::class)
            ->mountAction(TestAction::make('fundingPolicy')->table($this->client))
            ->set('mountedActions.0.data.allowed_payer_types', [['organization']])
            ->callMountedAction()->assertHasFormErrors(['allowed_payer_types']);
        $this->assertSame(['self'], $this->organization->refresh()->allowed_payer_types);
        $this->assertDatabaseCount('audit_logs', 0);
    }
}
