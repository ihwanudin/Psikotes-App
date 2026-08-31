<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Integrations\AssessmentInvitationRejected;
use App\Actions\Integrations\ConsumeAssessmentInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class AssessmentInvitationController extends Controller
{
    public function show(string $publicId): Response
    {
        $nonce = base64_encode(random_bytes(18));

        return response()
            ->view('assessment-invitation', compact('publicId', 'nonce'))
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Frame-Options', 'DENY')
            ->header(
                'Content-Security-Policy',
                "default-src 'none'; connect-src 'self'; style-src 'nonce-{$nonce}'; script-src 'nonce-{$nonce}'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
            );
    }

    public function consume(string $publicId, Request $request, ConsumeAssessmentInvitation $consume): JsonResponse
    {
        $validated = $request->validate(['token' => ['required', 'string', 'size:64']]);

        try {
            $participantToken = $consume->handle($publicId, $validated['token']);
        } catch (AssessmentInvitationRejected $exception) {
            return response()->json(['message' => $exception->publicMessage], $exception->httpStatus);
        }

        return response()->json(['participantToken' => $participantToken])
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }
}
