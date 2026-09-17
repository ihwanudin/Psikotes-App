<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Contracts\RequiresRlsContext;
use App\Http\Middleware\ApplyRlsContext;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

final class RlsMiddlewareCoverageTest extends TestCase
{
    public function test_all_registered_tenant_controllers_use_rls_middleware(): void
    {
        foreach (RouteFacade::getRoutes() as $route) {
            $this->assertTenantRouteIsProtected($route);
        }
    }

    public function test_guard_detects_a_tenant_controller_without_rls_middleware(): void
    {
        $route = new Route(['GET'], '/unprotected-fixture', [
            'uses' => UnprotectedTenantControllerFixture::class,
        ]);

        $this->assertFalse($this->tenantRouteIsProtected($route));
    }

    private function assertTenantRouteIsProtected(Route $route): void
    {
        $controller = $route->getControllerClass();

        if ($controller === null || ! is_subclass_of($controller, RequiresRlsContext::class)) {
            return;
        }

        $this->assertTrue(
            $this->tenantRouteIsProtected($route),
            "Tenant route [{$route->uri()}] must use the rls middleware.",
        );
    }

    private function tenantRouteIsProtected(Route $route): bool
    {
        $controller = $route->getControllerClass();

        if ($controller === null || ! is_subclass_of($controller, RequiresRlsContext::class)) {
            return true;
        }

        $middleware = $route->gatherMiddleware();

        return in_array('rls', $middleware, true)
            || in_array(ApplyRlsContext::class, $middleware, true);
    }
}

final class UnprotectedTenantControllerFixture implements RequiresRlsContext
{
    public function __invoke(): void {}
}
