<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\RequiresRlsContext;
use App\Models\Participant;
use App\Services\ParticipantAuth\ParticipantPrincipal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final class ParticipantProfileController extends Controller implements RequiresRlsContext
{
    public function __invoke(Request $request): JsonResponse
    {
        $principal = $request->attributes->get('participant_principal');

        if (! $principal instanceof ParticipantPrincipal) {
            throw new UnauthorizedHttpException('Bearer');
        }

        $participant = Participant::query()->whereKey($principal->participantId)->firstOrFail();

        return response()->json([
            'data' => [
                'full_name' => $participant->full_name,
                'test_number' => $participant->test_number,
            ],
        ]);
    }
}
