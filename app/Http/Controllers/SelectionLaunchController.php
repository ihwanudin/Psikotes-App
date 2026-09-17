<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Integrations\ConsumeSelectionLaunchTicket;
use App\Actions\Integrations\SelectionLaunchRejected;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class SelectionLaunchController extends Controller
{
    public function __invoke(Request $request, ConsumeSelectionLaunchTicket $consume): Response
    {
        $ticket = $request->query('ticket');
        if (! is_string($ticket) || $ticket === '' || strlen($ticket) > 4096) {
            return $this->page(null, 'Tautan psikotes tidak lengkap.', 422);
        }

        try {
            $participantToken = $consume->handle($ticket);
        } catch (SelectionLaunchRejected $exception) {
            return $this->page(null, $exception->publicMessage, $exception->httpStatus);
        }

        return $this->page($participantToken, null, 200);
    }

    private function page(?string $participantToken, ?string $error, int $status): Response
    {
        $nonce = base64_encode(random_bytes(18));
        $cleanPath = '/participant/lobby';

        return response()
            ->view('selection-launch', compact('participantToken', 'error', 'nonce', 'cleanPath'), $status)
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Frame-Options', 'DENY')
            ->header(
                'Content-Security-Policy',
                "default-src 'none'; style-src 'nonce-{$nonce}'; script-src 'nonce-{$nonce}'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
            );
    }
}
