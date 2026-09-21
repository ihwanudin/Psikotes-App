<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AssessmentSessions\GetAssessmentSession;
use App\Http\Resources\AssessmentSessionResource;
use App\Services\ParticipantAuth\ParticipantPrincipal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Throwable;

/**
 * F2 session-http (2026-09-21). GET /sessions/{id} (resume). Same sealed
 * boundary as StartParticipantSessionController: this class must never
 * gain a DB, Eloquent, or RlsContextRunner dependency of its own --
 * GetAssessmentSession owns its own service-context elevation and a
 * stacked context here would trip RlsContextRunner's reentrancy guard,
 * exactly like the start route. See
 * tests/Architecture/AssessmentSessionHttpBoundaryTest.php.
 *
 * A nonexistent session and a session owned by a different participant
 * both produce GetAssessmentSessionResult::found === false, so both take
 * this same 404 branch with an identical body -- there is no separate
 * "forbidden" branch to accidentally diverge from it.
 */
final class GetAssessmentSessionController extends Controller
{
    public function __invoke(
        Request $request,
        string $id,
        GetAssessmentSession $action,
    ): JsonResponse {
        $principal = $request->attributes->get('participant_principal');
        if (! $principal instanceof ParticipantPrincipal) {
            throw new UnauthorizedHttpException('Bearer');
        }

        try {
            $result = $action->execute($principal->participantId, $id);

            if (! $result->found || $result->snapshot === null) {
                return $this->error(404, 'SESSION_NOT_FOUND', 'Sesi tidak ditemukan.');
            }

            return response()->json((new AssessmentSessionResource($result->snapshot))->resolve());
        } catch (Throwable $exception) {
            report($exception);

            return $this->error(500, 'ASSESSMENT_SESSION_READ_FAILED', 'Terjadi kesalahan internal. Coba lagi nanti.');
        }
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message, 'details' => null]], $status);
    }
}
