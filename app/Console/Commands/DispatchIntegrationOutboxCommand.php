<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Integrations\DispatchIntegrationOutbox;
use Illuminate\Console\Command;

final class DispatchIntegrationOutboxCommand extends Command
{
    protected $signature = 'integrations:dispatch-outbox {--limit=100}';

    protected $description = 'Dispatch due assessment integration callback events';

    public function handle(DispatchIntegrationOutbox $dispatch): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (! is_int($limit) || $limit < 1 || $limit > 500) {
            return self::INVALID;
        }

        $this->info('Dispatched '.$dispatch->handle($limit).' integration event(s).');

        return self::SUCCESS;
    }
}
