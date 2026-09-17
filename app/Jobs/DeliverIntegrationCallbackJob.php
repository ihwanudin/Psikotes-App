<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Integrations\DeliverIntegrationCallback;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

#[UniqueFor(900)]
final class DeliverIntegrationCallbackJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly string $eventId)
    {
        $this->onQueue('integrations');
    }

    public function uniqueId(): string
    {
        return $this->eventId;
    }

    public function handle(DeliverIntegrationCallback $delivery): void
    {
        $delivery->handle($this->eventId);
    }
}
