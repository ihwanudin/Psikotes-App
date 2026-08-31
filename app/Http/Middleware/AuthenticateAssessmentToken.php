<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AssessmentParticipant;
use App\Models\Participant;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\ParticipantAuth\AssessmentAccessToken;
use App\Services\ParticipantAuth\AssessmentPrincipal;
use App\Services\ParticipantAuth\Exceptions\InvalidParticipantToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Not registered on production routes. Authentication is not entitlement authorization. */
final readonly class AuthenticateAssessmentToken
{
    public function __construct(private AssessmentAccessToken $tokens, private RlsContextRunner $runner) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->remove('assessment_principal');
        $request->attributes->remove('participant_principal');
        $request->setUserResolver(fn () => null);

        try {
            $header = $request->header('Authorization', '');
            if (count($request->headers->all('Authorization')) !== 1 || strlen($header) > 4103
                || preg_match('/\ABearer ([A-Za-z0-9+.\/=]+)\z/i', $header, $matches) !== 1) {
                throw new InvalidParticipantToken;
            }
            $principal = $this->tokens->verify($matches[1]);
            if (! $this->scopeExists($principal)) {
                throw new InvalidParticipantToken;
            }
        } catch (InvalidParticipantToken) {
            return response()->json(['error' => [
                'code' => 'INVALID_TOKEN',
                'message' => 'Token peserta tidak valid atau telah kedaluwarsa.',
            ]], 401, ['WWW-Authenticate' => 'Bearer', 'Cache-Control' => 'no-store']);
        }

        $request->attributes->set('assessment_principal', $principal);
        $request->setUserResolver(fn () => $principal);

        // Only the scoped lookup above runs as service, never arbitrary downstream handlers.
        return $next($request);
    }

    private function scopeExists(AssessmentPrincipal $principal): bool
    {
        return $this->runner->run(new RlsContext('service'), fn (): bool => Participant::query()
            ->whereKey($principal->participantId)->where('branch_id', $principal->organizationId)->exists()
            && AssessmentParticipant::query()->whereKey($principal->assessmentParticipantId)
                ->where('participant_id', $principal->participantId)->where('organization_id', $principal->organizationId)->exists());
    }
}
