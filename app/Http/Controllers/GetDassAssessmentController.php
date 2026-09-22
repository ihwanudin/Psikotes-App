<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Dass\GetDassAssessment;
use App\Domain\Dass\DassAssessmentSnapshot;
use App\Services\ParticipantAuth\ParticipantPrincipal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Throwable;

/** GET /api/dass21/{id}. Status/timestamps only -- see DassAssessmentSnapshot's doc. */
final class GetDassAssessmentController extends Controller
{
    public function __invoke(Request $request, string $id, GetDassAssessment $action): JsonResponse
    {
        $principal = $request->attributes->get('participant_principal');
        if (! $principal instanceof ParticipantPrincipal) {
            throw new UnauthorizedHttpException('Bearer');
        }

        try {
            $snapshot = $action->execute($principal->participantId, $id);

            if ($snapshot === null) {
                return $this->error(404, 'DASS_ASSESSMENT_NOT_FOUND', 'Sesi DASS-21 tidak ditemukan.');
            }

            return response()->json($this->resource($snapshot));
        } catch (Throwable $exception) {
            report($exception);

            return $this->error(500, 'DASS_ASSESSMENT_READ_FAILED', 'Terjadi kesalahan internal. Coba lagi nanti.');
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
