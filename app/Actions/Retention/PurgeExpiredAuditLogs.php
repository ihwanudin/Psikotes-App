<?php

declare(strict_types=1);

namespace App\Actions\Retention;

use App\Security\RlsContextRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

final readonly class PurgeExpiredAuditLogs
{
    private const int MAX_BATCH_SIZE = 1000;

    public function __construct(private RlsContextRunner $contexts) {}

    public function execute(int $batchSize): int
    {
        if ($batchSize < 1 || $batchSize > self::MAX_BATCH_SIZE) {
            throw new InvalidArgumentException('Audit retention batch size must be between 1 and 1000.');
        }

        if ($this->contexts->current()?->role !== 'service' || DB::transactionLevel() < 1) {
            throw new LogicException('Audit retention requires an active service transaction.');
        }

        $cutoff = CarbonImmutable::now('UTC');

        /** @var list<int> $ids */
        $ids = DB::table('audit_logs')
            ->where('expires_at', '<=', $cutoff)
            ->orderBy('id')
            ->limit($batchSize)
            ->lockForUpdate()
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($ids === []) {
            return 0;
        }

        $deleted = DB::table('audit_logs')
            ->whereIn('id', $ids)
            ->where('expires_at', '<=', $cutoff)
            ->delete();

        if ($deleted !== count($ids)) {
            throw new RuntimeException('Audit retention batch changed during deletion.');
        }

        return $deleted;
    }
}
