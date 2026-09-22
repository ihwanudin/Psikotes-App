<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AssessmentSessions\SubtestNext;
use App\Services\ParticipantAuth\ParticipantPrincipal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Throwable;

/**
 * F2 timed-segments stage 4 (2026-09-22). POST /sessions/:id/subtest/next.
 * Same sealed boundary as the other session-http controllers -- no DB/
 * Eloquent/RlsContextRunner dependency, SubtestNext owns its own service-
 * context elevation and transaction. See
 * tests/Architecture/AssessmentSessionHttpBoundaryTest.php.
 *
 * Error-code -> HTTP status: SESSION_NOT_FOUND 404; SESSION_NOT_STARTED/
 * SESSION_CLOSED/DEADLINE_EXCEEDED 409 (conflict against current session
 * state, same family as autosave/items); INVALID_SESSION_TRANSITION 422
 * (the request itself is not a legal transition from the current segment
 * state -- either already mid-window with early finish not allowed, or
 * nothing left to act on); anything else 500, reported.
 */
final class SubtestNextController extends Controller
{
    private const CONFLICT_CODES = ['SESSION_NOT_STARTED', 'SESSION_CLOSED', 'DEADLINE_EXCEEDED'];

    public function __invoke(Request $request, string $id, SubtestNext $action): JsonResponse
    {
        $principal = $request->attributes->get('participant_principal');
        if (! $principal instanceof ParticipantPrincipal) {
            throw new UnauthorizedHttpException('Bearer');
        }

        try {
            $result = $action->execute($principal->participantId, $id);

            if ($result->accepted) {
                return response()->json([
                    'session_id' => $id,
                    'current_segment' => [
                        'index' => $result->segmentIndex,
                        'became_current_at' => $result->becameCurrentAt,
                        'started_at' => $result->startedAt,
                    ],
                ]);
            }

            return $this->rejected($result->errorCode);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error(500, 'SUBTEST_NEXT_FAILED', 'Terjadi kesalahan internal. Coba lagi nanti.');
        }
    }

    private function rejected(?string $errorCode): JsonResponse
    {
        if ($errorCode === 'SESSION_NOT_FOUND') {
            return $this->error(404, $errorCode, 'Sesi tidak ditemukan.');
        }

        if ($errorCode === 'INVALID_SESSION_TRANSITION') {
            return $this->error(422, $errorCode, 'Permintaan tidak dapat dilakukan pada status segmen saat ini.');
        }

        if (in_array($errorCode, self::CONFLICT_CODES, true)) {
            return $this->error(409, $errorCode, 'Permintaan ditolak karena status sesi saat ini.');
        }

        report(new RuntimeException("Unmapped subtest/next error code: {$errorCode}"));

        return $this->error(500, 'SUBTEST_NEXT_FAILED', 'Terjadi kesalahan internal. Coba lagi nanti.');
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message, 'details' => null]], $status);
    }
}
