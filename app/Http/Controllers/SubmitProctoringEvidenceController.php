<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Proctoring\ProctoringSubmissionResult;
use App\Actions\Proctoring\RecordProctoringEvent;
use App\Actions\Proctoring\RecordProctoringPhoto;
use App\Http\Requests\SubmitProctoringEvidenceRequest;
use App\Services\ParticipantAuth\ParticipantPrincipal;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Throwable;

/**
 * F7 (2026-09-24). `POST /sessions/:id/proctor` (`API_CONTRACT.md`: "foto
 * multipart | log event"). Same sealed boundary as
 * `AutosaveAssessmentAnswersController`: no DB/RlsContextRunner dependency
 * here, each action owns its own service-context elevation and
 * transaction.
 *
 * Error-code -> HTTP status mapping, same reasoning as
 * `AutosaveAssessmentAnswersController`:
 * - SESSION_NOT_FOUND: 404 (nonexistent and foreign-owned are identical).
 * - SESSION_CLOSED, PROCTORING_SEQUENCE_CONFLICT, PROCTORING_EVIDENCE_MISMATCH:
 *   409 -- lifecycle/optimistic-concurrency conflicts against current state,
 *   not malformed input.
 * - INVALID_PROCTORING_SUBMISSION: 422 -- domain-level shape rejection
 *   (e.g. `ProctoringEvent`'s own evidence_id/kind-vs-source validation).
 * - Anything else (including any untyped Throwable): 500, reported.
 */
final class SubmitProctoringEvidenceController extends Controller
{
    private const CONFLICT_CODES = ['SESSION_CLOSED', 'PROCTORING_SEQUENCE_CONFLICT', 'PROCTORING_EVIDENCE_MISMATCH'];

    public function __invoke(
        SubmitProctoringEvidenceRequest $request,
        string $id,
        RecordProctoringEvent $recordEvent,
        RecordProctoringPhoto $recordPhoto,
    ): JsonResponse {
        $principal = $request->attributes->get('participant_principal');
        if (! $principal instanceof ParticipantPrincipal) {
            throw new UnauthorizedHttpException('Bearer');
        }

        try {
            $result = $request->isPhotoSubmission()
                ? $recordPhoto->execute(
                    $principal->participantId,
                    $id,
                    $request->file('photo'),
                    (string) $request->validated('capture_kind'),
                    (int) $request->validated('sequence'),
                    (string) $request->validated('client_event_id'),
                    $request->validated('captured_at') === null ? null : new DateTimeImmutable((string) $request->validated('captured_at')),
                )
                : $recordEvent->execute(
                    $principal->participantId,
                    $id,
                    (string) $request->validated('event_kind'),
                    (string) $request->validated('evidence_source'),
                    (string) $request->validated('evidence_id'),
                    new DateTimeImmutable((string) $request->validated('occurred_at')),
                    $request->validated('client_event_id') === null ? null : (string) $request->validated('client_event_id'),
                    $request->validated('duration_ms') === null ? null : (int) $request->validated('duration_ms'),
                    $request->validated('metadata'),
                );

            if ($result->accepted) {
                return response()->json([
                    'session_id' => $id,
                    'public_id' => $result->publicId,
                    'replayed' => $result->replayed,
                ]);
            }

            return $this->rejected($result);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error(500, 'PROCTORING_SUBMISSION_FAILED', 'Terjadi kesalahan internal. Coba lagi nanti.');
        }
    }

    private function rejected(ProctoringSubmissionResult $result): JsonResponse
    {
        $errorCode = $result->errorCode;

        if ($errorCode === 'SESSION_NOT_FOUND') {
            return $this->error(404, $errorCode, 'Sesi tidak ditemukan.');
        }

        if ($errorCode === 'INVALID_PROCTORING_SUBMISSION') {
            return $this->error(422, $errorCode, 'Bukti proctoring tidak valid.');
        }

        if (in_array($errorCode, self::CONFLICT_CODES, true)) {
            return $this->error(409, $errorCode, 'Permintaan ditolak karena status sesi atau bukti saat ini.');
        }

        report(new RuntimeException("Unmapped proctoring submission error code: {$errorCode}"));

        return $this->error(500, 'PROCTORING_SUBMISSION_FAILED', 'Terjadi kesalahan internal. Coba lagi nanti.');
    }

    /** @param array<string, mixed>|null $details */
    private function error(int $status, string $code, string $message, ?array $details = null): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message, 'details' => $details]], $status);
    }
}
