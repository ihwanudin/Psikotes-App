<?php

declare(strict_types=1);

namespace Tests\Postgres;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\GenericResultLedgerMigrationFixture;

/**
 * Migration mechanics for F2 lane increment 3a
 * (database/migrations/2026_09_20_000100_add_source_text_to_instrument_versions.php),
 * mirroring the up()/down() transaction-wrapped drive pattern already
 * established in InstrumentVersionHistorySecurityTest for the sibling hardening
 * migration. Every scenario here runs inside a transaction that is always
 * rolled back, so it never disturbs the real post-bootstrap migrated schema
 * that other PostgreSQL tests in this suite depend on.
 */
final class InstrumentVersionsSourceTextMigrationTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->asOwner(function (): void {
            DB::statement('TRUNCATE TABLE instrument_versions RESTART IDENTITY');
        });

        parent::tearDown();
    }

    /**
     * Lead-required guard (b): a missing source file on disk must not fail the
     * migration — the row is left with a NULL source_text and the migration
     * continues. This also proves the positive case (an on-disk file whose
     * content still hashes to the stored checksum IS backfilled) and the
     * superseded-content case (an on-disk file that no longer matches the
     * stored checksum is skipped, not silently mismatched).
     */
    public function test_backfill_tolerates_a_missing_source_file_and_only_fills_byte_identical_rows(): void
    {
        $this->asOwner(function (): void {
            DB::statement('TRUNCATE TABLE instrument_versions RESTART IDENTITY');

            $migration = require database_path('migrations/2026_09_20_000100_add_source_text_to_instrument_versions.php');

            DB::beginTransaction();
            try {
                // Revert to the pre-3a schema (table is empty, so down() is not refused).
                $migration->down();
                $this->assertFalse(Schema::hasColumn('instrument_versions', 'source_text'));

                $realBytes = file_get_contents(database_path('seeders/data/dass21.json'));
                if ($realBytes === false) {
                    throw new RuntimeException('Canonical DASS-21 fixture could not be read for this test.');
                }

                // Row A: on-disk file exists and its current bytes hash to exactly the
                // stored checksum — must be backfilled.
                DB::table('instrument_versions')->insert([
                    'code' => 'backfillproof', 'version' => 'file-match-v1',
                    'source_file' => 'dass21.json', 'checksum' => hash('sha256', $realBytes),
                    'payload' => $realBytes, 'is_active' => false,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                // Row B: source_file does not exist on disk at all — must be skipped,
                // not thrown.
                DB::table('instrument_versions')->insert([
                    'code' => 'backfillproof', 'version' => 'file-missing-v1',
                    'source_file' => 'this-file-does-not-exist-anywhere.json', 'checksum' => str_repeat('a', 64),
                    'payload' => '{}', 'is_active' => false,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                // Row C: source_file exists, but its current on-disk content no longer
                // matches the stored checksum (the version this row recorded has since
                // been superseded) — must be skipped, not backfilled with the wrong bytes.
                DB::table('instrument_versions')->insert([
                    'code' => 'backfillproof', 'version' => 'hash-mismatch-v1',
                    'source_file' => 'dass21.json', 'checksum' => hash('sha256', 'superseded-historical-content'),
                    'payload' => '{}', 'is_active' => false,
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                $migration->up();

                $this->assertTrue(Schema::hasColumn('instrument_versions', 'source_text'));
                $rows = DB::table('instrument_versions')->where('code', 'backfillproof')
                    ->orderBy('version')->get(['version', 'source_text']);

                $this->assertSame($realBytes, $rows->firstWhere('version', 'file-match-v1')->source_text);
                $this->assertNull($rows->firstWhere('version', 'file-missing-v1')->source_text);
                $this->assertNull($rows->firstWhere('version', 'hash-mismatch-v1')->source_text);

                // The immutability trigger must be back in force after up(), with
                // source_text now included in the frozen tuple. Triggers fire
                // regardless of which role performs the write — including the
                // table owner used by this test — so no RLS context is needed
                // here to prove the lock.
                try {
                    DB::table('instrument_versions')->where('version', 'file-match-v1')
                        ->update(['source_text' => 'attempted-mutation']);
                    $this->fail('source_text must be locked by the history trigger after up().');
                } catch (QueryException $exception) {
                    $this->assertSame('P0001', $exception->errorInfo[0] ?? null, $exception->getMessage());
                }
            } finally {
                DB::rollBack();
            }
        });
    }

    public function test_down_refuses_when_any_row_carries_source_text(): void
    {
        $this->asOwner(function (): void {
            DB::statement('TRUNCATE TABLE instrument_versions RESTART IDENTITY');

            $migration = require database_path('migrations/2026_09_20_000100_add_source_text_to_instrument_versions.php');

            DB::beginTransaction();
            try {
                DB::table('instrument_versions')->insert([
                    'code' => 'populated', 'version' => 'v1',
                    'source_file' => 'dass21.json', 'checksum' => str_repeat('b', 64),
                    'payload' => '{}', 'source_text' => 'non-null-value', 'is_active' => false,
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                try {
                    $migration->down();
                    $this->fail('Populated source_text must refuse the downgrade.');
                } catch (RuntimeException $exception) {
                    $this->assertStringContainsString('source_text', $exception->getMessage());
                }
                $this->assertTrue(Schema::hasColumn('instrument_versions', 'source_text'));
            } finally {
                DB::rollBack();
            }
        });
    }

    private function asOwner(callable $callback): void
    {
        $runtime = DB::getDefaultConnection();
        if ($runtime === 'instrument_source_text_migration_owner') {
            GenericResultLedgerMigrationFixture::withoutLedger(fn (): mixed => $callback(DB::connection()));

            return;
        }
        $config = config('database.connections.'.$runtime);
        config()->set('database.connections.instrument_source_text_migration_owner', [...$config, 'username' => 'org_test_owner']);
        DB::setDefaultConnection('instrument_source_text_migration_owner');
        Schema::clearResolvedInstance('db.schema');

        try {
            $owner = DB::connection();
            $this->assertSame('org_test_owner', $owner->selectOne('SELECT current_user AS name')->name);
            GenericResultLedgerMigrationFixture::withoutLedger(fn (): mixed => $callback($owner));
        } finally {
            DB::setDefaultConnection($runtime);
            Schema::clearResolvedInstance('db.schema');
            DB::purge('instrument_source_text_migration_owner');
            config()->set('database.connections.instrument_source_text_migration_owner', null);
        }
    }
}
