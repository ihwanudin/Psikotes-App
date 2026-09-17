<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Retention\PurgeExpiredAuditLogs;
use App\Security\RlsContextRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PurgeExpiredAuditLogsCommand extends Command
{
    protected $signature = 'retention:purge-expired-audits {--limit=1000}';

    protected $description = 'Purge one bounded batch of expired audit logs';

    public function handle(PurgeExpiredAuditLogs $purge, RlsContextRunner $contexts): int
    {
        $limit = $this->parseLimit($this->option('limit'));

        if ($limit === null) {
            return $this->reject('AUDIT_RETENTION_LIMIT_INVALID');
        }

        $enabled = config('retention.audit_purge_enabled');

        if ($enabled !== true) {
            return $this->reject(
                $enabled === null || $enabled === false
                    ? 'AUDIT_RETENTION_DISABLED'
                    : 'AUDIT_RETENTION_CONFIGURATION_INVALID',
            );
        }

        Log::info('Audit retention purge invocation started.', [
            'batchLimit' => $limit,
        ]);

        try {
            $deleted = $contexts->runAsService(
                fn (): int => $purge->execute($limit),
            );
        } catch (Throwable) {
            Log::error('Audit retention purge invocation failed.', [
                'reasonCode' => 'AUDIT_RETENTION_EXECUTION_FAILED',
                'batchLimit' => $limit,
            ]);
            $this->error('Audit retention purge failed. Inspect application logs before retrying.');

            return self::FAILURE;
        }

        Log::info('Audit retention purge invocation completed.', [
            'batchLimit' => $limit,
            'deletedCount' => $deleted,
            'batchSaturated' => $deleted === $limit,
        ]);
        $this->info("Purged {$deleted} expired audit log(s).");

        return self::SUCCESS;
    }

    private function parseLimit(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 1 && $value <= 1000 ? $value : null;
        }

        if (! is_string($value) || preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1) {
            return null;
        }

        $limit = (int) $value;

        return $limit >= 1 && $limit <= 1000 ? $limit : null;
    }

    private function reject(string $reasonCode): int
    {
        Log::warning('Audit retention purge invocation rejected.', [
            'reasonCode' => $reasonCode,
        ]);
        $this->error('Audit retention purge configuration or limit is invalid.');

        return self::INVALID;
    }
}
