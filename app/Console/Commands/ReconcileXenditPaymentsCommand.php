<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Payments\ReconcilePendingXenditPayments;
use Illuminate\Console\Command;

final class ReconcileXenditPaymentsCommand extends Command
{
    protected $signature = 'payments:reconcile-xendit {--limit=100}';

    protected $description = 'Reconcile pending Xendit invoices through the status API';

    public function handle(ReconcilePendingXenditPayments $reconcile): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);

        if (! is_int($limit) || $limit < 1 || $limit > 500) {
            $this->error('The limit must be an integer between 1 and 500.');

            return self::INVALID;
        }

        $result = $reconcile->handle($limit);
        $this->info("Checked {$result->checked}; applied {$result->applied}; failed {$result->failed}.");

        return $result->failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
