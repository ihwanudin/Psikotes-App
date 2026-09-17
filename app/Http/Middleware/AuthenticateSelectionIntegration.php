<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateSelectionIntegration
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('selection_integration.enabled')) {
            return $this->error('INTEGRATION_UNAVAILABLE', 'Integrasi seleksi belum tersedia.', 503);
        }

        $configuredClient = config('selection_integration.client_id');
        $secret = config('selection_integration.client_secret');
        $client = $request->header('X-Client-Id');
        $timestamp = $request->header('X-Timestamp');
        $signature = $request->header('X-Signature');

        if (! is_string($configuredClient) || $configuredClient === ''
            || ! is_string($secret) || strlen($secret) < 32) {
            return $this->error('INTEGRATION_UNAVAILABLE', 'Integrasi seleksi belum tersedia.', 503);
        }

        if (! is_string($client) || ! hash_equals($configuredClient, $client)
            || ! is_string($timestamp) || ! preg_match('/^\d{10}$/', $timestamp)
            || ! is_string($signature) || ! preg_match('/^[a-f0-9]{64}$/', $signature)) {
            return $this->error('INVALID_SIGNATURE', 'Autentikasi layanan tidak valid.', 401);
        }

        $tolerance = max(1, (int) config('selection_integration.signature_tolerance_seconds', 300));
        if (abs(now()->getTimestamp() - (int) $timestamp) > $tolerance) {
            return $this->error('STALE_REQUEST', 'Permintaan layanan telah kedaluwarsa.', 401);
        }

        $expected = hash_hmac(
            'sha256',
            $timestamp."\n".hash('sha256', $request->getContent()),
            $secret,
        );

        if (! hash_equals($expected, $signature)) {
            return $this->error('INVALID_SIGNATURE', 'Autentikasi layanan tidak valid.', 401);
        }

        $request->attributes->set('selection_client_id', $client);

        return $next($request);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
