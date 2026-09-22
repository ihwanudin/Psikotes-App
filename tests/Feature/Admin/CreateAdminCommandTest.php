<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

final class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_branch_admin_and_issues_a_password_setup_link(): void
    {
        $branch = Branch::query()->create([
            'code' => 'CENTRAL', 'ref_code' => 'CENTRAL-REF', 'name' => 'Central', 'is_default' => true,
        ]);

        $this->createCommand([
            '--operator' => 'ops-1',
            '--role' => 'branch_admin',
            '--name' => 'Kepala Cabang',
            '--email' => 'cabang@example.test',
            '--branch_id' => (string) $branch->id,
        ])
            ->expectsOutputToContain('RAHASIA')
            ->expectsOutputToContain('admin-password-setup')
            ->assertExitCode(0);

        $admin = Admin::query()->sole();
        $this->assertSame(AdminRole::BranchAdmin, $admin->role);
        $this->assertSame($branch->id, $admin->branch_id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.created', 'actor_id' => 'ops-1', 'subject_id' => (string) $admin->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.password_setup_link_issued', 'actor_id' => 'ops-1', 'subject_id' => (string) $admin->id,
        ]);

        $token = DB::table('admin_password_setup_tokens')->where('admin_id', $admin->id)->sole();
        $this->assertSame('PENDING', $token->status);
        $this->assertTrue((bool) $token->active_marker);
    }

    public function test_it_creates_a_psychologist_with_silp_and_str(): void
    {
        $this->createCommand([
            '--operator' => 'ops-1',
            '--role' => 'psychologist',
            '--name' => 'Psikolog A',
            '--email' => 'psikolog@example.test',
            '--silp_number' => 'SILP-001',
            '--str_number' => 'STR-001',
        ])->assertExitCode(0);

        $admin = Admin::query()->sole();
        $this->assertSame(AdminRole::Psychologist, $admin->role);
        $this->assertSame('SILP-001', $admin->silp_number);
        $this->assertSame('STR-001', $admin->str_number);
        $this->assertNull($admin->branch_id);
    }

    public function test_it_rejects_a_branch_role_without_a_branch_id(): void
    {
        // staff requires a branch_id; not passed as an option, so the
        // command prompts for it interactively -- a blank answer must
        // still be rejected by CreateAdmin's own validation, not silently
        // accepted as "no branch."
        $this->createCommand([
            '--operator' => 'ops-1',
            '--role' => 'staff',
            '--name' => 'Staf', '--email' => 'staf@example.test',
        ])
            ->expectsQuestion('branch_id (wajib untuk branch_admin/staff)', '')
            ->assertExitCode(1);

        $this->assertDatabaseCount('admins', 0);
    }

    public function test_it_rejects_a_super_admin_with_a_branch_id(): void
    {
        $branch = Branch::query()->create([
            'code' => 'CENTRAL', 'ref_code' => 'CENTRAL-REF', 'name' => 'Central', 'is_default' => true,
        ]);

        $this->createCommand([
            '--operator' => 'ops-1',
            '--role' => 'super_admin',
            '--name' => 'Salah', '--email' => 'salah@example.test',
            '--branch_id' => (string) $branch->id,
        ])->assertExitCode(1);

        $this->assertDatabaseCount('admins', 0);
    }

    public function test_it_rejects_a_psychologist_without_silp_or_str(): void
    {
        $this->createCommand([
            '--operator' => 'ops-1',
            '--role' => 'psychologist',
            '--name' => 'Psikolog', '--email' => 'psikolog@example.test',
        ])
            ->expectsQuestion('Nomor SILP (wajib untuk psikolog)', '')
            ->expectsQuestion('Nomor STR (wajib untuk psikolog)', '')
            ->assertExitCode(1);

        $this->assertDatabaseCount('admins', 0);
    }

    public function test_it_rejects_a_non_psychologist_with_silp_or_str(): void
    {
        $this->createCommand([
            '--operator' => 'ops-1',
            '--role' => 'super_admin',
            '--name' => 'Salah', '--email' => 'salah@example.test',
            '--silp_number' => 'SILP-999',
        ])->assertExitCode(1);

        $this->assertDatabaseCount('admins', 0);
    }

    /** @param array<string, string> $params */
    private function createCommand(array $params): PendingCommand
    {
        $command = $this->artisan('admins:create', $params);
        if (is_int($command)) {
            $this->fail('The command did not return a test command wrapper.');
        }

        return $command;
    }
}
