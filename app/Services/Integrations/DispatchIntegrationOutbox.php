<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Jobs\DeliverIntegrationCallbackJob;
use App\Models\OutboxMessage;
use App\Security\RlsContextRunner;
use InvalidArgumentException;

final readonly class DispatchIntegrationOutbox
{
    public function __construct(private RlsContextRunner $runner) {}

    public function handle(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('Integration dispatch limit must be between 1 and 500.');
        }

        $ids = $this->runner->runAsService(fn (): array => OutboxMessage::query()
            ->where('topic', 'psychotest.assessment-event')
            ->whereIn('status', ['pending', 'failed'])
            ->where('attempts', '<', 5)
            ->where('available_at', '<=', now())
            ->where('expires_at', '>', now())
            ->orderBy('id')->limit($limit)->pluck('message_id')->all());

        foreach ($ids as $id) {
            DeliverIntegrationCallbackJob::dispatch((string) $id);
        }

        return count($ids);
    }
}
