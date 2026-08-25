<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Notifications\DeliverParticipantActivation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

#[UniqueFor(900)]
final class DeliverOutboxMessage implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly string $messageId)
    {
        $this->onQueue('notifications');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 600, 1_800];
    }

    public function uniqueId(): string
    {
        return $this->messageId;
    }

    public function handle(DeliverParticipantActivation $delivery): void
    {
        $delivery->handle($this->messageId);
    }
}
