<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AssessmentSessions\AutosaveAssessmentAnswers;
use App\Http\Requests\AutosaveAssessmentAnswersRequest;
use App\Services\ParticipantAuth\ParticipantPrincipal;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Throwable;

/**
 * F2 session-http (2026-09-21). POST /sessions/{id}/answers. Same sealed
 * boundary as StartParticipantSessionController/GetAssessmentSessionController:
 * no DB/Eloquent/RlsContextRunner dependency here -- AutosaveAssessmentAnswers
 * owns its own service-context elevation and transaction. See
 * tests/Architecture/AssessmentSessionHttpBoundaryTest.php.
 *
 * Error-code -> HTTP status mapping (Correction A: no string matching on the
 * message, no 4xx for an untyped/invariant failure -- those fall to the
 * generic 500 branch below):
 * - SESSION_NOT_FOUND: 404 (nonexistent and foreign-owned are identical here,
 *   see AutosaveAssessmentAnswers -- same WHERE clause, same empty result).
 * - SESSION_NOT_STARTED, SESSION_CLOSED, DEADLINE_EXCEEDED,
 *   AUTOSAVE_STALE_REVISION, AUTOSAVE_REVISION_GAP, MUTATION_PAYLOAD_MISMATCH:
 *   409 -- all are optimistic-concurrency/lifecycle conflicts against the
 *   session's current state, not malformed input.
 * - INVALID_ANSWER_BATCH: 422 -- request-shape/domain-batch rejection.
 * - Anything else (including the structurally-unreachable
 *   INVALID_SESSION_TRANSITION and any untyped Throwable): 500, reported.
 *
 * `details` is populated only for AUTOSAVE_REVISION_GAP, and only with values
 * that come from the participant's own session/request (never raw client
 * `value`), per F2's plan approved 2026-09-21.
 */
final class AutosaveAssessmentAnswersController extends Controller
{
    private const CONFLICT_CODES = [
        'SESSION_NOT_STARTED',
        'SESSION_CLOSED',
        'DEADLINE_EXCEEDED',
        'AUTOSAVE_STALE_REVISION',
        'AUTOSAVE_REVISION_GAP',
        'MUTATION_PAYLOAD_MISMATCH',
    ];

    public function __invoke(
        AutosaveAssessmentAnswersRequest $request,
        string $id,
        AutosaveAssessmentAnswers $action,
    ): JsonResponse {
        $principal = $request->attributes->get('participant_principal');
        if (! $principal instanceof ParticipantPrincipal) {
            throw new UnauthorizedHttpException('Bearer');
        }

        /** @var array<int, array{item_no: int, value: mixed}> $items */
        $items = $request->validated('items');

        try {
            $result = $action->execute(
                $principal->participantId,
                $id,
                (string) $request->validated('mutation_id'),
                (int) $request->validated('revision'),
                $items,
            );

            if ($result->accepted) {
                return response()->json([
                    'session_id' => $id,
                    'status' => $result->status,
                    'replayed' => $result->replayed,
                    'answers_revision' => $result->receipt?->revision,
                    'accepted_item_numbers' => $result->receipt?->acceptedItemNumbers,
                ]);
            }

            return $this->rejected($result->errorCode, $result->currentRevision, (int) $request->validated('revision'));
        } catch (Throwable $exception) {
            report($exception);

            return $this->error(500, 'ASSESSMENT_AUTOSAVE_FAILED', 'Terjadi kesalahan internal. Coba lagi nanti.');
        }
    }

    private function rejected(?string $errorCode, ?int $currentRevision, int $proposedRevision): JsonResponse
    {
        if ($errorCode === 'SESSION_NOT_FOUND') {
            return $this->error(404, $errorCode, 'Sesi tidak ditemukan.');
        }

        if ($errorCode === 'INVALID_ANSWER_BATCH') {
            return $this->error(422, $errorCode, 'Batch jawaban tidak valid.');
        }

        if ($errorCode === 'AUTOSAVE_REVISION_GAP') {
            return $this->error(409, $errorCode, 'Revisi autosave melompati revisi berikutnya.', [
                'current_revision' => $currentRevision,
                'proposed_revision' => $proposedRevision,
            ]);
        }

        if (in_array($errorCode, self::CONFLICT_CODES, true)) {
            return $this->error(409, $errorCode, 'Autosave ditolak karena status sesi saat ini.');
        }

        report(new RuntimeException("Unmapped assessment autosave error code: {$errorCode}"));

        return $this->error(500, 'ASSESSMENT_AUTOSAVE_FAILED', 'Terjadi kesalahan internal. Coba lagi nanti.');
    }

    /** @param array<string, mixed>|null $details */
    private function error(int $status, string $code, string $message, ?array $details = null): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message, 'details' => $details]], $status);
    }
}
