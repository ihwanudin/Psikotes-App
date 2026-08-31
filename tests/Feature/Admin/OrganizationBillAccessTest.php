<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Filament\Resources\OrganizationBills\OrganizationBillResource;
use App\Filament\Resources\OrganizationBills\Pages\ListOrganizationBills;
use App\Filament\Resources\OrganizationBills\Pages\ViewOrganizationBill;
use App\Models\Admin;
use App\Models\AssessmentBill;
use App\Models\AssessmentCharge;
use App\Models\TestPackage;
use App\Services\Payments\AssessmentPriceSnapshot;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentBillingFixture as Fixture;

final class OrganizationBillAccessTest extends OrganizationPaymentTestCase
{
    use RefreshDatabase;

    private array $own;

    private array $foreign;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        $this->own = $this->bill('OWN');
        $this->foreign = $this->bill('FOREIGN');
        $this->admin = Admin::create([
            'name' => 'Admin Sintetis', 'email' => 'portal@example.test',
            'password' => 'synthetic-password', 'role' => AdminRole::BranchAdmin,
            'branch_id' => $this->own['organization'], 'can_verify_payments' => true,
        ]);
        $this->actingAs($this->admin, 'admin');
    }

    public function test_list_and_direct_url_are_scoped_to_organization_payer_and_owner(): void
    {
        $self = Fixture::create('self', ['organization' => $this->own['organization']]);
        $this->assertSame([$this->own['bill']], OrganizationBillResource::getEloquentQuery()->pluck('id')->all());
        $this->get('/admin/organization-bills')->assertOk()->assertSee('AB_OWN')->assertDontSee('AB_FOREIGN');
        $this->get('/admin/organization-bills/'.$this->own['bill'])->assertOk()
            ->assertSee('Detail Tagihan Cabang')->assertSee('Peserta OWN');
        $this->get('/admin/organization-bills/'.$this->foreign['bill'])->assertNotFound();
        $this->get('/admin/organization-bills/'.$self['bill'])->assertNotFound();
        $this->get('/admin/organization-bills/999999')->assertNotFound();
    }

    #[DataProvider('otherRoles')]
    public function test_other_roles_cannot_read_even_with_legacy_verifier_flag(AdminRole $role): void
    {
        $this->admin->update(['role' => $role]);
        $this->assertFalse(OrganizationBillResource::canViewAny());
        $this->assertSame(0, OrganizationBillResource::getEloquentQuery()->count());
        $this->get('/admin/organization-bills')->assertForbidden();
        $this->get('/admin/organization-bills/'.$this->own['bill'])->assertNotFound();
        Livewire::test(ListOrganizationBills::class)->assertForbidden();
    }

    public static function otherRoles(): iterable
    {
        yield [AdminRole::SuperAdmin];
        yield [AdminRole::Staff];
        yield [AdminRole::Psychologist];
    }

    public function test_guest_cannot_access_panel_or_query(): void
    {
        auth('admin')->logout();
        $this->assertFalse(OrganizationBillResource::canViewAny());
        $this->assertSame(0, OrganizationBillResource::getEloquentQuery()->count());
        $this->get('/admin/organization-bills')->assertRedirect('/admin/login');
        $this->get('/admin/organization-bills/'.$this->own['bill'])->assertRedirect('/admin/login');
    }

    public function test_resource_is_not_available_outside_testing_even_with_authenticated_owner(): void
    {
        $this->app->instance('env', 'production');
        $this->assertFalse(OrganizationBillResource::isDiscovered());
        $this->assertFalse(OrganizationBillResource::canViewAny());
        $this->assertFalse(OrganizationBillResource::shouldRegisterNavigation());
        $this->assertSame(0, OrganizationBillResource::getEloquentQuery()->count());
        $this->get('/admin/organization-bills')->assertForbidden();
        $this->get('/admin/organization-bills/'.$this->own['bill'])->assertNotFound();
    }

    public function test_revoked_role_and_membership_fail_closed_on_livewire_refresh(): void
    {
        $list = Livewire::test(ListOrganizationBills::class)->assertSuccessful();
        $view = Livewire::test(ViewOrganizationBill::class, ['record' => $this->own['bill']])->assertSuccessful();
        DB::table('admins')->where('id', $this->admin->id)->update(['branch_id' => $this->foreign['organization']]);
        $view->call('$refresh')->assertForbidden();
        DB::table('admins')->where('id', $this->admin->id)->update(['role' => AdminRole::Staff->value]);
        $list->call('$refresh')->assertForbidden();
        $this->assertFalse(OrganizationBillResource::canViewAny());
    }

    public function test_missing_branch_and_deleted_admin_are_denied(): void
    {
        DB::table('admins')->where('id', $this->admin->id)->update(['branch_id' => null]);
        $this->assertFalse(OrganizationBillResource::canViewAny());
        $this->assertSame(0, OrganizationBillResource::getEloquentQuery()->count());
        $this->admin->delete();
        $this->assertFalse(OrganizationBillResource::canViewAny());
    }

    public function test_paid_history_filter_search_and_pagination_use_same_scoped_bills(): void
    {
        $paid = $this->bill('PAID', $this->own['organization'], 'paid');
        for ($i = 0; $i < 11; $i++) {
            $this->bill('PAGE-'.$i, $this->own['organization']);
        }
        $list = Livewire::test(ListOrganizationBills::class)
            ->set('tableRecordsPerPage', 10)->assertCountTableRecords(13);
        $this->assertCount(10, $list->instance()->getTableRecords());
        $list->call('gotoPage', 2);
        $this->assertCount(3, $list->instance()->getTableRecords());
        $list->filterTable('status', 'paid')
            ->assertCanSeeTableRecords([$paid['bill']])->assertCountTableRecords(1)
            ->assertCanNotSeeTableRecords([$this->own['bill'], $this->foreign['bill']]);
        $list->resetTableFilters()->searchTable('FOREIGN')->assertCountTableRecords(0);
        $list->searchTable('AB_OWN')->assertCountTableRecords(1);
    }

    public function test_detail_is_read_only_minimal_and_uses_stored_bill_and_charge_values(): void
    {
        $bill = AssessmentBill::findOrFail($this->own['bill']);
        $bill->update(['status' => 'expired', 'invoice_url' => 'https://gateway.example.test/DO-NOT-EXPOSE',
            'proof_object_key' => 'PRIVATE-PROOF', 'gateway_ref' => 'PRIVATE-GATEWAY',
            'rejection_reason' => 'PRIVATE-REVIEW-NOTE']);
        DB::table('assessment_participants')->where('id', $this->own['attempt'])->update([
            'metadata' => json_encode(['clinical' => 'CLINICAL-SENTINEL']),
        ]);
        DB::table('packages')->where('id', $this->own['package'])->update(['name' => 'CATALOG-CHANGED', 'amount' => 999999]);
        $before = (array) DB::table('assessment_bills')->where('id', $bill->id)->first();
        $component = Livewire::test(ViewOrganizationBill::class, ['record' => $bill->id])
            ->assertSee('Kedaluwarsa')->assertSee('Hubungi petugas ONCAM')
            ->assertSee('Paket snapshot OWN')->assertSee('Peserta OWN')->assertSee('Periode OWN')
            ->assertDontSee('Peserta FOREIGN')->assertDontSee('CLINICAL-SENTINEL')
            ->assertDontSee('CATALOG-CHANGED')->assertDontSee('PRIVATE-PROOF')
            ->assertDontSee('PRIVATE-GATEWAY')->assertDontSee('DO-NOT-EXPOSE')->assertDontSee('PRIVATE-REVIEW-NOTE');
        $component->call('refreshFormData', ['invoice_url', 'proof_object_key', 'gateway_ref', 'request_hash'])
            ->assertDontSee('PRIVATE-PROOF')->assertDontSee('DO-NOT-EXPOSE');
        foreach (['create', 'update', 'delete', 'deleteAny', 'forceDelete', 'restore', 'replicate', 'reorder', 'verifyPayment'] as $action) {
            $this->assertFalse(OrganizationBillResource::can($action, $bill), $action);
        }
        $this->get('/admin/organization-bills/'.$bill->id.'/edit')->assertNotFound();
        $this->assertSame($before, (array) DB::table('assessment_bills')->where('id', $bill->id)->first());
        $this->assertDatabaseCount('assessment_bills', 2);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public function test_two_attempts_of_one_participant_keep_server_count_total_and_allocations(): void
    {
        $second = Fixture::create('organization', [
            'organization' => $this->own['organization'], 'participant' => $this->own['participant'],
        ]);
        $this->validSnapshot($second, 'SECOND');
        DB::table('assessment_bills')->where('id', $second['bill'])->delete();
        $second['bill'] = $this->own['bill'];
        DB::table('assessment_bill_items')->insert(Fixture::item($second));
        DB::table('assessment_bills')->where('id', $this->own['bill'])->update(['item_count' => 2, 'amount' => 200]);
        $bill = AssessmentBill::findOrFail($this->own['bill']);
        Livewire::test(ListOrganizationBills::class)
            ->assertTableColumnStateSet('item_count', 2, $bill)
            ->assertTableColumnStateSet('amount', 200, $bill)
            ->assertTableColumnStateSet('status', 'pending', $bill);
        Livewire::test(ViewOrganizationBill::class, ['record' => $bill->id])
            ->assertSee(DB::table('assessment_participants')->where('id', $this->own['attempt'])->value('assessment_attempt_id'))
            ->assertSee(DB::table('assessment_participants')->where('id', $second['attempt'])->value('assessment_attempt_id'));
    }

    public function test_rejected_bill_displays_terminal_guidance_without_reinvoice_controls(): void
    {
        AssessmentBill::findOrFail($this->own['bill'])->update(['status' => 'rejected']);
        Livewire::test(ViewOrganizationBill::class, ['record' => $this->own['bill']])
            ->assertSee('Ditolak')->assertSee('Hubungi petugas ONCAM')
            ->assertActionDoesNotExist('create')->assertActionDoesNotExist('edit')
            ->assertActionDoesNotExist('verifyPayment')->assertActionDoesNotExist('reinvoice');
        $this->assertDatabaseCount('assessment_bills', 2);
    }

    public function test_detail_navigation_and_forged_action_recheck_persisted_membership(): void
    {
        $list = Livewire::test(ListOrganizationBills::class)
            ->assertTableActionHasUrl('detail', OrganizationBillResource::getUrl('view', ['record' => $this->own['bill']]), $this->own['bill']);
        DB::table('admins')->where('id', $this->admin->id)->update(['branch_id' => $this->foreign['organization']]);
        $list->mountTableAction('detail', $this->own['bill'])->assertActionNotMounted('detail');
        $this->get('/admin/organization-bills/'.$this->own['bill'])->assertNotFound();
        DB::table('admins')->where('id', $this->admin->id)->update(['role' => AdminRole::Staff->value]);
        $list->mountTableAction('detail', $this->foreign['bill'])->assertForbidden();
    }

    public function test_paginated_livewire_query_counts(): void
    {
        for ($i = 1; $i < 50; $i++) {
            $this->bill('MEASURE-'.$i, $this->own['organization']);
        }
        $list = Livewire::test(ListOrganizationBills::class);
        foreach ([10, 25, 50] as $size) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $list->set('tableRecordsPerPage', $size)->assertSuccessful();
                $queries = DB::getQueryLog();
            } finally {
                DB::disableQueryLog();
            }
            $this->assertCount($size, $list->instance()->getTableRecords());
            fwrite(STDERR, "\nPortal SQLite Livewire page {$size}: ".count($queries)." queries\n");
            $this->assertLessThanOrEqual(20, count($queries), 'Rendering navigation links must not query authorization per row.');
        }
    }

    private function bill(string $label, ?int $organization = null, string $status = 'pending'): array
    {
        $fixture = Fixture::create('organization', $organization === null ? null : ['organization' => $organization]);
        DB::table('participants')->where('id', $fixture['participant'])->update(['full_name' => 'Peserta '.$label]);
        DB::table('assessment_participants')->where('id', $fixture['attempt'])->update(['assessment_round_id' => 'Periode '.$label]);
        $this->validSnapshot($fixture, $label);
        DB::table('assessment_bill_items')->insert(Fixture::item($fixture));
        DB::table('assessment_bills')->where('id', $fixture['bill'])->update([
            'public_reference' => 'AB_'.$label, 'status' => $status,
            'expires_at' => '2026-09-01 12:00:00', 'created_at' => '2026-08-31 12:00:00',
            'paid_at' => $status === 'paid' ? '2026-08-31 13:00:00' : null,
        ]);

        return $fixture;
    }

    private function validSnapshot(array $fixture, string $label): void
    {
        DB::table('packages')->where('id', $fixture['package'])->update(['name' => 'Paket snapshot '.$label, 'is_active' => true]);
        DB::table('package_items')->insert(['package_id' => $fixture['package'], 'test_type' => 'ist', 'sort_order' => 1]);
        $snapshots = app(AssessmentPriceSnapshot::class);
        $snapshot = $snapshots->capture(TestPackage::with('items')->findOrFail($fixture['package']), false);
        AssessmentCharge::findOrFail($fixture['charge'])->update(['price_snapshot' => $snapshot]);
        $this->assertSame($snapshot, $snapshots->fromCharge(AssessmentCharge::findOrFail($fixture['charge']), false));
    }
}
