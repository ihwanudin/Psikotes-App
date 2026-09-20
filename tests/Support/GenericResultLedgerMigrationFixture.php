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

        // suspend()/restore() rebuild generic_instrument_result_sources via
        // ONLY 2026_09_13_000100_create_generic_instrument_result_ledger.php's
        // own createTables(), which knows nothing about later migrations that
        // also alter this table's shape. Left alone, that rebuild would
        // silently revert those later changes for the rest of this database's
        // life — a real reversion (columns actually change type back), not
        // just a stale check — and the failure would surface far away, in an
        // unrelated test, as a confusing type error. Re-apply every such
        // migration here, explicitly, immediately after the rebuild above.
        (require database_path(
            'migrations/2026_09_20_000200_widen_generic_instrument_result_source_fractional_columns.php',
        ))->up();
        $this->assertRebuiltStateMatchesAllKnownMigrations();

        $this->restoreRequired = false;
    }

    /**
     * Fails loudly, with the exact fix, instead of letting a future migration
     * on this table be silently reverted by this fixture. If you added a
     * migration that changes generic_instrument_result_sources/
     * generic_instrument_results after 2026_09_13_000100 and this throws:
     * add that migration's ->up() call in restore() above (next to the
     * 2026_09_20_000200 call), in the same order migrations actually run.
     */
    private function assertRebuiltStateMatchesAllKnownMigrations(): void
    {
        $columns = collect(DB::select(<<<'SQL'
            SELECT attname, format_type(atttypid, atttypmod) type
            FROM pg_attribute
            WHERE attrelid = 'generic_instrument_result_sources'::regclass
              AND attnum > 0 AND NOT attisdropped
            SQL))->pluck('type', 'attname');
        $expected = [
            'raw_score' => 'numeric(8,3)', 'band_low' => 'numeric(8,3)', 'band_high' => 'numeric(8,3)',
        ];
        $mismatches = [];
        foreach ($expected as $column => $expectedType) {
            $actualType = $columns[$column] ?? null;
            if ($actualType !== $expectedType) {
                $mismatches[] = "{$column}: expected {$expectedType}, got ".($actualType ?? 'MISSING');
            }
        }
        if ($mismatches !== []) {
            throw new RuntimeException(
                'GenericResultLedgerMigrationFixture::restore() only rebuilt generic_instrument_result_sources '
                .'back to migration 2026_09_13_000100\'s original shape. Another migration changes this table '
                .'and is not (yet, or no longer correctly) re-applied here — add its ->up() call in restore(), '
                .'right after the rebuild. Mismatched column(s): '.implode('; ', $mismatches).'.',
            );
        }
    }
}
