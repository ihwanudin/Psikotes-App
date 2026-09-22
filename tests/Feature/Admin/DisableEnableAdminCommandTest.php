<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

final class DisableEnableAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_disable_sets_disabled_at_and_writes_an_audit_row(): void
    {
        $admin = $this->admin();

        $this->command('admins:disable', ['id' => $admin->id, '--operator' => 'ops-1'])->assertExitCode(0);

        $this->assertNotNull($admin->refresh()->disabled_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.disabled', 'actor_id' => 'ops-1', 'subject_id' => (string) $admin->id,
        ]);
    }

    public function test_disable_refuses_an_already_disabled_admin(): void
    {
        $admin = $this->admin();
        $admin->forceFill(['disabled_at' => now()])->save();

        $this->command('admins:disable', ['id' => $admin->id, '--operator' => 'ops-1'])->assertExitCode(1);
    }

    public function test_enable_clears_disabled_at_and_writes_an_audit_row(): void
    {
        $admin = $this->admin();
        $admin->forceFill(['disabled_at' => now()])->save();

        $this->command('admins:enable', ['id' => $admin->id, '--operator' => 'ops-1'])->assertExitCode(0);

        $this->assertNull($admin->refresh()->disabled_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.enabled', 'actor_id' => 'ops-1', 'subject_id' => (string) $admin->id,
        ]);
    }

    public function test_enable_refuses_an_already_active_admin(): void
    {
        $admin = $this->admin();

        $this->command('admins:enable', ['id' => $admin->id, '--operator' => 'ops-1'])->assertExitCode(1);
    }

    private function admin(): Admin
    {
        return Admin::query()->create([
            'branch_id' => null,
            'name' => 'Admin', 'email' => 'admin-'.uniqid('', true).'@example.test',
            'password' => Hash::make('irrelevant-existing-password'),
            'role' => AdminRole::SuperAdmin,
        ]);
    }

    /** @param array<string, int|string> $params */
    private function command(string $name, array $params): PendingCommand
    {
        $command = $this->artisan($name, $params);
        if (is_int($command)) {
            $this->fail('The command did not return a test command wrapper.');
        }

        return $command;
    }
}
