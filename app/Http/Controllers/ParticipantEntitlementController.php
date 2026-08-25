<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\RequiresRlsContext;
use App\Models\Entitlement;
use App\Services\ParticipantAuth\ParticipantPrincipal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final class ParticipantEntitlementController extends Controller implements RequiresRlsContext
{
    public function __invoke(Request $request): JsonResponse
    {
        $principal = $request->attributes->get('participant_principal');

        if (! $principal instanceof ParticipantPrincipal) {
            throw new UnauthorizedHttpException('Bearer');
        }

        $entitlements = Entitlement::query()
            ->where('participant_id', $principal->participantId)
            ->orderBy('id')
            ->get(['test_type', 'status', 'ready_at', 'started_at', 'completed_at']);

        return response()->json(['data' => $entitlements]);
    }
}
