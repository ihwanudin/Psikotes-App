<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Integrations\GenericAssessmentResultCallbackOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

#[UniqueFor(300)]
final class DispatchGenericAssessmentResultCallback implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 45;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $scheduleId)
    {
        $this->onQueue('integrations');
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->scheduleId;
    }

    public function handle(GenericAssessmentResultCallbackOrchestrator $orchestrator): void
    {
        $orchestrator->execute($this->scheduleId);
    }

    public function failed(?Throwable $exception): void
    {
        app(GenericAssessmentResultCallbackOrchestrator::class)->recordWorkerFailure($this->scheduleId);
    }
}
