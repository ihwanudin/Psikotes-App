<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Http\Middleware\ApplyRlsContext;
use App\Models\Admin;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_panel_uses_admin_guard_and_applies_rls_after_authentication(): void
    {
        $panel = Filament::getPanel('admin');
        $middleware = $panel->getAuthMiddleware();

        $this->assertSame('admin', $panel->getAuthGuard());
        $this->assertContains(Authenticate::class, $middleware);
        $this->assertContains(ApplyRlsContext::class, $middleware);
        $this->assertLessThan(
            array_search(ApplyRlsContext::class, $middleware, true),
            array_search(Authenticate::class, $middleware, true),
        );
    }

    public function test_guest_is_redirected_to_panel_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_authenticated_admin_can_access_panel_through_rls_middleware(): void
    {
        $admin = Admin::query()->create([
            'name' => 'Central Admin',
            'email' => 'central@example.test',
            'password' => 'not-a-real-password',
            'role' => AdminRole::SuperAdmin,
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin')
            ->assertOk();
    }
}
