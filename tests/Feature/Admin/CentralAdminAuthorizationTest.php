<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminAbility;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Branch;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CentralAdminAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // Test 1: Full ability matrix — every AdminAbility × central_admin
    // -----------------------------------------------------------------

    /** @return iterable<string, array{AdminAbility, bool}> */
    public static function centralAdminAbilityMatrix(): iterable
    {
        yield 'AccessPanel' => [AdminAbility::AccessPanel,        true];
        yield 'ViewParticipants' => [AdminAbility::ViewParticipants,   true];
        yield 'ManageAdmins' => [AdminAbility::ManageAdmins,       false];
        yield 'ManageTestPackages' => [AdminAbility::ManageTestPackages, true];
        yield 'ManagePaymentMethods' => [AdminAbility::ManagePaymentMethods, false];
        yield 'ManageIntegrations' => [AdminAbility::ManageIntegrations, false];
        yield 'EditParticipants' => [AdminAbility::EditParticipants,   true];
        yield 'VerifyPayments' => [AdminAbility::VerifyPayments,     true];
        yield 'ViewDass' => [AdminAbility::ViewDass,           false];
        yield 'ReviewReports' => [AdminAbility::ReviewReports,      false];
        yield 'GenerateReports' => [AdminAbility::GenerateReports,    true];
    }

    #[DataProvider('centralAdminAbilityMatrix')]
    public function test_central_admin_ability_matrix_matches_spec(AdminAbility $ability, bool $expected): void
    {
        $admin = $this->createCentralAdmin();

        $this->assertSame($expected, $admin->canPerform($ability));
    }

    // -----------------------------------------------------------------
    // Regression: branch_admin/staff tetap ditolak GenerateReports
    // -----------------------------------------------------------------

    public function test_branch_admin_and_staff_still_cannot_generate_reports(): void
    {
        $branch = Branch::query()->create([
            'code' => 'BR-REGRESS',
            'name' => 'Branch Regression',
            'ref_code' => 'REF-REGRESS',
        ]);

        foreach ([AdminRole::BranchAdmin, AdminRole::Staff] as $role) {
            $admin = Admin::query()->create([
                'branch_id' => $branch->id,
                'name' => "Admin {$role->value}",
                'email' => "{$role->value}-regression@example.test",
                'password' => bcrypt('password'),
                'role' => $role,
            ]);

            $this->assertFalse(
                $admin->canPerform(AdminAbility::GenerateReports),
                "{$role->value} must not be able to generate reports.",
            );
        }
    }

    // -----------------------------------------------------------------
    // Test 2: runAsService() from central_admin RlsContext — BERHASIL
    // (catches missing ADMIN_ROLES entry in RlsContextRunner)
    // -----------------------------------------------------------------

    public function test_central_admin_context_can_elevate_to_service_and_is_restored(): void
    {
        $runner = $this->app->make(RlsContextRunner::class);
        $context = new RlsContext('central_admin');

        $runner->run($context, function () use ($runner, $context): void {
            $result = $runner->runAsService(function () use ($runner): string {
                $this->assertSame('service', $runner->current()?->role);

                return 'elevated';
            });

            $this->assertSame('elevated', $result);
            $this->assertSame($context, $runner->current());
        });

        $this->assertNull($runner->current());
    }

    public function test_central_admin_service_elevation_restores_after_exception(): void
    {
        $runner = $this->app->make(RlsContextRunner::class);
        $context = new RlsContext('central_admin');

        $runner->run($context, function () use ($runner, $context): void {
            try {
                $runner->runAsService(static function (): never {
                    throw new \RuntimeException('expected');
                });

                $this->fail('The elevated callback should have thrown.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('expected', $exception->getMessage());
            }

            $this->assertSame($context, $runner->current());
        });

        $this->assertNull($runner->current());
    }

    // -----------------------------------------------------------------
    // VerifyPayments is unconditional for central_admin (no flag gate)
    // -----------------------------------------------------------------

    public function test_central_admin_verify_payments_does_not_require_flag(): void
    {
        $admin = Admin::query()->create([
            'name' => 'Central Admin No Flag',
            'email' => 'central-no-flag@example.test',
            'password' => bcrypt('password'),
            'role' => AdminRole::CentralAdmin,
            'can_verify_payments' => false,
        ]);

        $this->assertTrue($admin->canPerform(AdminAbility::VerifyPayments));
    }

    private function createCentralAdmin(): Admin
    {
        return Admin::query()->create([
            'name' => 'Central Admin',
            'email' => 'central-admin@example.test',
            'password' => bcrypt('password'),
            'role' => AdminRole::CentralAdmin,
        ]);
    }
}
