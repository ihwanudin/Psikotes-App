<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

final class BootstrapSuperAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // AdminPasswordPolicy::rule()'s uncompromised() calls the real HIBP
        // API over HTTPS (Illuminate\Validation\NotPwnedVerifier) -- faked
        // here so these tests never depend on network reachability. An
        // empty body means "no breach match for this range," i.e. every
        // password below is treated as not compromised.
        Http::fake(['https://api.pwnedpasswords.com/*' => Http::response('', 200)]);
    }

    public function test_it_creates_the_first_super_admin_and_writes_an_audit_row(): void
    {
        $this->bootstrapCommand([
            '--operator' => 'deploy-operator',
            '--name' => 'Kepala Admin',
            '--email' => 'kepala@example.test',
        ])
            ->expectsQuestion('Kata sandi (minimal 12 karakter, huruf besar/kecil, angka, simbol)', 'Str0ngPassw0rd!Z')
            ->expectsQuestion('Ulangi kata sandi', 'Str0ngPassw0rd!Z')
            ->assertExitCode(0);

        $admin = Admin::query()->sole();
        $this->assertSame(AdminRole::SuperAdmin, $admin->role);
        $this->assertNull($admin->branch_id);
        $this->assertNull($admin->disabled_at);
        $this->assertTrue(Hash::check('Str0ngPassw0rd!Z', $admin->password));

        $this->assertDatabaseHas('audit_logs', [
            'actor_type' => 'operator',
            'actor_id' => 'deploy-operator',
            'action' => 'admin.bootstrapped',
            'subject_type' => Admin::class,
            'subject_id' => (string) $admin->id,
        ]);
    }

    public function test_it_refuses_to_run_a_second_time(): void
    {
        Admin::query()->create([
            'branch_id' => null,
            'name' => 'Existing', 'email' => 'existing@example.test',
            'password' => Hash::make('irrelevant-existing-password'),
            'role' => AdminRole::SuperAdmin,
        ]);

        $this->bootstrapCommand([
            '--operator' => 'deploy-operator',
            '--name' => 'Second', '--email' => 'second@example.test',
        ])
            ->expectsQuestion('Kata sandi (minimal 12 karakter, huruf besar/kecil, angka, simbol)', 'Str0ngPassw0rd!Z')
            ->expectsQuestion('Ulangi kata sandi', 'Str0ngPassw0rd!Z')
            ->assertExitCode(1);

        $this->assertSame(1, Admin::query()->count());
    }

    public function test_it_refuses_when_the_only_existing_super_admin_is_disabled(): void
    {
        $admin = Admin::query()->create([
            'branch_id' => null,
            'name' => 'Disabled', 'email' => 'disabled@example.test',
            'password' => Hash::make('irrelevant-existing-password'),
            'role' => AdminRole::SuperAdmin,
        ]);
        $admin->forceFill(['disabled_at' => now()])->save();

        $this->bootstrapCommand([
            '--operator' => 'deploy-operator',
            '--name' => 'Second', '--email' => 'second@example.test',
        ])
            ->expectsQuestion('Kata sandi (minimal 12 karakter, huruf besar/kecil, angka, simbol)', 'Str0ngPassw0rd!Z')
            ->expectsQuestion('Ulangi kata sandi', 'Str0ngPassw0rd!Z')
            ->assertExitCode(1);

        $this->assertSame(1, Admin::query()->count());
    }

    public function test_it_rejects_a_weak_password(): void
    {
        $this->bootstrapCommand([
            '--operator' => 'deploy-operator',
            '--name' => 'Kepala Admin', '--email' => 'kepala@example.test',
        ])
            ->expectsQuestion('Kata sandi (minimal 12 karakter, huruf besar/kecil, angka, simbol)', 'password')
            ->expectsQuestion('Ulangi kata sandi', 'password')
            ->assertExitCode(1);

        $this->assertDatabaseCount('admins', 0);
    }

    public function test_the_password_never_appears_in_command_output(): void
    {
        $this->bootstrapCommand([
            '--operator' => 'deploy-operator',
            '--name' => 'Kepala Admin',
            '--email' => 'kepala@example.test',
        ])
            ->expectsQuestion('Kata sandi (minimal 12 karakter, huruf besar/kecil, angka, simbol)', 'Str0ngPassw0rd!Z')
            ->expectsQuestion('Ulangi kata sandi', 'Str0ngPassw0rd!Z')
            ->doesntExpectOutputToContain('Str0ngPassw0rd!Z')
            ->assertExitCode(0);
    }

    /** @param array<string, string> $params */
    private function bootstrapCommand(array $params): PendingCommand
    {
        $command = $this->artisan('admins:bootstrap-super-admin', $params);
        if (is_int($command)) {
            $this->fail('The command did not return a test command wrapper.');
        }

        return $command;
    }
}
