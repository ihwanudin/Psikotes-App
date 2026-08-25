<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Contracts\Notifier;
use App\Data\Notifications\ParticipantActivationNotification;
use App\Models\Branch;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\Participant;
use App\Services\Notifications\DeliverParticipantActivation;
use App\Services\Notifications\Exceptions\NotificationDeliveryFailed;
use App\Services\Notifications\FakeNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

final class ParticipantActivationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-08-25 15:00:00+07:00');
    }

    public function test_delivery_sends_minimal_credentials_once_and_marks_outbox_processed(): void
    {
        [$order, $messageId] = $this->paidOrderAndOutbox();
        $notifier = new FakeNotifier;
        $logger = new RecordingLogger;
        $this->app->instance(Notifier::class, $notifier);
        $this->app->instance(LoggerInterface::class, $logger);
        $delivery = app(DeliverParticipantActivation::class);

        $delivery->handle($messageId);
        $delivery->handle($messageId);

        $this->assertCount(1, $notifier->delivered());
        $notification = $notifier->delivered()[0];
        $this->assertSame($messageId, $notification->idempotencyKey);
        $this->assertSame('+6281234567890', $notification->phone);
        $this->assertSame('LSI-202608-000001-ABCDEF', $notification->testNumber);
        $this->assertSame(['ist', 'papi'], $notification->testTypes);
        $this->assertDatabaseHas('outbox_messages', [
            'message_id' => $messageId,
            'status' => 'processed',
            'attempts' => 1,
            'last_error' => null,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'branch_id' => $order->participant->branch_id,
            'action' => 'participant_notification.delivered',
            'subject_type' => Order::class,
            'subject_id' => $order->public_id,
        ]);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertSame([
            [
                'level' => 'info',
                'message' => 'participant_notification_delivery',
                'context' => [
                    'message_id' => $messageId,
                    'topic' => 'participant.activation',
                    'channel' => 'fake',
                    'attempt' => 1,
                    'outcome' => 'delivered',
                ],
            ],
        ], $logger->records);
    }

    public function test_provider_failure_is_audited_without_rolling_back_paid_and_can_retry(): void
    {
        [$order, $messageId, $entitlements] = $this->paidOrderAndOutbox();
        $notifier = new FailsOnceNotifier;
        $logger = new RecordingLogger;
        $this->app->instance(Notifier::class, $notifier);
        $this->app->instance(LoggerInterface::class, $logger);
        $delivery = app(DeliverParticipantActivation::class);

        try {
            $delivery->handle($messageId);
            $this->fail('First provider attempt should fail.');
        } catch (NotificationDeliveryFailed $exception) {
            $this->assertSame('synthetic_provider_unavailable', $exception->errorCode);
        }

        $this->assertSame('paid', $order->fresh()->status->value);
        $this->assertSame(['ready', 'ready'], $entitlements->map->fresh()->pluck('status')->all());
        $this->assertDatabaseHas('outbox_messages', [
            'message_id' => $messageId,
            'status' => 'failed',
            'attempts' => 1,
            'last_error' => 'synthetic_provider_unavailable',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'participant_notification.failed',
            'subject_id' => $order->public_id,
        ]);

        Date::setTestNow('2026-08-25 15:00:31+07:00');
        $delivery->handle($messageId);

        $this->assertSame([$messageId, $messageId], $notifier->attemptedKeys);
        $this->assertCount(1, $notifier->delivered);
        $this->assertDatabaseHas('outbox_messages', [
            'message_id' => $messageId,
            'status' => 'processed',
            'attempts' => 2,
            'last_error' => null,
        ]);
        $this->assertDatabaseCount('audit_logs', 2);
        $failureLog = $logger->records[0];
        $this->assertSame('warning', $failureLog['level']);
        $this->assertSame('participant_notification_delivery', $failureLog['message']);
        $this->assertSame('synthetic_provider_unavailable', $failureLog['context']['error_code']);
        $this->assertNotContains('+6281234567890', $failureLog['context']);
        $this->assertNotContains('LSI-202608-000001-ABCDEF', $failureLog['context']);
    }

    /** @return array{Order, string, Collection<int, Entitlement>} */
    private function paidOrderAndOutbox(): array
    {
        $branch = Branch::query()->create([
            'code' => 'CENTRAL',
            'name' => 'LSI Pusat',
            'ref_code' => 'CENTRAL-REF',
            'is_default' => true,
        ]);
        $participant = Participant::query()->create([
            'branch_id' => $branch->id,
            'referral_branch_id' => $branch->id,
            'referral_source' => 'default',
            'full_name' => 'Ayu Pratiwi',
            'gender' => 'female',
            'birth_date' => '2001-04-15',
            'education_level' => 'SMA/SMK',
            'intended_field' => 'KAIGO',
            'phone' => '+6281234567890',
            'test_number' => 'LSI-202608-000001-ABCDEF',
        ]);
        $methodId = DB::table('payment_methods')->insertGetId([
            'code' => 'manual_transfer',
            'display_name' => 'Transfer Manual',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $order = Order::query()->create([
            'public_id' => (string) Str::ulid(),
            'participant_id' => $participant->id,
            'payment_method_id' => $methodId,
            'status' => 'paid',
            'amount' => 250_000,
            'currency' => 'IDR',
            'paid_at' => now(),
        ]);
        $entitlements = collect(['ist', 'papi'])->map(fn (string $testType): Entitlement => Entitlement::query()->create([
            'participant_id' => $participant->id,
            'order_id' => $order->id,
            'test_type' => $testType,
            'status' => 'ready',
            'ready_at' => now(),
        ]));
        $messageId = (string) Str::ulid();
        DB::table('outbox_messages')->insert([
            'message_id' => $messageId,
            'deduplication_key' => hash('sha256', 'participant.activation|'.$order->public_id),
            'topic' => 'participant.activation',
            'aggregate_type' => Order::class,
            'aggregate_id' => $order->public_id,
            'payload' => json_encode(['schema_version' => 1], JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => now(),
            'expires_at' => now()->addYears(2),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$order->load('participant'), $messageId, $entitlements];
    }
}

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /** @param array<string, mixed> $context */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}

final class FailsOnceNotifier implements Notifier
{
    /** @var list<string> */
    public array $attemptedKeys = [];

    /** @var list<ParticipantActivationNotification> */
    public array $delivered = [];

    public function send(ParticipantActivationNotification $notification): void
    {
        $this->attemptedKeys[] = $notification->idempotencyKey;

        if (count($this->attemptedKeys) === 1) {
            throw new NotificationDeliveryFailed('synthetic_provider_unavailable');
        }

        $this->delivered[] = $notification;
    }

    public function channel(): string
    {
        return 'fake';
    }
}
