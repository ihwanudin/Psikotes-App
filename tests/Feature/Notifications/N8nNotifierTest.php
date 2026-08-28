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

    public function test_it_allows_an_explicit_local_http_endpoint_in_the_local_environment(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');

        try {
            config()->set('participant_notifications.n8n.url', 'http://host.docker.internal:5678/webhook/participant-activation');
            config()->set('participant_notifications.n8n.token', 'synthetic-token');
            config()->set('participant_notifications.n8n.allow_insecure_local_http', true);
            Http::fake(['host.docker.internal:5678/*' => Http::response(['status' => 'sent'])]);

            app(N8nNotifier::class)->send($this->notification());

            Http::assertSent(fn (Request $request): bool => $request->url()
                === 'http://host.docker.internal:5678/webhook/participant-activation');
        } finally {
            $this->app->detectEnvironment(fn (): string => 'testing');
        }
    }

    public function test_it_rejects_other_http_hosts_when_the_local_override_is_enabled(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');

        try {
            config()->set('participant_notifications.n8n.url', 'http://n8n.example.test/webhook/participant-activation');
            config()->set('participant_notifications.n8n.token', 'synthetic-token');
            config()->set('participant_notifications.n8n.allow_insecure_local_http', true);

            try {
                app(N8nNotifier::class)->send($this->notification());
                $this->fail('An untrusted HTTP host should be rejected.');
            } catch (NotificationDeliveryFailed $exception) {
                $this->assertSame('n8n_not_configured', $exception->errorCode);
            }

            Http::assertNothingSent();
        } finally {
            $this->app->detectEnvironment(fn (): string => 'testing');
        }
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
