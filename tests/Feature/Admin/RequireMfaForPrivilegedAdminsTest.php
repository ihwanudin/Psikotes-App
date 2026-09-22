<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Branch;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Admin panel MFA (2026-09-22, Lead sign-off,
 * tasks/handoffs/f2/admin-mfa-plan.md). Filament's own
 * multiFactorAuthentication() isRequired cannot be scoped by role (it is
 * evaluated once at route-registration time, before any admin is
 * authenticated -- see RequireMfaForPrivilegedAdmins' docblock), so this
 * middleware does the real, per-request, role-scoped enforcement.
 */
final class RequireMfaForPrivilegedAdminsTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('requiredRoles')]
    public function test_required_role_without_mfa_is_redirected_to_profile(AdminRole $role): void
    {
        $admin = $this->admin($role, hasEmailAuthentication: false);

        $response = $this->actingAs($admin, 'admin')->get('/admin');

        $response->assertRedirect(Filament::getProfileUrl());
    }

    #[DataProvider('requiredRoles')]
    public function test_required_role_with_mfa_enabled_reaches_the_panel(AdminRole $role): void
    {
        $admin = $this->admin($role, hasEmailAuthentication: true);

        $response = $this->actingAs($admin, 'admin')->get('/admin');

        $response->assertOk();
    }

    #[DataProvider('notRequiredRoles')]
    public function test_non_required_role_without_mfa_reaches_the_panel(AdminRole $role): void
    {
        $admin = $this->admin($role, hasEmailAuthentication: false);

        $response = $this->actingAs($admin, 'admin')->get('/admin');

        $response->assertOk();
    }

    /**
     * The loop guard: authMiddleware applies panel-wide, including the
     * profile route this middleware redirects to, so it must not redirect
     * a request that is already headed there.
     */
    public function test_profile_page_itself_does_not_redirect_again(): void
    {
        $admin = $this->admin(AdminRole::SuperAdmin, hasEmailAuthentication: false);
        $profileUrl = Filament::getProfileUrl();
        $this->assertNotNull($profileUrl);

        $response = $this->actingAs($admin, 'admin')->get($profileUrl);

        $response->assertOk();
    }

    /** @return iterable<string, array{AdminRole}> */
    public static function requiredRoles(): iterable
    {
        yield 'super admin' => [AdminRole::SuperAdmin];
        yield 'psychologist' => [AdminRole::Psychologist];
    }

    /** @return iterable<string, array{AdminRole}> */
    public static function notRequiredRoles(): iterable
    {
        yield 'branch admin' => [AdminRole::BranchAdmin];
        yield 'staff' => [AdminRole::Staff];
    }

    private function admin(AdminRole $role, bool $hasEmailAuthentication): Admin
    {
        $requiresBranch = in_array($role, [AdminRole::BranchAdmin, AdminRole::Staff], true);

        return Admin::query()->create([
            'branch_id' => $requiresBranch ? $this->branchId() : null,
            'name' => 'Admin '.$role->value,
            'email' => $role->value.'-'.uniqid('', true).'@example.test',
            'password' => Hash::make('irrelevant-existing-password'),
            'role' => $role,
            'has_email_authentication' => $hasEmailAuthentication,
        ]);
    }

    private function branchId(): int
    {
        return Branch::query()->create([
            'code' => 'BR-'.uniqid('', true),
            'name' => 'Branch',
            'ref_code' => 'REF-'.uniqid('', true),
        ])->id;
    }
}
