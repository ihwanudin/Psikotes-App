<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Security\RlsContextRunner;
use App\Services\Integrations\ReconcileUnknownCallback;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ReconcileIntegrationCallbacksCommand extends Command
{
    protected $signature = 'integrations:reconcile-callbacks {--limit=100}';

    protected $description = 'Reconcile callback deliveries whose remote outcome is unknown';

    public function handle(RlsContextRunner $runner, ReconcileUnknownCallback $reconcile): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (! is_int($limit) || $limit < 1 || $limit > 500) {
            return self::INVALID;
        }
        $ids = $runner->runAsService(fn (): array => DB::table('integration_callback_deliveries')
            ->where('status', 'UNKNOWN')->orderBy('id')->limit($limit)->pluck('event_id')->all());
        foreach ($ids as $id) {
            $reconcile->handle((string) $id);
        }
        $this->info('Reconciled '.count($ids).' unknown callback(s).');

        return self::SUCCESS;
    }
}
