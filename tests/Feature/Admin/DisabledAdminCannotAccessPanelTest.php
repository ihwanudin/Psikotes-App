<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Admin account lifecycle (2026-09-22, Lead sign-off). Proves both halves
 * of "a disabled admin truly cannot log in": Admin::canAccessPanel()
 * (checked at login) and App\Http\Middleware\RejectDisabledAdmin (checked
 * on every subsequent authenticated request, since Laravel's database
 * session driver can't reliably identify which session rows belong to
 * which admin -- see that middleware's own doc comment).
 */
final class DisabledAdminCannotAccessPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_disabled_admin_cannot_authenticate_into_the_panel(): void
    {
        $admin = $this->admin();
        $admin->forceFill(['disabled_at' => now()])->save();

        $this->assertFalse($admin->canAccessPanel(filament()->getPanel('admin')));
    }

    public function test_an_active_admin_can_authenticate_into_the_panel(): void
    {
        $admin = $this->admin();

        $this->assertTrue($admin->canAccessPanel(filament()->getPanel('admin')));
    }

    /**
     * The scenario Lead specifically required a test for: an admin already
     * logged in (an active, valid session) who is THEN disabled -- the
     * very next authenticated request must be rejected, not merely the
     * next login attempt.
     */
    public function test_an_already_logged_in_admin_is_rejected_on_the_next_request_once_disabled(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->get('/admin')
            ->assertOk();

        $admin->forceFill(['disabled_at' => now()])->save();

        $this->actingAs($admin, 'admin')
            ->get('/admin')
            ->assertStatus(403);
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
}
