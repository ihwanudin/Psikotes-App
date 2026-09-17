<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Retention\RetentionDataClass;
use App\Domain\Retention\RetentionPolicy;
use App\Models\IntegrationClient;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

final readonly class AuthenticateSelectionResultPoll
{
    public function __construct(
        private RlsContextRunner $runner,
        private RetentionPolicy $retention,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $rateKey = 'selection-result-poll:'.hash('sha256', (string) $request->ip());
        if (RateLimiter::tooManyAttempts($rateKey, 120)) {
            $this->signalRateLimit($request);

            return response()->json([
                'error' => ['code' => 'RATE_LIMITED', 'message' => 'Terlalu banyak permintaan layanan.'],
            ], 429, ['Cache-Control' => 'no-store, private']);
        }
        RateLimiter::hit($rateKey, 60);

        $configuredClient = config('selection_integration.client_id');
        $secret = config('selection_integration.client_secret');
        $tolerance = config('selection_integration.signature_tolerance_seconds');
        $clientHeader = $request->header('X-Client-Id');
        $timestamp = $request->header('X-Timestamp');
        $contract = $request->header('X-Integration-Contract');
        $signatureVersion = $request->header('X-Signature-Version');
        $signature = $request->header('X-Signature');

        if (! (bool) config('selection_integration.result_poll_enabled')
            || ! is_string($configuredClient) || $configuredClient === ''
            || ! is_string($secret) || strlen($secret) < 32
            || ! is_int($tolerance) || $tolerance < 30 || $tolerance > 900) {
            return $this->deny($request, $clientHeader, 'CONFIG_UNAVAILABLE');
        }
        if (! is_string($clientHeader) || ! hash_equals($configuredClient, $clientHeader)) {
            return $this->deny($request, $clientHeader, 'CLIENT_MISMATCH');
        }
        if (! is_string($timestamp) || preg_match('/^\d{10}$/', $timestamp) !== 1
            || $contract !== 'selection-result-poll:v1'
            || $signatureVersion !== 'v2'
            || ! is_string($signature) || preg_match('/^[a-f0-9]{64}$/', $signature) !== 1) {
            return $this->deny($request, $clientHeader, 'REQUEST_FORMAT_INVALID');
        }
        if (abs(now()->getTimestamp() - (int) $timestamp) > $tolerance) {
            return $this->deny($request, $clientHeader, 'STALE_REQUEST');
        }

        $expected = hash_hmac('sha256', implode("\n", [
            $timestamp,
            $contract,
            strtoupper($request->getMethod()),
            $request->getPathInfo(),
            $this->canonicalQuery($request),
            hash('sha256', $request->getContent()),
        ]), $secret);
        if (! hash_equals($expected, $signature)) {
            return $this->deny($request, $clientHeader, 'SIGNATURE_INVALID');
        }

        return $this->runner->run(new RlsContext('service'), function () use (
            $configuredClient,
            $request,
            $clientHeader,
            $next,
        ): Response {
            $client = IntegrationClient::query()
                ->where('client_id', $configuredClient)
                ->lockForUpdate()
                ->first();
            $now = now();
            if ($client === null || ! $client->enabled
                || ($client->effective_from !== null && $client->effective_from->isFuture())
                || ($client->effective_until !== null && $client->effective_until->lte($now))
                || ! in_array($client->result_delivery_mode, ['POLL', 'CALLBACK_AND_POLL'], true)) {
                return $this->deny($request, $clientHeader, 'CLIENT_REGISTRY_UNAVAILABLE');
            }

            $request->attributes->set('integration_client', $client);
            $response = $next($request);
            $response->headers->set('Cache-Control', 'no-store, private');

            return $response;
        });
    }

    private function signalRateLimit(Request $request): void
    {
        $sourceReference = hash('sha256', (string) $request->ip());
        $window = intdiv(now()->getTimestamp(), 60);
        $signalKey = "selection-result-poll-limited:{$sourceReference}:{$window}";
        RateLimiter::hit($signalKey, 70);

        if (! Cache::add($signalKey.':logged', true, 70)) {
            return;
        }

        Log::warning('Selection result poll rate limited.', [
            'sourceReference' => $sourceReference,
            'window' => $window,
            'limit' => 120,
            'deduplicated' => true,
        ]);
    }

    private function canonicalQuery(Request $request): string
    {
        $query = $request->query();
        ksort($query);

        return http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function deny(
        Request $request,
        mixed $clientHeader,
        string $reasonCode,
    ): JsonResponse {
        $clientReference = hash('sha256', is_string($clientHeader) ? $clientHeader : '');
        $requestReference = hash('sha256', implode("\n", [
            strtoupper($request->getMethod()),
            $request->getPathInfo(),
            $request->getQueryString() ?? '',
        ]));
        $at = CarbonImmutable::now('UTC');
        $expiresAt = $this->retention->expiresAt(RetentionDataClass::Audit, $at);
        $writeAudit = static function () use ($clientReference, $requestReference, $reasonCode, $at, $expiresAt): void {
            DB::table('audit_logs')->insert([
                'branch_id' => null,
                'actor_type' => 'service',
                'actor_id' => null,
                'action' => 'selection_result_poll.authentication_denied',
                'subject_type' => IntegrationClient::class,
                'subject_id' => null,
                'context' => json_encode([
                    'clientReference' => $clientReference,
                    'requestReference' => $requestReference,
                    'reasonCode' => $reasonCode,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'occurred_at' => $at,
                'expires_at' => $expiresAt,
            ]);
        };
        if ($this->runner->current()?->role === 'service') {
            $writeAudit();
        } else {
            $this->runner->run(new RlsContext('service'), $writeAudit);
        }

        return response()->json([
            'error' => ['code' => 'AUTHENTICATION_FAILED', 'message' => 'Autentikasi layanan tidak valid.'],
        ], 401, ['Cache-Control' => 'no-store, private']);
    }
}
