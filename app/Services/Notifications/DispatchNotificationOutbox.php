<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Jobs\DeliverOutboxMessage;
use App\Models\OutboxMessage;
use App\Security\RlsContextRunner;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

final readonly class DispatchNotificationOutbox
{
    public function __construct(private RlsContextRunner $runner) {}

    public function handle(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('Notification dispatch limit must be between 1 and 500.');
        }

        $now = now()->toImmutable()->utc();
        $messageIds = $this->runner->runAsService(fn (): array => OutboxMessage::query()
            ->where('topic', 'participant.activation')
            ->where('attempts', '<', 5)
            ->where('available_at', '<=', $now)
            ->where('expires_at', '>', $now)
            ->where(function (Builder $query) use ($now): void {
                $query->whereIn('status', ['pending', 'failed'])
                    ->orWhere(function (Builder $query) use ($now): void {
                        $query->where('status', 'processing')
                            ->where('updated_at', '<=', $now->subMinutes(10));
                    });
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('message_id')
            ->filter(fn (mixed $messageId): bool => is_string($messageId))
            ->values()
            ->all());

        foreach ($messageIds as $messageId) {
            DeliverOutboxMessage::dispatch($messageId);
        }

        return count($messageIds);
    }
}
