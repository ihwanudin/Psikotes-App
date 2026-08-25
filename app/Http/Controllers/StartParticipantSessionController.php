<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\RequiresRlsContext;
use App\Services\ParticipantAuth\Exceptions\EntitlementLocked;
use App\Services\ParticipantAuth\ParticipantEntitlementGate;
use App\Services\ParticipantAuth\ParticipantPrincipal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final class StartParticipantSessionController extends Controller implements RequiresRlsContext
{
    public function __invoke(
        Request $request,
        string $testType,
        ParticipantEntitlementGate $gate,
    ): JsonResponse {
        $principal = $request->attributes->get('participant_principal');

        if (! $principal instanceof ParticipantPrincipal) {
            throw new UnauthorizedHttpException('Bearer');
        }

        try {
            $gate->assertReady($principal->participantId, $testType);
        } catch (EntitlementLocked) {
            return response()->json([
                'error' => [
                    'code' => 'ENTITLEMENT_LOCKED',
                    'message' => 'Tes belum dapat dimulai karena akses belum aktif.',
                ],
            ], 403);
        }

        return response()->json([
            'error' => [
                'code' => 'SESSION_ENGINE_PENDING',
                'message' => 'Akses tes aktif, tetapi mesin sesi belum tersedia pada fase ini.',
            ],
        ], 501);
    }
}
