<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\RequiresRlsContext;
use App\Services\Orders\FindParticipantOrderStatus;
use App\Services\ParticipantAuth\ParticipantPrincipal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final class ParticipantOrderStatusController extends Controller implements RequiresRlsContext
{
    public function __invoke(Request $request, FindParticipantOrderStatus $find): JsonResponse
    {
        $principal = $request->attributes->get('participant_principal');

        if (! $principal instanceof ParticipantPrincipal) {
            throw new UnauthorizedHttpException('Bearer');
        }

        return response()->json([
            'data' => $find->handle($principal->participantId)?->toApiArray(),
        ]);
    }
}
