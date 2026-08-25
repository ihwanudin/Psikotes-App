<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Filament\Resources\PaymentMethods\Pages\EditPaymentMethod;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\PaymentMethod;
use Database\Seeders\PaymentMethodSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PaymentMethodManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        $this->seed(PaymentMethodSeeder::class);
    }

    public function test_super_admin_can_open_payment_method_list_and_edit_pages(): void
    {
        $admin = $this->admin(AdminRole::SuperAdmin);
        $method = $this->method('xendit');

        $this->actingAs($admin, 'admin')
            ->get('/admin/payment-methods')
            ->assertOk();

        $this->get("/admin/payment-methods/{$method->id}/edit")
            ->assertOk();
    }

    #[DataProvider('unauthorizedRoles')]
    public function test_non_super_admin_cannot_access_payment_method_management(AdminRole $role): void
    {
        $admin = $this->admin($role);
        $method = $this->method('xendit');

        $this->actingAs($admin, 'admin')
            ->get('/admin/payment-methods')
            ->assertForbidden();

        $this->get("/admin/payment-methods/{$method->id}/edit")
            ->assertForbidden();
    }

    public function test_super_admin_can_toggle_a_method_on_and_off_with_audit(): void
    {
        $admin = $this->admin(AdminRole::SuperAdmin);
        $method = $this->method('xendit');
        $this->actingAs($admin, 'admin');

        Livewire::test(EditPaymentMethod::class, ['record' => $method->id])
            ->fillForm(['is_active' => true])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        Livewire::test(EditPaymentMethod::class, ['record' => $method->id])
            ->fillForm(['is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertFalse($method->fresh()->is_active);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_canonical_code_and_display_name_cannot_be_changed_through_the_form(): void
    {
        $admin = $this->admin(AdminRole::SuperAdmin);
        $method = $this->method('xendit');
        $this->actingAs($admin, 'admin');

        Livewire::test(EditPaymentMethod::class, ['record' => $method->id])
            ->fillForm([
                'code' => 'attacker_method',
                'display_name' => 'Attacker Method',
                'is_active' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $method->refresh();
        $this->assertSame('xendit', $method->code);
        $this->assertSame('Xendit Invoice', $method->display_name);
        $this->assertTrue($method->is_active);
    }

    private function method(string $code): PaymentMethod
    {
        return PaymentMethod::query()->where('code', $code)->sole();
    }

    private function admin(AdminRole $role): Admin
    {
        $branchId = in_array($role, [AdminRole::BranchAdmin, AdminRole::Staff], true)
            ? Branch::query()->create([
                'code' => 'BR-'.strtoupper($role->value),
                'name' => "Branch {$role->value}",
                'ref_code' => 'REF-'.strtoupper($role->value),
            ])->id
            : null;

        return Admin::query()->create([
            'branch_id' => $branchId,
            'name' => "Admin {$role->value}",
            'email' => $role->value.'@example.test',
            'password' => 'not-a-real-password',
            'role' => $role,
        ]);
    }

    /** @return iterable<string, array{AdminRole}> */
    public static function unauthorizedRoles(): iterable
    {
        yield 'branch admin' => [AdminRole::BranchAdmin];
        yield 'staff' => [AdminRole::Staff];
        yield 'psychologist' => [AdminRole::Psychologist];
    }
}
