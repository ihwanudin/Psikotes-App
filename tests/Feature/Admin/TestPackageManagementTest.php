<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Filament\Resources\TestPackages\Pages\EditTestPackage;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\TestPackage;
use Database\Seeders\TestPackageSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class TestPackageManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        $this->seed(TestPackageSeeder::class);
    }

    public function test_super_admin_can_open_package_list_and_edit_pages(): void
    {
        $admin = $this->admin(AdminRole::SuperAdmin);
        $package = $this->package('IST');

        $this->actingAs($admin, 'admin')
            ->get('/admin/test-packages')
            ->assertOk();

        $this->get("/admin/test-packages/{$package->id}/edit")
            ->assertOk();
    }

    #[DataProvider('nonCentralRoles')]
    public function test_non_super_admin_roles_cannot_access_package_management(AdminRole $role): void
    {
        $package = $this->package('IST');
        $branch = in_array($role, [AdminRole::BranchAdmin, AdminRole::Staff], true)
            ? $this->branch($role->value)
            : null;
        $admin = $this->admin($role, $branch);

        $this->actingAs($admin, 'admin')
            ->get('/admin/test-packages')
            ->assertForbidden();

        $this->get("/admin/test-packages/{$package->id}/edit")
            ->assertForbidden();
    }

    public function test_super_admin_can_set_an_idr_price_and_activate_a_package(): void
    {
        $admin = $this->admin(AdminRole::SuperAdmin);
        $package = $this->package('IST');
        $this->actingAs($admin, 'admin');

        Livewire::test(EditTestPackage::class, ['record' => $package->id])
            ->fillForm([
                'amount' => 150000,
                'consultation_amount' => 50000,
                'is_active' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertDatabaseHas('packages', [
            'id' => $package->id,
            'amount' => 150000,
            'consultation_amount' => 50000,
            'currency' => 'IDR',
            'is_active' => true,
        ]);
    }

    public function test_package_cannot_be_activated_without_a_configured_price(): void
    {
        $admin = $this->admin(AdminRole::SuperAdmin);
        $package = $this->package('IST');
        $this->actingAs($admin, 'admin');

        Livewire::test(EditTestPackage::class, ['record' => $package->id])
            ->fillForm([
                'amount' => null,
                'is_active' => true,
            ])
            ->call('save')
            ->assertHasFormErrors(['amount' => 'required']);

        $this->assertDatabaseHas('packages', [
            'id' => $package->id,
            'amount' => 99000,
            'is_active' => false,
        ]);
    }

    public function test_super_admin_can_activate_a_free_package(): void
    {
        $admin = $this->admin(AdminRole::SuperAdmin);
        $package = $this->package('DASS21');
        $this->actingAs($admin, 'admin');

        Livewire::test(EditTestPackage::class, ['record' => $package->id])
            ->fillForm([
                'amount' => 0,
                'consultation_amount' => 50000,
                'is_active' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertDatabaseHas('packages', [
            'id' => $package->id,
            'amount' => 0,
            'consultation_amount' => 50000,
            'is_active' => true,
        ]);
    }

    public function test_canonical_package_templates_cannot_be_created_or_deleted_from_admin(): void
    {
        $admin = $this->admin(AdminRole::SuperAdmin);
        $package = $this->package('IST');

        $this->assertFalse(Gate::forUser($admin)->allows('create', TestPackage::class));
        $this->assertFalse(Gate::forUser($admin)->allows('delete', $package));
    }

    private function package(string $code): TestPackage
    {
        return TestPackage::query()->where('code', $code)->sole();
    }

    private function branch(string $suffix): Branch
    {
        return Branch::query()->create([
            'code' => "BR-{$suffix}",
            'name' => "Branch {$suffix}",
            'ref_code' => "REF-{$suffix}",
        ]);
    }

    private function admin(AdminRole $role, ?Branch $branch = null): Admin
    {
        return Admin::query()->create([
            'branch_id' => $branch?->id,
            'name' => "Admin {$role->value}",
            'email' => $role->value.'@example.test',
            'password' => 'not-a-real-password',
            'role' => $role,
        ]);
    }

    /** @return iterable<string, array{AdminRole}> */
    public static function nonCentralRoles(): iterable
    {
        yield 'branch admin' => [AdminRole::BranchAdmin];
        yield 'staff' => [AdminRole::Staff];
        yield 'psychologist' => [AdminRole::Psychologist];
    }
}
