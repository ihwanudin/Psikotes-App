<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Integrations\IdempotencyConflict;
use App\Actions\Integrations\IntegrationContractViolation;
use App\Actions\Integrations\ProvisionAssessmentParticipant;
use App\Http\Requests\ProvisionAssessmentParticipantRequest;
use App\Models\IntegrationClient;
use Illuminate\Http\JsonResponse;

final class AssessmentParticipantProvisioningController extends Controller
{
    public function __invoke(ProvisionAssessmentParticipantRequest $request, ProvisionAssessmentParticipant $provision): JsonResponse
    {
        $key = $request->idempotencyKey();
        if ($key === null) {
            return $this->error('IDEMPOTENCY_KEY_REQUIRED', 'Idempotency-Key wajib dan tidak valid.', 422);
        }

        $client = $request->attributes->get('integration_client');
        abort_unless($client instanceof IntegrationClient, 401);

        try {
            $result = $provision->handle($request->validated(), $client, $key);
        } catch (IdempotencyConflict) {
            return $this->error('IDEMPOTENCY_CONFLICT', 'Idempotency-Key telah digunakan untuk payload berbeda.', 409);
        } catch (IntegrationContractViolation $exception) {
            return $this->error($exception->errorCode, 'Kontrak integrasi tidak mengizinkan permintaan ini.', $exception->httpStatus);
        }

        return response()->json(['data' => [
            'participantId' => (string) $result['participant_id'],
            'assessmentAttemptId' => $result['assessment_attempt_id'],
            'assessmentStatus' => $result['assessment_status'],
        ]], $result['replayed'] ? 200 : 201);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
