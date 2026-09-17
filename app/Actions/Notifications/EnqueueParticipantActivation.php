<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Models\Order;
use App\Security\RlsContextRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final readonly class EnqueueParticipantActivation
{
    private const string TOPIC = 'participant.activation';

    public function __construct(private RlsContextRunner $runner) {}

    public function handle(Order $order): void
    {
        if ($this->runner->current()?->role !== 'service') {
            throw new LogicException('Notification outbox writes require an active service transaction.');
        }

        $now = now()->utc();

        DB::table('outbox_messages')->insertOrIgnore([
            'message_id' => (string) Str::ulid(),
            'deduplication_key' => hash(
                'sha256',
                self::TOPIC.'|'.Order::class.'|'.$order->public_id,
            ),
            'topic' => self::TOPIC,
            'aggregate_type' => Order::class,
            'aggregate_id' => $order->public_id,
            'payload' => json_encode(['schema_version' => 1], JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => $now,
            'processed_at' => null,
            'expires_at' => $now->addYears(2),
            'last_error' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
