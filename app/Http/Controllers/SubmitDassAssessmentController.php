<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Dass\SubmitDassAssessment;
use App\Domain\Dass\Dass21ConfigUnavailable;
use App\Domain\Dass\DassAssessmentSnapshot;
use App\Domain\Dass\InvalidDassAssessmentState;
use App\Http\Requests\SubmitDassAssessmentRequest;
use App\Services\ParticipantAuth\ParticipantPrincipal;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Throwable;

/**
 * POST /api/dass21/{id}/submit. Response is a status ack only -- never
 * score/category content, per Lead's explicit instruction to hold back
 * participant-facing results (decision (c) still pending). See
 * SubmitDassAssessment's doc for why this whole action runs as one
 * service transaction.
 */
final class SubmitDassAssessmentController extends Controller
{
    public function __invoke(
        SubmitDassAssessmentRequest $request,
        string $id,
        SubmitDassAssessment $action,
    ): JsonResponse {
        $principal = $request->attributes->get('participant_principal');
        if (! $principal instanceof ParticipantPrincipal) {
            throw new UnauthorizedHttpException('Bearer');
        }

        try {
            $snapshot = $action->execute($principal->participantId, $id, $request->responses());

            return response()->json($this->resource($snapshot));
        } catch (InvalidDassAssessmentState) {
            return $this->error(409, 'DASS_SUBMIT_CONFLICT', 'Permintaan kirim jawaban DASS-21 berbenturan dengan status sesi saat ini.');
        } catch (Dass21ConfigUnavailable) {
            return $this->error(503, 'DASS_CONFIG_UNAVAILABLE', 'Konfigurasi penilaian DASS-21 belum tersedia. Coba lagi nanti.');
        } catch (Throwable $exception) {
            report($exception);

            return $this->error(500, 'DASS_SUBMIT_FAILED', 'Terjadi kesalahan internal. Coba lagi nanti.');
        }
    }

    /** @return array<string, mixed> */
    private function resource(DassAssessmentSnapshot $snapshot): array
    {
        return [
            'assessment_id' => $snapshot->publicId,
            'status' => $snapshot->status->value,
            'started_at' => $snapshot->startedAt,
            'completed_at' => $snapshot->completedAt,
        ];
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
