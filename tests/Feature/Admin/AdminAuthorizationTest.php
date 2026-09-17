<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminAbility;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\Participant;
use App\Security\RlsContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AdminAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('adminRoles')]
    public function test_each_admin_role_authenticates_server_side_and_gets_expected_rls_role(
        AdminRole $role,
        string $expectedRlsRole,
        bool $requiresBranch,
    ): void {
        $branch = $requiresBranch ? $this->branch(strtoupper($role->value)) : null;
        $admin = $this->admin($role, $branch);

        $this->assertTrue(Auth::guard('admin')->attempt([
            'email' => $admin->email,
            'password' => 'not-a-real-password',
        ]));
        $this->assertSame($admin->id, Auth::guard('admin')->id());
        $this->assertTrue($admin->canAccessPanel(Filament::getPanel('admin')));
        $this->assertSame($expectedRlsRole, $admin->rlsContext()->role);

        Auth::guard('admin')->logout();
    }

    public function test_branch_admin_gets_its_branch_rls_context_and_server_side_capabilities(): void
    {
        $branch = $this->branch('A');
        $admin = $this->admin(AdminRole::BranchAdmin, $branch, canVerifyPayments: true);

        $this->assertEquals(
            new RlsContext('branch_admin', $branch->id),
            $admin->rlsContext(),
        );
        $this->assertTrue($admin->canPerform(AdminAbility::ViewParticipants));
        $this->assertTrue($admin->canPerform(AdminAbility::VerifyPayments));
        $this->assertFalse($admin->canPerform(AdminAbility::ViewDass));
        $this->assertFalse($admin->canPerform(AdminAbility::ManageAdmins));
    }

    public function test_payment_verification_flag_cannot_be_bypassed_by_role(): void
    {
        $branch = $this->branch('A');
        $admin = $this->admin(AdminRole::BranchAdmin, $branch, canVerifyPayments: false);

        $this->assertFalse($admin->canPerform(AdminAbility::VerifyPayments));
    }

    public function test_only_psychologist_role_can_view_dass_and_review_reports(): void
    {
        $psychologist = $this->admin(AdminRole::Psychologist);
        $superAdmin = $this->admin(AdminRole::SuperAdmin);

        $this->assertTrue($psychologist->canPerform(AdminAbility::ViewDass));
        $this->assertTrue($psychologist->canPerform(AdminAbility::ReviewReports));
        $this->assertFalse($superAdmin->canPerform(AdminAbility::ViewDass));
        $this->assertFalse($superAdmin->canPerform(AdminAbility::ReviewReports));
    }

    public function test_cross_branch_participant_idor_is_denied_even_when_identifier_is_known(): void
    {
        $branchA = $this->branch('A');
        $branchB = $this->branch('B');
        $admin = $this->admin(AdminRole::BranchAdmin, $branchA);
        $participantA = $this->participant($branchA, 'A-001');
        $participantB = $this->participant($branchB, 'B-001');

        $this->assertTrue(Gate::forUser($admin)->allows('view', $participantA));
        $this->assertFalse(Gate::forUser($admin)->allows('view', $participantB));
        $this->assertFalse(Gate::forUser($admin)->allows('update', $participantB));
    }

    private function branch(string $suffix): Branch
    {
        return Branch::query()->create([
            'code' => "BR-{$suffix}",
            'name' => "Branch {$suffix}",
            'ref_code' => "REF-{$suffix}",
        ]);
    }

    private function admin(
        AdminRole $role,
        ?Branch $branch = null,
        bool $canVerifyPayments = false,
    ): Admin {
        return Admin::query()->create([
            'branch_id' => $branch?->id,
            'name' => "Admin {$role->value}",
            'email' => $role->value.'-'.($branch?->id ?? 'central').'@example.test',
            'password' => 'not-a-real-password',
            'role' => $role,
            'can_verify_payments' => $canVerifyPayments,
        ]);
    }

    private function participant(Branch $branch, string $testNumber): Participant
    {
        return Participant::query()->create([
            'branch_id' => $branch->id,
            'referral_branch_id' => $branch->id,
            'referral_source' => 'manual',
            'full_name' => "Participant {$testNumber}",
            'gender' => 'female',
            'birth_date' => '2000-01-01',
            'education_level' => 'SMA',
            'intended_field' => 'KAIGO',
            'phone' => '081200000000',
            'test_number' => $testNumber,
        ]);
    }

    /** @return iterable<string, array{AdminRole, string, bool}> */
    public static function adminRoles(): iterable
    {
        yield 'super admin' => [AdminRole::SuperAdmin, 'super_admin', false];
        yield 'branch admin' => [AdminRole::BranchAdmin, 'branch_admin', true];
        yield 'staff' => [AdminRole::Staff, 'staff', true];
        yield 'psychologist' => [AdminRole::Psychologist, 'psychologist', false];
    }
}
