<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Contracts\RequiresRlsContext;
use App\Http\Controllers\StartParticipantSessionController;
use App\Http\Middleware\ApplyRlsContext;
use App\Models\Entitlement;
use App\Security\RlsContextRunner;
use App\Services\ParticipantAuth\AssessmentEntitlementGate;
use App\Services\ParticipantAuth\ParticipantEntitlementGate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as RouteFacade;
use ReflectionClass;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * F2 S5 (2026-09-21). ADR-0030's own "required proof before production wiring"
 * list names this explicitly: "architecture test membuktikan stack route start
 * yang tepat dan controller bebas dari DB/Eloquent/runner". Without this test,
 * a future PR that innocently adds `rls` back to the start route, or a
 * DB/Eloquent/RlsContextRunner/entitlement-gate dependency to the controller,
 * would recreate the exact stacked-context boundary that made the whole
 * session-start command family unable to run a single query in real
 * PostgreSQL (S4, 2026-09-21) -- and it would pass on SQLite, invisibly,
 * exactly like that bug did.
 */
final class AssessmentSessionStartBoundaryTest extends TestCase
{
    /** @var list<class-string> */
    private const FORBIDDEN_DEPENDENCY_TYPES = [
        RlsContextRunner::class,
        ParticipantEntitlementGate::class,
        AssessmentEntitlementGate::class,
        Entitlement::class,
        Model::class,
    ];

    public function test_start_route_uses_participant_jwt_without_rls(): void
    {
        $route = collect(RouteFacade::getRoutes())
            ->first(fn ($route): bool => $route->getName() === 'participant.sessions.start');

        $this->assertNotNull($route, 'The participant session start route must be registered.');
        $middleware = $route->gatherMiddleware();
        $this->assertContains('participant.jwt', $middleware);
        $this->assertNotContains('rls', $middleware);
        $this->assertNotContains(ApplyRlsContext::class, $middleware);
    }

    public function test_controller_does_not_implement_requires_rls_context(): void
    {
        $this->assertFalse(
            is_subclass_of(StartParticipantSessionController::class, RequiresRlsContext::class),
            'StartParticipantSessionController must stay outside the rls context boundary -- the command it '
            .'calls owns its own service transaction and rejects any pre-existing context before touching SQL.',
        );
    }

    public function test_controller_invoke_takes_no_db_eloquent_runner_or_gate_dependency(): void
    {
        $reflection = new ReflectionClass(StartParticipantSessionController::class);
        $this->assertFalse($reflection->hasMethod('__construct'), 'The controller must stay stateless: no constructor-injected dependencies.');
        $invoke = $reflection->getMethod('__invoke');

        foreach ($invoke->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }
            $typeName = $type->getName();
            foreach (self::FORBIDDEN_DEPENDENCY_TYPES as $forbidden) {
                $this->assertFalse(
                    $typeName === $forbidden || is_subclass_of($typeName, $forbidden),
                    "StartParticipantSessionController::__invoke must not depend on {$typeName} "
                    ."(forbidden: {$forbidden}). Adding it re-creates the stacked-context boundary ADR-0030 forbids.",
                );
            }
        }
    }

    public function test_controller_source_never_references_the_db_facade_or_run_as_service(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(StartParticipantSessionController::class))->getFileName());

        $this->assertStringNotContainsString(DB::class, $source);
        $this->assertStringNotContainsString('runAsService', $source);
    }
}
