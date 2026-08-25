<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Notifications\DispatchNotificationOutbox;
use Illuminate\Console\Command;

final class DispatchNotificationOutboxCommand extends Command
{
    protected $signature = 'notifications:dispatch-outbox {--limit=100}';

    protected $description = 'Dispatch due participant notification outbox messages';

    public function handle(DispatchNotificationOutbox $dispatch): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);

        if (! is_int($limit) || $limit < 1 || $limit > 500) {
            $this->error('The limit must be an integer between 1 and 500.');

            return self::INVALID;
        }

        $count = $dispatch->handle($limit);
        $this->info("Dispatched {$count} notification message(s).");

        return self::SUCCESS;
    }
}
