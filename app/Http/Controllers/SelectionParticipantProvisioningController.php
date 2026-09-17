<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Integrations\IdempotencyConflict;
use App\Actions\Integrations\ProvisionSelectionParticipant;
use App\Actions\Integrations\SelectionIntegrationUnavailable;
use App\Http\Requests\ProvisionSelectionParticipantRequest;
use Illuminate\Http\JsonResponse;

final class SelectionParticipantProvisioningController extends Controller
{
    public function __invoke(
        ProvisionSelectionParticipantRequest $request,
        ProvisionSelectionParticipant $provision,
    ): JsonResponse {
        $idempotencyKey = $request->idempotencyKey();
        if ($idempotencyKey === null) {
            return response()->json([
                'error' => ['code' => 'INVALID_IDEMPOTENCY_KEY', 'message' => 'Kunci idempotensi tidak valid.'],
            ], 422);
        }

        try {
            $result = $provision->handle(
                $request->validated(),
                (string) $request->attributes->get('selection_client_id'),
                $idempotencyKey,
            );
        } catch (IdempotencyConflict) {
            return response()->json([
                'error' => ['code' => 'IDEMPOTENCY_CONFLICT', 'message' => 'Kunci idempotensi telah digunakan untuk data berbeda.'],
            ], 409);
        } catch (SelectionIntegrationUnavailable) {
            return response()->json([
                'error' => ['code' => 'INTEGRATION_UNAVAILABLE', 'message' => 'Integrasi seleksi belum tersedia.'],
            ], 503);
        }

        return response()->json([
            'data' => ['participantId' => (string) $result['participant_id']],
        ], $result['replayed'] ? 200 : 201);
    }
}
