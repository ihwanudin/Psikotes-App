<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Contracts\ProvidesRlsContext;
use App\Http\Middleware\ApplyRlsContext;
use App\Models\User;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class ApplyRlsContextMiddlewareTest extends TestCase
{
    public function test_principal_without_internal_context_contract_is_rejected(): void
    {
        $request = Request::create('/tenant-data');
        $request->setUserResolver(static fn (): User => new User);

        $this->expectException(AuthorizationException::class);

        $this->app->make(ApplyRlsContext::class)->handle(
            $request,
            static fn (): Response => new Response,
        );
    }

    public function test_context_is_active_only_while_downstream_request_runs(): void
    {
        $runner = $this->app->make(RlsContextRunner::class);
        $middleware = new ApplyRlsContext($runner);
        $principal = new class extends User implements ProvidesRlsContext
        {
            public function rlsContext(): RlsContext
            {
                return new RlsContext('branch_admin', 17);
            }
        };
        $request = Request::create('/tenant-data');
        $request->setUserResolver(static fn (): User => $principal);

        $response = $middleware->handle($request, function () use ($runner): Response {
            $this->assertSame(17, $runner->current()?->branchId);

            return new Response('ok');
        });

        $this->assertSame('ok', $response->getContent());
        $this->assertNull($runner->current());
    }
}
