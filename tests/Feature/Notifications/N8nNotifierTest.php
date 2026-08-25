<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Data\Notifications\ParticipantActivationNotification;
use App\Services\Notifications\Exceptions\NotificationDeliveryFailed;
use App\Services\Notifications\N8nNotifier;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class N8nNotifierTest extends TestCase
{
    public function test_it_sends_the_minimal_activation_contract_with_a_stable_idempotency_key(): void
    {
        config()->set('participant_notifications.n8n.url', 'https://n8n.example.test/webhook/participant-activation');
        config()->set('participant_notifications.n8n.token', 'synthetic-token');
        Http::fake(['n8n.example.test/*' => Http::response(['accepted' => true], 202)]);
        $notification = $this->notification();

        app(N8nNotifier::class)->send($notification);

        Http::assertSent(function (Request $request) use ($notification): bool {
            $payload = $request->data();

            return $request->url() === 'https://n8n.example.test/webhook/participant-activation'
                && $request->hasHeader('Authorization', 'Bearer synthetic-token')
                && $request->hasHeader('Idempotency-Key', $notification->idempotencyKey)
                && $payload === [
                    'event' => 'participant.activation',
                    'schema_version' => 1,
                    'idempotency_key' => $notification->idempotencyKey,
                    'recipient' => ['phone' => $notification->phone],
                    'credentials' => ['test_number' => $notification->testNumber],
                    'entitlements' => ['ist', 'papi'],
                ]
                && ! str_contains($request->body(), 'birth_date')
                && ! str_contains($request->body(), 'full_name');
        });
    }

    public function test_it_fails_closed_with_a_stable_error_code_for_provider_failure(): void
    {
        config()->set('participant_notifications.n8n.url', 'https://n8n.example.test/webhook/participant-activation');
        config()->set('participant_notifications.n8n.token', 'synthetic-token');
        Http::fake(['n8n.example.test/*' => Http::response(['internal' => 'must not leak'], 503)]);

        try {
            app(N8nNotifier::class)->send($this->notification());
            $this->fail('Provider failure should throw.');
        } catch (NotificationDeliveryFailed $exception) {
            $this->assertSame('n8n_http_5xx', $exception->errorCode);
            $this->assertStringNotContainsString('internal', $exception->getMessage());
        }
    }

    public function test_it_rejects_missing_or_non_https_configuration_without_an_http_call(): void
    {
        foreach ([null, 'http://n8n.example.test/webhook/participant-activation'] as $url) {
            config()->set('participant_notifications.n8n.url', $url);
            config()->set('participant_notifications.n8n.token', null);

            try {
                app(N8nNotifier::class)->send($this->notification());
                $this->fail('Invalid configuration should throw.');
            } catch (NotificationDeliveryFailed $exception) {
                $this->assertSame('n8n_not_configured', $exception->errorCode);
            }
        }

        Http::assertNothingSent();
    }

    private function notification(): ParticipantActivationNotification
    {
        return new ParticipantActivationNotification(
            idempotencyKey: '01K3H9M5YXB62D9QK7E5V2G8Z1',
            phone: '+6281234567890',
            testNumber: 'LSI-202608-000001-ABCDEF',
            testTypes: ['ist', 'papi'],
        );
    }
}
