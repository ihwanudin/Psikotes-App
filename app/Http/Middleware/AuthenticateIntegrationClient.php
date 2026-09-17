<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\IntegrationClient;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

final readonly class AuthenticateIntegrationClient
{
    public function __construct(private RlsContextRunner $runner) {}

    public function handle(Request $request, Closure $next): Response
    {
        $clientId = $request->header('X-Client-Id');
        $timestamp = $request->header('X-Timestamp');
        $signature = $request->header('X-Signature');

        if (! is_string($clientId) || $clientId === ''
            || ! is_string($timestamp) || ! preg_match('/^\d{10}$/', $timestamp)
            || ! is_string($signature) || ! preg_match('/^[a-f0-9]{64}$/', $signature)) {
            return $this->error('INVALID_SIGNATURE', 'Autentikasi layanan tidak valid.', 401);
        }

        $client = $this->runner->run(new RlsContext('service'), fn (): ?IntegrationClient => IntegrationClient::query()
            ->with('organization')
            ->where('client_id', $clientId)
            ->where('enabled', true)
            ->first());
        $now = now();

        if ($client === null
            || ($client->effective_from !== null && $client->effective_from->isFuture())
            || ($client->effective_until !== null && $client->effective_until->lte($now))) {
            return $this->error('INVALID_SIGNATURE', 'Autentikasi layanan tidak valid.', 401);
        }

        $policy = $client->rate_limit_policy ?? [];
        $perMinute = max(1, min(1_000, (int) ($policy['requestsPerMinute'] ?? 120)));
        $rateKey = 'integration-client:'.hash('sha256', $client->client_id);
        if (RateLimiter::tooManyAttempts($rateKey, $perMinute)) {
            return $this->error('RATE_LIMITED', 'Terlalu banyak permintaan integrasi.', 429);
        }
        RateLimiter::hit($rateKey, 60);

        $secret = config('assessment_integration.credentials.'.$client->credential_reference);
        if (! is_string($secret) || strlen($secret) < 32) {
            return $this->error('INTEGRATION_UNAVAILABLE', 'Integrasi belum tersedia.', 503);
        }

        $tolerance = max(1, (int) config('assessment_integration.signature_tolerance_seconds', 300));
        if (abs($now->getTimestamp() - (int) $timestamp) > $tolerance) {
            return $this->error('STALE_REQUEST', 'Permintaan layanan telah kedaluwarsa.', 401);
        }

        $expected = hash_hmac('sha256', $timestamp."\n".hash('sha256', $request->getContent()), $secret);
        if (! hash_equals($expected, $signature)) {
            return $this->error('INVALID_SIGNATURE', 'Autentikasi layanan tidak valid.', 401);
        }

        $request->attributes->set('integration_client', $client);

        return $next($request);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
