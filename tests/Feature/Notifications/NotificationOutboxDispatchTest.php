<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Jobs\DeliverOutboxMessage;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class NotificationOutboxDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-08-25 15:00:00+07:00');
    }

    public function test_command_dispatches_only_due_and_recoverable_messages(): void
    {
        Queue::fake();
        $pending = $this->outbox('pending', now());
        $failed = $this->outbox('failed', now()->subMinute());
        $stale = $this->outbox('processing', now()->subMinute(), now()->subMinutes(11));
        $this->outbox('failed', now()->addMinute());
        $this->outbox('processing', now()->subMinute(), now()->subMinutes(9));
        $this->outbox('processed', now()->subMinute());
        $this->outbox('failed', now()->subMinute(), attempts: 5);

        $exitCode = Artisan::call('notifications:dispatch-outbox', ['--limit' => 100]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Dispatched 3 notification message(s).', Artisan::output());

        Queue::assertPushed(DeliverOutboxMessage::class, 3);
        Queue::assertPushed(fn (DeliverOutboxMessage $job): bool => $job->messageId === $pending);
        Queue::assertPushed(fn (DeliverOutboxMessage $job): bool => $job->messageId === $failed);
        Queue::assertPushed(fn (DeliverOutboxMessage $job): bool => $job->messageId === $stale);
    }

    public function test_dispatch_limit_is_validated_and_applied(): void
    {
        Queue::fake();
        $first = $this->outbox('pending', now());
        $this->outbox('pending', now());

        $this->assertSame(
            Command::SUCCESS,
            Artisan::call('notifications:dispatch-outbox', ['--limit' => 1]),
        );

        Queue::assertPushed(DeliverOutboxMessage::class, 1);
        Queue::assertPushed(fn (DeliverOutboxMessage $job): bool => $job->messageId === $first);

        $this->assertSame(
            Command::INVALID,
            Artisan::call('notifications:dispatch-outbox', ['--limit' => 0]),
        );
    }

    public function test_delivery_job_has_bounded_retry_and_unique_identity(): void
    {
        $messageId = (string) Str::ulid();
        $job = new DeliverOutboxMessage($messageId);

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame($messageId, $job->uniqueId());
        $this->assertSame(5, $job->tries);
        $this->assertSame([30, 120, 600, 1800], $job->backoff());
        $this->assertSame('notifications', $job->queue);
    }

    public function test_dispatch_is_scheduled_each_minute_without_overlap(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains($event->command ?? '', 'notifications:dispatch-outbox'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }

    private function outbox(string $status, mixed $availableAt, mixed $updatedAt = null, ?int $attempts = null): string
    {
        $messageId = (string) Str::ulid();
        DB::table('outbox_messages')->insert([
            'message_id' => $messageId,
            'deduplication_key' => hash('sha256', $messageId),
            'topic' => 'participant.activation',
            'aggregate_type' => Order::class,
            'aggregate_id' => (string) Str::ulid(),
            'payload' => json_encode(['schema_version' => 1], JSON_THROW_ON_ERROR),
            'status' => $status,
            'attempts' => $attempts ?? ($status === 'pending' ? 0 : 1),
            'available_at' => $availableAt,
            'processed_at' => $status === 'processed' ? now() : null,
            'expires_at' => now()->addYear(),
            'created_at' => now(),
            'updated_at' => $updatedAt ?? now(),
        ]);

        return $messageId;
    }
}
