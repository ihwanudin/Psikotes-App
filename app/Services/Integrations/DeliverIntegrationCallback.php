<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Models\IntegrationClient;
use App\Models\IntegrationSource;
use App\Models\OutboxMessage;
use App\Rules\PublicHttpsUrl;
use App\Rules\RelativeCallbackPath;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final readonly class DeliverIntegrationCallback
{
    public function __construct(private RlsContextRunner $runner) {}

    public function handle(string $eventId): void
    {
        $record = $this->runner->run(new RlsContext('service'), function () use ($eventId): ?array {
            $message = OutboxMessage::query()->where('message_id', $eventId)->where('topic', 'psychotest.assessment-event')->first();
            if ($message === null) {
                return null;
            }
            $payload = $message->payload;
            $client = IntegrationClient::query()
                ->where('client_id', $payload['integrationClientId'] ?? null)
                ->where('enabled', true)
                ->where(fn ($query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', now()))
                ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', now()))
                ->first();
            if ($client === null) {
                $message->forceFill([
                    'status' => 'failed',
                    'attempts' => $message->attempts + 1,
                    'available_at' => now()->addMinutes(5),
                    'last_error' => 'INTEGRATION_CLIENT_UNAVAILABLE',
                ])->save();

                return null;
            }
            if (! in_array($client->result_delivery_mode, ['CALLBACK', 'CALLBACK_AND_POLL'], true)) {
                $message->forceFill(['status' => 'processed', 'processed_at' => now(), 'last_error' => null])->save();

                return null;
            }
            $source = IntegrationSource::query()
                ->where('integration_client_id', $client->id)
                ->where('source_system', $payload['targetSystem'] ?? null)
                ->where('contract_version', $payload['eventVersion'] ?? 'v1')
                ->where('status', 'ACTIVE')
                ->where(fn ($query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', now()))
                ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', now()))
                ->first();
            if ($source === null) {
                $message->forceFill([
                    'status' => 'failed',
                    'attempts' => $message->attempts + 1,
                    'available_at' => now()->addMinutes(5),
                    'last_error' => 'INTEGRATION_SOURCE_UNAVAILABLE',
                ])->save();

                return null;
            }
            $delivery = DB::table('integration_callback_deliveries')->where('event_id', $eventId)->first();
            if ($delivery !== null && in_array($delivery->status, ['UNKNOWN', 'DELIVERED'], true)) {
                return null;
            }
            if ($delivery === null) {
                DB::table('integration_callback_deliveries')->insert([
                    'outbox_message_id' => $message->id, 'integration_client_id' => $client->id,
                    'event_id' => $eventId, 'status' => 'PENDING', 'attempts' => 0,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $message->forceFill([
                'status' => 'processing',
                'attempts' => $message->attempts + 1,
                'last_error' => null,
            ])->save();

            return compact('message', 'client', 'source');
        });

        if ($record === null) {
            return;
        }

        $message = $record['message'];
        $client = $record['client'];
        $source = $record['source'];
        try {
            $url = $this->callbackUrl($client, $source);
            $body = json_encode($message->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $timestamp = (string) now()->timestamp;
            $secret = $this->secret($client);
        } catch (RuntimeException) {
            $this->updateDelivery($eventId, 'FAILED', null, 'CALLBACK_CONFIGURATION_UNAVAILABLE');

            return;
        }

        try {
            $response = Http::timeout(10)->withHeaders([
                'X-Client-Id' => $client->client_id,
                'X-Timestamp' => $timestamp,
                'X-Signature' => hash_hmac('sha256', $timestamp."\n".hash('sha256', $body), $secret),
                'Idempotency-Key' => $message->message_id,
                'Content-Type' => 'application/json',
            ])->withBody($body, 'application/json')->post($url);

            $this->updateDelivery($eventId, $response->successful() ? 'DELIVERED' : 'FAILED', $response->status(), $response->successful() ? null : 'HTTP_REJECTED');
        } catch (ConnectionException) {
            $this->updateDelivery($eventId, 'UNKNOWN', null, 'DELIVERY_OUTCOME_UNKNOWN');
        }
    }

    private function updateDelivery(string $eventId, string $status, ?int $httpStatus, ?string $error): void
    {
        $this->runner->run(new RlsContext('service'), function () use ($eventId, $status, $httpStatus, $error): void {
            $now = now();
            $delivery = DB::table('integration_callback_deliveries')->where('event_id', $eventId)->first();
            if ($delivery === null) {
                return;
            }
            DB::table('integration_callback_deliveries')->where('id', $delivery->id)->update([
                'status' => $status,
                'attempts' => DB::raw('attempts + 1'),
                'last_http_status' => $httpStatus,
                'last_error_code' => $error,
                'last_attempted_at' => $now,
                'delivered_at' => $status === 'DELIVERED' ? $now : null,
                'updated_at' => $now,
            ]);
            DB::table('outbox_messages')->where('id', $delivery->outbox_message_id)->update([
                'status' => match ($status) {
                    'DELIVERED' => 'processed',
                    'FAILED' => 'failed',
                    default => 'processing',
                },
                'processed_at' => $status === 'DELIVERED' ? $now : null,
                'available_at' => $status === 'FAILED' ? $now->copy()->addMinutes(5) : $now,
                'last_error' => $error,
                'updated_at' => $now,
            ]);
        });
    }

    private function callbackUrl(IntegrationClient $client, IntegrationSource $source): string
    {
        $base = $client->callback_base_url;
        if (! PublicHttpsUrl::accepts($base) || ! RelativeCallbackPath::accepts($source->callback_path)) {
            throw new RuntimeException('Secure callback configuration is unavailable.');
        }

        return rtrim($base, '/').'/'.ltrim($source->callback_path, '/');
    }

    private function secret(IntegrationClient $client): string
    {
        $secret = config('assessment_integration.credentials.'.$client->credential_reference);
        if (! is_string($secret) || strlen($secret) < 32) {
            throw new RuntimeException('Callback credential is unavailable.');
        }

        return $secret;
    }
}
