<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Integrations\GenericAssessmentResultCallbackConfiguration;
use App\Services\Integrations\GenericAssessmentResultCallbackOrchestrator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DispatchGenericAssessmentResultCallbacksCommand extends Command
{
    protected $signature = 'integrations:dispatch-generic-result-callbacks {--limit=25}';

    protected $description = 'Dispatch bounded generic assessment result callbacks to Selection';

    public function handle(
        GenericAssessmentResultCallbackOrchestrator $orchestrator,
        GenericAssessmentResultCallbackConfiguration $configuration,
    ): int {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (! is_int($limit) || $limit < 1 || $limit > 100) {
            return $this->reject('CALLBACK_LIMIT_INVALID');
        }
        if (! (bool) config('selection_integration.result_callback_enabled')) {
            return $this->reject('CALLBACK_DISABLED');
        }
        if (! $configuration->isValid()) {
            return $this->reject('CALLBACK_CONFIGURATION_INVALID');
        }

        Log::info('Generic assessment result callback invocation started.', ['limit' => $limit]);

        try {
            $result = $orchestrator->schedule($limit);
        } catch (Throwable) {
            Log::error('Generic assessment result callback invocation failed.', [
                'reasonCode' => 'CALLBACK_INVOCATION_FAILED',
                'limit' => $limit,
                'deliveryOutcome' => 'MAY_BE_PARTIAL',
            ]);
            $this->error('Callback orchestration failed; delivery outcome may be partial. Inspect durable callback schedules before retrying.');

            return self::FAILURE;
        }

        $context = [
            'selected' => $result['selected'],
            'brokerAccepted' => $result['queued'],
            'brokerFailures' => $result['brokerFailures'],
        ];
        if ($result['brokerFailures'] > 0) {
            Log::warning('Generic assessment result callback invocation completed with broker failures.', $context);
            $this->error("Broker accepted {$result['queued']} callback job(s); {$result['brokerFailures']} broker failure(s).");

            return self::FAILURE;
        }

        Log::info('Generic assessment result callback invocation completed.', $context);
        $this->info("Broker accepted {$result['queued']} callback job(s) from {$result['selected']} selected.");

        return self::SUCCESS;
    }

    private function reject(string $reasonCode): int
    {
        Log::warning('Generic assessment result callback invocation rejected.', [
            'reasonCode' => $reasonCode,
        ]);
        $this->error('Callback dispatch configuration or limit is invalid.');

        return self::INVALID;
    }
}
