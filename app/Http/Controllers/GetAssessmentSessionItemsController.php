<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AssessmentSessions\GetAssessmentSessionItems;
use App\Services\ParticipantAuth\ParticipantPrincipal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Throwable;

/**
 * F2 item-delivery (2026-09-21). GET /sessions/{id}/items. Same sealed
 * boundary as the other session-http controllers -- no DB/Eloquent/
 * RlsContextRunner dependency, GetAssessmentSessionItems owns its own
 * service-context elevation. See
 * tests/Architecture/AssessmentSessionHttpBoundaryTest.php.
 *
 * Error-code -> HTTP status: SESSION_NOT_FOUND 404; SESSION_NOT_STARTED/
 * SESSION_CLOSED/DEADLINE_EXCEEDED 409 (readable exactly when writable,
 * same as GET /sessions/{id}/answers); ASSESSMENT_ITEM_CONTENT_UNAVAILABLE
 * 503 (no reader registered, or a registered reader could not produce
 * content -- same code the start gate uses, since it's the same
 * underlying condition); anything else 500, reported.
 *
 * Response is a pass-through of AssessmentItemContent: session_id,
 * instrument, version, subtests, and instructions as returned by the
 * authority. Field-level whitelisting of what belongs inside each item (or
 * inside instructions) happens inside the per-instrument reader that
 * produced the content, not here -- this controller has no
 * instrument-specific knowledge to filter with. `instructions` is `null`
 * for instruments/readers that don't have any -- always present in the
 * response shape, never an omitted key, so the shape is stable across
 * instruments.
 */
final class GetAssessmentSessionItemsController extends Controller
{
    private const REJECTION_STATUS = [
        'SESSION_NOT_FOUND' => 404,
        'SESSION_NOT_STARTED' => 409,
        'SESSION_CLOSED' => 409,
        'DEADLINE_EXCEEDED' => 409,
        'ASSESSMENT_ITEM_CONTENT_UNAVAILABLE' => 503,
    ];

    public function __invoke(
        Request $request,
        string $id,
        GetAssessmentSessionItems $action,
    ): JsonResponse {
        $principal = $request->attributes->get('participant_principal');
        if (! $principal instanceof ParticipantPrincipal) {
            throw new UnauthorizedHttpException('Bearer');
        }

        try {
            $result = $action->execute($principal->participantId, $id);

            if ($result->accepted && $result->content !== null) {
                return response()->json([
                    'session_id' => $result->sessionId,
                    'instrument' => $result->content->instrument->value,
                    'version' => $result->content->version,
                    'subtests' => $result->content->subtests,
                    'instructions' => $result->content->instructions,
                ]);
            }

            $status = self::REJECTION_STATUS[$result->errorCode ?? ''] ?? null;
            if ($status === null) {
                report(new RuntimeException("Unmapped assessment session items error code: {$result->errorCode}"));

                return $this->error(500, 'ASSESSMENT_SESSION_ITEMS_READ_FAILED', 'Terjadi kesalahan internal. Coba lagi nanti.');
            }

            return $this->error($status, $result->errorCode, $this->message($result->errorCode));
        } catch (Throwable $exception) {
            report($exception);

            return $this->error(500, 'ASSESSMENT_SESSION_ITEMS_READ_FAILED', 'Terjadi kesalahan internal. Coba lagi nanti.');
        }
    }

    private function message(?string $errorCode): string
    {
        return match ($errorCode) {
            'SESSION_NOT_FOUND' => 'Sesi tidak ditemukan.',
            'SESSION_NOT_STARTED' => 'Sesi belum dimulai.',
            'SESSION_CLOSED' => 'Sesi sudah ditutup.',
            'DEADLINE_EXCEEDED' => 'Waktu pengerjaan sudah habis.',
            'ASSESSMENT_ITEM_CONTENT_UNAVAILABLE' => 'Isi soal belum tersedia. Coba lagi nanti.',
            default => 'Isi soal tidak dapat dibaca.',
        };
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message, 'details' => null]], $status);
    }
}
