<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Participant;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\ParticipantAuth\Exceptions\InvalidParticipantToken;
use App\Services\ParticipantAuth\ParticipantJwt;
use App\Services\ParticipantAuth\ParticipantPrincipal;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class AuthenticateParticipantJwt
{
    public function __construct(
        private ParticipantJwt $jwt,
        private RlsContextRunner $runner,
    ) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $principal = $this->jwt->verify((string) $request->bearerToken());

            if (! $this->participantExists($principal)) {
                throw new InvalidParticipantToken;
            }
        } catch (InvalidParticipantToken) {
            return $this->unauthorized();
        }

        $request->setUserResolver(
            fn (?string $guard = null): ParticipantPrincipal => $principal,
        );
        $request->attributes->set('participant_principal', $principal);

        return $next($request);
    }

    private function participantExists(ParticipantPrincipal $principal): bool
    {
        return $this->runner->run(
            new RlsContext('service'),
            fn (): bool => Participant::query()
                ->whereKey($principal->participantId)
                ->where('branch_id', $principal->branchId)
                ->exists(),
        );
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'INVALID_TOKEN',
                'message' => 'Token peserta tidak valid atau telah kedaluwarsa.',
            ],
        ], 401);
    }
}
