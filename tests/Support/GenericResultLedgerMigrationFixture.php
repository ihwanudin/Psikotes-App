<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use RuntimeException;

final class GenericResultLedgerMigrationFixture
{
    private function __construct(
        private readonly ?object $migration,
        private bool $restoreRequired,
    ) {}

    public static function withoutLedger(callable $callback): mixed
    {
        $connection = DB::connection();
        $entryTransactionLevel = $connection->transactionLevel();
        $fixture = self::suspend();

        try {
            return $callback();
        } finally {
            while ($connection->transactionLevel() > $entryTransactionLevel) {
                $connection->rollBack();
            }
            $fixture->restore();
        }
    }

    public static function suspend(): self
    {
        $parentExists = Schema::hasTable('generic_instrument_results');
        $childExists = Schema::hasTable('generic_instrument_result_sources');
        $supportIndexExists = (bool) DB::scalar(<<<'SQL'
            SELECT EXISTS (
                SELECT 1 FROM pg_indexes
                WHERE schemaname = 'public'
                  AND indexname = 'instrument_versions_result_scope_unique'
            )
            SQL);

        if (! $parentExists && ! $childExists && ! $supportIndexExists) {
            return new self(null, false);
        }
        if (! $parentExists || ! $childExists || ! $supportIndexExists) {
            throw new RuntimeException('Partial generic result ledger cannot be suspended for a historical fixture.');
        }

        $migration = require database_path(
            'migrations/2026_09_13_000100_create_generic_instrument_result_ledger.php',
        );
        if (! is_object($migration) || ! method_exists($migration, 'down') || ! method_exists($migration, 'up')) {
            throw new RuntimeException('Generic result ledger migration fixture is unavailable.');
        }
        (new ReflectionMethod($migration, 'down'))->invoke($migration);

        return new self($migration, true);
    }

    public function restore(): void
    {
        if (! $this->restoreRequired) {
            return;
        }

        if ($this->migration === null) {
            throw new RuntimeException('Generic result ledger migration fixture cannot be restored.');
        }
        (new ReflectionMethod($this->migration, 'up'))->invoke($this->migration);
        $this->restoreRequired = false;
    }
}
