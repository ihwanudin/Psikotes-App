<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AssessmentSessions\SubmitAssessmentSession;
use App\Services\ParticipantAuth\ParticipantPrincipal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Throwable;

/**
 * F2 session-http (2026-09-21). POST /sessions/{id}/submit. Same sealed
 * boundary as the other two session-http controllers -- no DB/Eloquent/
 * RlsContextRunner dependency, SubmitAssessmentSession owns its own
 * service-context elevation and transaction. See
 * tests/Architecture/AssessmentSessionHttpBoundaryTest.php.
 *
 * Submit/replay both return the same stable
 * {session_id,status:'scored'... -- actually 'submitted', see below}
 * shape per API_CONTRACT.md: repeated submit is safe and does not reopen
 * answers (AssessmentSessionSubmitPolicy's replay branch). The contract's
 * literal example shows status:'scored', but scoring is a separate,
 * later pipeline (F2's G7 lane) that this action does not run --
 * AssessmentSessionSubmitPolicy only ever transitions
 * InProgress -> Submitted. This response reports the action's real
 * post-state ('submitted') rather than fabricating 'scored'; documented
 * as a contract-wording note in the handoff, not silently resolved.
 *
 * Same error-code -> HTTP status mapping as autosave for the codes this
 * action can produce (SESSION_NOT_FOUND: 404; SESSION_NOT_STARTED,
 * SESSION_CLOSED, DEADLINE_EXCEEDED: 409; anything else: 500, reported).
 */
final class SubmitAssessmentSessionController extends Controller
{
    private const CONFLICT_CODES = ['SESSION_NOT_STARTED', 'SESSION_CLOSED', 'DEADLINE_EXCEEDED'];

    public function __invoke(
        Request $request,
        string $id,
        SubmitAssessmentSession $action,
    ): JsonResponse {
        $principal = $request->attributes->get('participant_principal');
        if (! $principal instanceof ParticipantPrincipal) {
            throw new UnauthorizedHttpException('Bearer');
        }

        try {
            $result = $action->execute($principal->participantId, $id);

            if ($result->accepted) {
                return response()->json([
                    'session_id' => $result->sessionId,
                    'status' => $result->status,
                    'submitted_at' => $result->submittedAt?->format('Y-m-d\TH:i:s.up'),
                    'answers_revision' => $result->answersRevision,
                ]);
            }

            return $this->rejected($result->errorCode);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error(500, 'ASSESSMENT_SUBMIT_FAILED', 'Terjadi kesalahan internal. Coba lagi nanti.');
        }
    }

    private function rejected(?string $errorCode): JsonResponse
    {
        if ($errorCode === 'SESSION_NOT_FOUND') {
            return $this->error(404, $errorCode, 'Sesi tidak ditemukan.');
        }

        if (in_array($errorCode, self::CONFLICT_CODES, true)) {
            return $this->error(409, $errorCode, 'Submit ditolak karena status sesi saat ini.');
        }

        report(new RuntimeException("Unmapped assessment submit error code: {$errorCode}"));

        return $this->error(500, 'ASSESSMENT_SUBMIT_FAILED', 'Terjadi kesalahan internal. Coba lagi nanti.');
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message, 'details' => null]], $status);
    }
}
