<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Orders\FindParticipantOrderStatus;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class RegistrationOrderStatusController extends Controller
{
    public function __invoke(
        Request $request,
        RlsContextRunner $runner,
        FindParticipantOrderStatus $find,
    ): Response {
        $participantId = $request->session()->get('registration.participant_id');
        $authorizedUntil = $request->session()->get('registration.evidence_authorized_until');
        $authorized = is_numeric($participantId)
            && is_numeric($authorizedUntil)
            && (int) $authorizedUntil >= now()->getTimestamp();
        $status = $authorized
            ? $runner->run(
                new RlsContext('service'),
                fn () => $find->handle((int) $participantId),
            )
            : null;

        return Inertia::render('registration/order-status', [
            'authorized' => $authorized,
            'order' => $status?->toInertiaArray(),
        ]);
    }
}
