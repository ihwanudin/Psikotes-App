<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AssessmentSessions\GetAssessmentSessionAnswers;
use App\Services\ParticipantAuth\ParticipantPrincipal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Throwable;

/**
 * F2 session-answers-readback (2026-09-21). GET /sessions/{id}/answers.
 * Same sealed boundary as the other four session-http controllers -- no
 * DB/Eloquent/RlsContextRunner dependency, GetAssessmentSessionAnswers owns
 * its own service-context elevation. See
 * tests/Architecture/AssessmentSessionHttpBoundaryTest.php.
 *
 * Error-code -> HTTP status: SESSION_NOT_FOUND 404 (nonexistent and
 * foreign-owned are identical here, same WHERE clause as every other
 * session action); SESSION_NOT_STARTED/SESSION_CLOSED/DEADLINE_EXCEEDED
 * 409 (readable exactly when writable -- these are the same three states
 * that make autosave itself unwritable); anything else 500, reported.
 *
 * Response is a strict whitelist: session_id, answers_revision, and
 * answers as {item_no, value} pairs only -- no status, no answer keys, no
 * scores, no per-item correctness. There is nothing else to leak: the
 * `answers` table has no columns for any of those.
 */
final class GetAssessmentSessionAnswersController extends Controller
{
    private const REJECTION_STATUS = [
        'SESSION_NOT_FOUND' => 404,
        'SESSION_NOT_STARTED' => 409,
        'SESSION_CLOSED' => 409,
        'DEADLINE_EXCEEDED' => 409,
    ];

    public function __invoke(
        Request $request,
        string $id,
        GetAssessmentSessionAnswers $action,
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
                    'answers_revision' => $result->answersRevision,
                    'answers' => $result->answers,
                ]);
            }

            $status = self::REJECTION_STATUS[$result->errorCode ?? ''] ?? null;
            if ($status === null) {
                report(new RuntimeException("Unmapped assessment session answers error code: {$result->errorCode}"));

                return $this->error(500, 'ASSESSMENT_SESSION_ANSWERS_READ_FAILED', 'Terjadi kesalahan internal. Coba lagi nanti.');
            }

            return $this->error($status, $result->errorCode, $this->message($result->errorCode));
        } catch (Throwable $exception) {
            report($exception);

            return $this->error(500, 'ASSESSMENT_SESSION_ANSWERS_READ_FAILED', 'Terjadi kesalahan internal. Coba lagi nanti.');
        }
    }

    private function message(?string $errorCode): string
    {
        return match ($errorCode) {
            'SESSION_NOT_FOUND' => 'Sesi tidak ditemukan.',
            'SESSION_NOT_STARTED' => 'Sesi belum dimulai.',
            'SESSION_CLOSED' => 'Sesi sudah ditutup.',
            'DEADLINE_EXCEEDED' => 'Waktu pengerjaan sudah habis.',
            default => 'Jawaban tidak dapat dibaca.',
        };
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message, 'details' => null]], $status);
    }
}
