<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Contracts\Notifier;
use App\Data\Notifications\ParticipantActivationNotification;
use App\Services\Notifications\Exceptions\NotificationDeliveryFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class N8nNotifier implements Notifier
{
    public function send(ParticipantActivationNotification $notification): void
    {
        $url = config('participant_notifications.n8n.url');
        $token = config('participant_notifications.n8n.token');

        if (! is_string($url)
            || ! str_starts_with($url, 'https://')
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || ! is_string($token)
            || $token === '') {
            throw new NotificationDeliveryFailed('n8n_not_configured');
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withToken($token)
                ->withHeader('Idempotency-Key', $notification->idempotencyKey)
                ->connectTimeout((int) config('participant_notifications.n8n.connect_timeout_seconds', 3))
                ->timeout((int) config('participant_notifications.n8n.timeout_seconds', 10))
                ->withoutRedirecting()
                ->post($url, [
                    'event' => 'participant.activation',
                    'schema_version' => 1,
                    'idempotency_key' => $notification->idempotencyKey,
                    'recipient' => ['phone' => $notification->phone],
                    'credentials' => ['test_number' => $notification->testNumber],
                    'entitlements' => $notification->testTypes,
                ]);
        } catch (ConnectionException) {
            throw new NotificationDeliveryFailed('n8n_connection_failed');
        }

        if (! $response->successful()) {
            throw new NotificationDeliveryFailed(
                $response->serverError() ? 'n8n_http_5xx' : 'n8n_http_4xx',
            );
        }
    }

    public function channel(): string
    {
        return 'n8n';
    }
}
