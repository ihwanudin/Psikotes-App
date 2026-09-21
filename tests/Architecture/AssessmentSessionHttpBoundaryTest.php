<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Contracts\RequiresRlsContext;
use App\Http\Controllers\AutosaveAssessmentAnswersController;
use App\Http\Controllers\GetAssessmentSessionAnswersController;
use App\Http\Controllers\GetAssessmentSessionController;
use App\Http\Controllers\GetAssessmentSessionItemsController;
use App\Http\Controllers\SubmitAssessmentSessionController;
use App\Http\Middleware\ApplyRlsContext;
use App\Models\Entitlement;
use App\Security\RlsContextRunner;
use App\Services\ParticipantAuth\AssessmentEntitlementGate;
use App\Services\ParticipantAuth\ParticipantEntitlementGate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * F2 session-http (2026-09-21), extended F2 session-answers-readback
 * (2026-09-21). Same proof obligation as AssessmentSessionStartBoundaryTest,
 * covering resume/autosave/submit/answers-readback: each of
 * GetAssessmentSession, AutosaveAssessmentAnswers, SubmitAssessmentSession,
 * GetAssessmentSessionAnswers owns its own runAsService() call directly (no
 * assertCleanOuterBoundary() -- see each action's doc comment), and
 * RlsContextRunner::runAsService() itself throws
 * ("Only administrator contexts may elevate to service.") if a participant
 * RLS context is already active. A stray `rls` middleware entry or a
 * DB/Eloquent/RlsContextRunner dependency smuggled into any of these four
 * controllers would recreate that stacked-context failure -- and, per the
 * S4 lesson, it would pass silently on SQLite and only fail against real
 * PostgreSQL.
 */
final class AssessmentSessionHttpBoundaryTest extends TestCase
{
    /** @var list<class-string> */
    private const FORBIDDEN_DEPENDENCY_TYPES = [
        RlsContextRunner::class,
        ParticipantEntitlementGate::class,
        AssessmentEntitlementGate::class,
        Entitlement::class,
        Model::class,
    ];

    /** @return iterable<string, array{string, class-string}> */
    public static function routes(): iterable
    {
        yield 'GET /sessions/{id}' => ['participant.sessions.show', GetAssessmentSessionController::class];
        yield 'POST /sessions/{id}/answers' => ['participant.sessions.answers', AutosaveAssessmentAnswersController::class];
        yield 'POST /sessions/{id}/submit' => ['participant.sessions.submit', SubmitAssessmentSessionController::class];
        yield 'GET /sessions/{id}/answers' => ['participant.sessions.answers.show', GetAssessmentSessionAnswersController::class];
        yield 'GET /sessions/{id}/items' => ['participant.sessions.items.show', GetAssessmentSessionItemsController::class];
    }

    #[DataProvider('routes')]
    public function test_route_uses_participant_jwt_without_rls(string $routeName, string $controller): void
    {
        $route = collect(RouteFacade::getRoutes())
            ->first(fn ($route): bool => $route->getName() === $routeName);

        $this->assertNotNull($route, "The {$routeName} route must be registered.");
        $middleware = $route->gatherMiddleware();
        $this->assertContains('participant.jwt', $middleware);
        $this->assertNotContains('rls', $middleware);
        $this->assertNotContains(ApplyRlsContext::class, $middleware);
    }

    #[DataProvider('routes')]
    public function test_controller_does_not_implement_requires_rls_context(string $routeName, string $controller): void
    {
        $this->assertFalse(
            is_subclass_of($controller, RequiresRlsContext::class),
            "{$controller} must stay outside the rls context boundary -- the action it calls owns its own "
            .'service transaction and would trip RlsContextRunner\'s reentrancy guard if one were pre-established.',
        );
    }

    #[DataProvider('routes')]
    public function test_controller_invoke_takes_no_db_eloquent_runner_or_gate_dependency(string $routeName, string $controller): void
    {
        $reflection = new ReflectionClass($controller);
        $this->assertFalse($reflection->hasMethod('__construct'), "{$controller} must stay stateless: no constructor-injected dependencies.");
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
                    "{$controller}::__invoke must not depend on {$typeName} (forbidden: {$forbidden}). "
                    .'Adding it re-creates the stacked-context boundary this test guards against.',
                );
            }
        }
    }

    #[DataProvider('routes')]
    public function test_controller_source_never_references_the_db_facade_or_run_as_service(string $routeName, string $controller): void
    {
        $source = (string) file_get_contents((new ReflectionClass($controller))->getFileName());

        $this->assertStringNotContainsString(DB::class, $source);
        $this->assertStringNotContainsString('runAsService', $source);
    }
}
