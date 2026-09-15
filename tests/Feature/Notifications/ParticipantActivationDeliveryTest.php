<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Contracts\Notifier;
use App\Data\Notifications\ParticipantActivationNotification;
use App\Models\AssessmentCase;
use App\Models\Branch;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\Participant;
use App\Models\TestPackage;
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

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    public function test_delivery_sends_minimal_credentials_once_and_marks_outbox_processed(): void
    {
        Date::setTestNow('2024-02-29 10:15:00+07:00');
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
            'processed_at' => '2024-02-29 03:15:00',
            'last_error' => null,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'branch_id' => $order->participant->branch_id,
            'action' => 'participant_notification.delivered',
            'subject_type' => Order::class,
            'subject_id' => $order->public_id,
        ]);
        $this->assertDatabaseCount('audit_logs', 1);
        $audit = DB::table('audit_logs')->sole();
        $this->assertSame('2024-02-29 03:15:00', $audit->occurred_at);
        $this->assertSame('2029-02-28 03:15:00', $audit->expires_at);
        $context = json_decode((string) $audit->context, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['topic', 'channel', 'attempt'], array_keys($context));
        $this->assertSame([
            'topic' => 'participant.activation',
            'channel' => 'fake',
            'attempt' => 1,
        ], $context);
        $encodedAudit = json_encode($audit, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        foreach (['Ayu Pratiwi', '+6281234567890', 'LSI-202608-000001-ABCDEF'] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $encodedAudit);
        }
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
        Date::setTestNow('2024-02-29 10:15:00+07:00');
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
            'available_at' => '2024-02-29 03:15:30',
            'last_error' => 'synthetic_provider_unavailable',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'participant_notification.failed',
            'subject_id' => $order->public_id,
        ]);
        $failureAudit = DB::table('audit_logs')->sole();
        $this->assertSame('2024-02-29 03:15:00', $failureAudit->occurred_at);
        $this->assertSame('2029-02-28 03:15:00', $failureAudit->expires_at);
        $failureContext = json_decode((string) $failureAudit->context, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['topic', 'channel', 'attempt', 'error_code'], array_keys($failureContext));
        $this->assertSame('synthetic_provider_unavailable', $failureContext['error_code']);

        Date::setTestNow('2024-02-29 10:15:31+07:00');
        $delivery->handle($messageId);
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
        $audits = DB::table('audit_logs')->orderBy('id')->get();
        $this->assertSame(
            ['participant_notification.failed', 'participant_notification.delivered'],
            $audits->pluck('action')->all(),
        );
        $this->assertSame('2024-02-29 03:15:31', $audits[1]->occurred_at);
        $this->assertSame('2029-02-28 03:15:31', $audits[1]->expires_at);
        $deliveryContext = json_decode((string) $audits[1]->context, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['topic', 'channel', 'attempt'], array_keys($deliveryContext));
        $encodedAudits = json_encode($audits, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        foreach (['Ayu Pratiwi', '+6281234567890', 'LSI-202608-000001-ABCDEF'] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $encodedAudits);
        }
        $failureLog = $logger->records[0];
        $this->assertSame('warning', $failureLog['level']);
        $this->assertSame('participant_notification_delivery', $failureLog['message']);
        $this->assertSame('synthetic_provider_unavailable', $failureLog['context']['error_code']);
        $this->assertNotContains('+6281234567890', $failureLog['context']);
        $this->assertNotContains('LSI-202608-000001-ABCDEF', $failureLog['context']);
    }

    public function test_expired_outbox_is_never_delivered(): void
    {
        [, $messageId] = $this->paidOrderAndOutbox();
        DB::table('outbox_messages')->where('message_id', $messageId)->update([
            'expires_at' => now(),
        ]);
        $notifier = new FakeNotifier;
        $this->app->instance(Notifier::class, $notifier);

        app(DeliverParticipantActivation::class)->handle($messageId);

        $this->assertSame([], $notifier->delivered());
        $this->assertDatabaseHas('outbox_messages', [
            'message_id' => $messageId,
            'status' => 'pending',
            'attempts' => 0,
        ]);
        $this->assertDatabaseCount('audit_logs', 0);
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
        $package = TestPackage::query()->create([
            'code' => 'ACTIVATION_V1',
            'name' => 'Activation Package',
            'amount' => 250_000,
            'currency' => 'IDR',
            'is_active' => true,
        ]);
        foreach (['ist', 'papi', 'dass21'] as $testType) {
            $package->items()->create(['test_type' => $testType]);
        }
        $participant = Participant::query()->create([
            'branch_id' => $branch->id,
            'referral_branch_id' => $branch->id,
            'referral_source' => 'default',
            'package_id' => $package->id,
            'source_system' => 'DIRECT_PUBLIC',
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
        $orderPublicId = (string) Str::ulid();
        $case = AssessmentCase::query()->create([
            'public_id' => $orderPublicId,
            'participant_id' => $participant->id,
            'organization_id' => $branch->id,
            'package_id' => $package->id,
            'origin' => 'DIRECT_PUBLIC',
            'intended_field_snapshot' => 'KAIGO',
        ]);
        $order = Order::query()->create([
            'public_id' => $orderPublicId,
            'participant_id' => $participant->id,
            'assessment_case_id' => $case->id,
            'payment_method_id' => $methodId,
            'status' => 'paid',
            'amount' => 250_000,
            'currency' => 'IDR',
            'paid_at' => now(),
        ]);
        $entitlements = collect(['ist', 'papi'])->map(fn (string $testType): Entitlement => Entitlement::query()->create([
            'participant_id' => $participant->id,
            'order_id' => $order->id,
            'assessment_case_id' => $case->id,
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
