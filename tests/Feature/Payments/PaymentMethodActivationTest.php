<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Actions\Payments\SetPaymentMethodActivation;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\PaymentMethod;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PaymentMethodActivationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PaymentMethodSeeder::class);
    }

    public function test_payment_methods_are_disabled_by_default(): void
    {
        $this->assertSame(2, PaymentMethod::query()->count());
        $this->assertSame(0, PaymentMethod::query()->active()->count());
    }

    public function test_super_admin_can_activate_and_deactivate_a_method_with_audit(): void
    {
        Date::setTestNow('2024-02-29 10:15:00+07:00');
        $admin = $this->admin(AdminRole::SuperAdmin);
        $method = PaymentMethod::query()->where('code', 'xendit')->sole();
        $action = app(SetPaymentMethodActivation::class);

        $activated = $action->handle($admin, $method->id, true);
        $audit = (array) DB::table('audit_logs')->sole();
        $this->assertSame('2024-02-29 03:15:00', $audit['occurred_at']);
        $this->assertSame('2029-02-28 03:15:00', $audit['expires_at']);
        $this->assertSame([
            'code' => 'xendit',
            'from' => false,
            'to' => true,
        ], json_decode((string) $audit['context'], true, 512, JSON_THROW_ON_ERROR));
        foreach ([$admin->name, $admin->email] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, (string) $audit['context']);
        }

        Date::setTestNow('2024-02-29 11:00:00+07:00');
        $deactivated = $action->handle($admin, $method->id, false);

        $this->assertTrue($activated->is_active);
        $this->assertFalse($deactivated->is_active);
        $this->assertDatabaseHas('payment_methods', [
            'id' => $method->id,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_type' => 'admin',
            'actor_id' => (string) $admin->id,
            'action' => 'payment_method.activation_changed',
            'subject_type' => PaymentMethod::class,
            'subject_id' => (string) $method->id,
        ]);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_repeating_the_same_state_is_a_no_op_without_duplicate_audit(): void
    {
        Date::setTestNow('2024-02-29 10:15:00+07:00');
        $admin = $this->admin(AdminRole::SuperAdmin);
        $method = PaymentMethod::query()->where('code', 'xendit')->sole();
        $action = app(SetPaymentMethodActivation::class);

        $action->handle($admin, $method->id, true);
        Date::setTestNow('2024-02-29 11:00:00+07:00');
        $unchanged = $action->handle($admin, $method->id, true);

        $this->assertTrue($unchanged->is_active);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    #[DataProvider('unauthorizedRoles')]
    public function test_non_super_admin_cannot_change_activation(AdminRole $role): void
    {
        $admin = $this->admin($role);
        $method = PaymentMethod::query()->where('code', 'xendit')->sole();

        try {
            app(SetPaymentMethodActivation::class)->handle($admin, $method->id, true);
            $this->fail('Expected payment method activation to be forbidden.');
        } catch (AuthorizationException) {
            $this->assertFalse($method->fresh()->is_active);
            $this->assertDatabaseCount('audit_logs', 0);
        }
    }

    private function admin(AdminRole $role): Admin
    {
        return Admin::query()->create([
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
