<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Admins\AdminPasswordSetupRejected;
use App\Actions\Admins\ConsumeAdminPasswordSetupLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Admin account lifecycle (2026-09-22, Lead sign-off). Same sealed-page
 * shape as AssessmentInvitationController: a strict-CSP page keyed only by
 * `publicId`, the raw token stays client-side (URL fragment, never a GET
 * query param), and consume() is the only place it's ever sent to the
 * server, over POST.
 */
final class AdminPasswordSetupController extends Controller
{
    public function show(string $publicId): Response
    {
        $nonce = base64_encode(random_bytes(18));

        return response()
            ->view('admin-password-setup', compact('publicId', 'nonce'))
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Frame-Options', 'DENY')
            ->header(
                'Content-Security-Policy',
                "default-src 'none'; connect-src 'self'; style-src 'nonce-{$nonce}'; script-src 'nonce-{$nonce}'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
            );
    }

    public function consume(string $publicId, Request $request, ConsumeAdminPasswordSetupLink $consume): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'string', 'confirmed'],
        ]);

        try {
            $consume->handle($publicId, $validated['token'], $validated['password']);
        } catch (AdminPasswordSetupRejected $exception) {
            return response()->json(['message' => $exception->publicMessage], $exception->httpStatus)
                ->header('Cache-Control', 'no-store, private');
        }

        return response()->json(['message' => 'Kata sandi berhasil diatur.'])
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }
}
