<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Contracts\ProvidesRlsContext;
use App\Contracts\RunsRlsContext;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ApplyRlsContext
{
    public function __construct(private RunsRlsContext $runner) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $principal = $request->user();

        if (! $principal instanceof ProvidesRlsContext) {
            throw new AuthorizationException('A trusted RLS context is required.');
        }

        return $this->runner->run(
            $principal->rlsContext(),
            fn (): Response => $next($request),
        );
    }
}
