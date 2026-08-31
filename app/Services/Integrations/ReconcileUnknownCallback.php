<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Models\IntegrationClient;
use App\Models\IntegrationSource;
use App\Rules\PublicHttpsUrl;
use App\Rules\RelativeCallbackPath;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final readonly class ReconcileUnknownCallback
{
    public function __construct(private RlsContextRunner $runner) {}

    public function handle(string $eventId): string
    {
        $record = $this->runner->run(new RlsContext('service'), function () use ($eventId): ?array {
            $delivery = DB::table('integration_callback_deliveries')->where('event_id', $eventId)->where('status', 'UNKNOWN')->first();
            if ($delivery === null) {
                return null;
            }
            $client = IntegrationClient::query()->whereKey((int) $delivery->integration_client_id)->where('enabled', true)->first();
            $message = DB::table('outbox_messages')->where('id', $delivery->outbox_message_id)->first();
            if ($client === null || $message === null) {
                return null;
            }
            $payload = json_decode($message->payload, true, flags: JSON_THROW_ON_ERROR);
            $source = IntegrationSource::query()
                ->where('integration_client_id', $client->id)
                ->where('source_system', $payload['targetSystem'] ?? null)
                ->where('contract_version', $payload['eventVersion'] ?? 'v1')
                ->where('status', 'ACTIVE')
                ->first();

            return $source === null ? null : compact('client', 'source');
        });
        if ($record === null) {
            return 'NOT_RECONCILABLE';
        }

        $client = $record['client'];
        $source = $record['source'];
        $path = $source->callback_configuration['reconciliationPath'] ?? null;
        if (! PublicHttpsUrl::accepts($client->callback_base_url) || ! RelativeCallbackPath::accepts($path)) {
            return 'INVALID_CONFIGURATION';
        }
        $url = rtrim($client->callback_base_url, '/').'/'.ltrim(str_replace('{eventId}', rawurlencode($eventId), $path), '/');
        $timestamp = (string) now()->timestamp;
        $secret = config('assessment_integration.credentials.'.$client->credential_reference);
        if (! is_string($secret)) {
            return 'MISSING_CREDENTIAL';
        }

        try {
            $response = Http::timeout(10)->withHeaders([
                'X-Client-Id' => $client->client_id,
                'X-Timestamp' => $timestamp,
                'X-Signature' => hash_hmac('sha256', $timestamp."\n".hash('sha256', ''), $secret),
                'Idempotency-Key' => $eventId,
            ])->get($url);
        } catch (ConnectionException) {
            return 'UNKNOWN';
        }

        $received = $response->successful() && $response->json('received') === true;
        $this->runner->run(new RlsContext('service'), function () use ($eventId, $received, $response): void {
            $now = now();
            $delivery = DB::table('integration_callback_deliveries')->where('event_id', $eventId)->first();
            if ($delivery === null) {
                return;
            }
            DB::table('integration_callback_deliveries')->where('id', $delivery->id)->update([
                'status' => $received ? 'DELIVERED' : 'PENDING',
                'last_http_status' => $response->status(),
                'last_error_code' => $received ? null : 'REMOTE_EVENT_NOT_FOUND',
                'reconciled_at' => $now,
                'delivered_at' => $received ? $now : null,
                'updated_at' => $now,
            ]);
            DB::table('outbox_messages')->where('id', $delivery->outbox_message_id)->update([
                'status' => $received ? 'processed' : 'failed',
                'processed_at' => $received ? $now : null,
                'available_at' => $received ? $now : $now->copy()->addMinutes(5),
                'last_error' => $received ? null : 'REMOTE_EVENT_NOT_FOUND',
                'updated_at' => $now,
            ]);
        });

        return $received ? 'DELIVERED' : 'PENDING';
    }
}
