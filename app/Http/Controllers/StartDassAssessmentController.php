<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Dass\AllocateAndStartDassAssessment;
use App\Domain\Dass\DassAssessmentSnapshot;
use App\Services\ParticipantAuth\Exceptions\EntitlementLocked;
use App\Services\ParticipantAuth\ParticipantPrincipal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Throwable;

/**
 * POST /api/dass21/start. Sealed the same way every other session-taking
 * controller in this codebase is: no DB/Eloquent/RlsContextRunner
 * dependency here -- the action owns its own service transaction (see
 * AllocateAndStartDassAssessment's doc for why this is required even
 * though Lead's 2026-09-22 decision let DASS's assessments/responses
 * writes stay in a plain participant RLS context in principle: this
 * action's own entitlement/prerequisite/consent reads need one stable
 * elevated context regardless).
 */
final class StartDassAssessmentController extends Controller
{
    public function __invoke(Request $request, AllocateAndStartDassAssessment $action): JsonResponse
    {
        $principal = $request->attributes->get('participant_principal');
        if (! $principal instanceof ParticipantPrincipal) {
            throw new UnauthorizedHttpException('Bearer');
        }

        try {
            $snapshot = $action->execute($principal);

            return response()->json($this->resource($snapshot));
        } catch (EntitlementLocked) {
            return $this->error(403, 'ASSESSMENT_NOT_AVAILABLE', 'DASS-21 tidak tersedia untuk dimulai.');
        } catch (Throwable $exception) {
            report($exception);

            return $this->error(500, 'DASS_START_FAILED', 'Terjadi kesalahan internal. Coba lagi nanti.');
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
