<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F2 lane G7 (2026-09-20). Lead-approved direction: `raw_score`, `band_low`,
 * and `band_high` on `generic_instrument_result_sources`
 * (database/migrations/2026_09_13_000100_create_generic_instrument_result_ledger.php:148,152-153)
 * were `integer`, but Kraepelin's Panker and Hanker factors are fractional by
 * design — SCORING_ALGORITHM.md's golden test requires "Panker 15,86...
 * Hanker -0,622", and database/seeders/data/kraepelin.json's norm band
 * boundaries include values like `16.7`, `15.485`, `-0.761`. Storing them as
 * integer would silently truncate psychometric data the golden test itself
 * checks to three decimal places.
 *
 * `standard_score` and `source_score` are deliberately NOT widened here: for
 * Kraepelin they are both populated from `KraepelinBandMapper`'s `source_score`
 * (already a native integer, 1-10) — see
 * tasks/handoffs/f2/generic-instrument-result-field-mapping.md. No instrument
 * puts a fractional value in either column, so widening them would only add
 * unused precision.
 *
 * numeric(8,3) covers every value these columns need: eight total digits,
 * three after the decimal point (matching `factor_rounding.precision` in
 * kraepelin.json), room for five integer digits — the existing IST/PAPI/RMIB
 * integer values (single/double digit raw scores, small band bounds) fit
 * this domain exactly as before with zero fractional digits.
 *
 * SECOND FIX, found only by running the real insert in PostgreSQL (proof:
 * tests/Postgres/GenericInstrumentResultSourceFractionalColumnsTest.php —
 * this failed with SQLSTATE 23514 before this fix landed): the PostgreSQL
 * `generic_instrument_result_sources_contract_check` constraint
 * (2026_09_13_000100_create_generic_instrument_result_ledger.php:190) also
 * requires `raw_score >= 0`, written when every raw score was a non-negative
 * count. Kraepelin's Hanker is routinely negative (golden test: -0,622), so
 * that clause is dropped here — every other clause is preserved verbatim.
 *
 * DROPPED GLOBALLY, NOT REPLACED WITH PER-INSTRUMENT SQL (Lead-approved
 * 2026-09-20): `generic_instrument_result_sources` has no instrument-code
 * column to make a database-level rule conditional on ("`raw_score >= 0 OR
 * source_code = 'HANKER'`" was considered and rejected — baking one
 * instrument's vocabulary into a generic table's constraint ages badly).
 * The non-negative guard for IST/PAPI/RMIB — and the negative-only-for-
 * Hanker guard for Kraepelin — now live in each instrument's own Sealed
 * result class instead: SealedIstResult, SealedPapiResult, SealedRmibResult
 * (already rejected negative rawScore before this migration; proven by
 * tests/Unit/AssessmentResults/Sealed{Ist,Papi,Rmib}ResultTest.php) and
 * SealedKraepelinResult (rejects negative for PANKER/TIANKER/JANKER,
 * accepts it only for HANKER; proven by
 * tests/Unit/AssessmentResults/SealedKraepelinResultTest.php). If you are
 * reading this because you are about to restore `raw_score >= 0` globally:
 * don't — it will reject every real Hanker value again. The guard is not
 * missing, it moved.
 * SQLite has no equivalent CHECK constraint for this table (its own contract
 * is trigger-only, see 2026_09_13_000100's addSqliteContract()), so nothing
 * changes there.
 */
return new class extends Migration
{
    private const TABLE = 'generic_instrument_result_sources';

    /**
     * Verbatim copy of 2026_09_13_000100's
     * generic_instrument_result_sources_contract_check, with `raw_score >= 0`
     * removed. Every other clause is unchanged.
     */
    private const CONTRACT_CHECK_SQL_WITHOUT_NON_NEGATIVE_RAW_SCORE = <<<'SQL'
        ALTER TABLE generic_instrument_result_sources
            ADD CONSTRAINT generic_instrument_result_sources_contract_check CHECK (
                ordinal > 0 AND level BETWEEN 1 AND 5
                AND length(source_code) BETWEEN 1 AND 32
                AND source_code = btrim(source_code) AND source_code !~ '[[:space:][:cntrl:]]'
                AND length(btrim(category)) > 0
                AND (band_low IS NOT NULL OR band_high IS NOT NULL)
                AND (band_low IS NULL OR band_high IS NULL OR band_low <= band_high)
            );
        SQL;

    /** The original 2026_09_13_000100 clause, restored on down(). */
    private const CONTRACT_CHECK_SQL_WITH_NON_NEGATIVE_RAW_SCORE = <<<'SQL'
        ALTER TABLE generic_instrument_result_sources
            ADD CONSTRAINT generic_instrument_result_sources_contract_check CHECK (
                ordinal > 0 AND raw_score >= 0 AND level BETWEEN 1 AND 5
                AND length(source_code) BETWEEN 1 AND 32
                AND source_code = btrim(source_code) AND source_code !~ '[[:space:][:cntrl:]]'
                AND length(btrim(category)) > 0
                AND (band_low IS NOT NULL OR band_high IS NOT NULL)
                AND (band_low IS NULL OR band_high IS NULL OR band_low <= band_high)
            );
        SQL;

    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            $this->widenPostgres();

            return;
        }

        $this->widenSqlite();
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        $populated = (int) DB::table(self::TABLE)->count();
        if ($populated > 0) {
            throw new RuntimeException(
                "Cannot narrow generic_instrument_result_sources' fractional columns while {$populated} row(s) exist; downgrade refused.",
            );
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE '.self::TABLE.' ALTER COLUMN raw_score TYPE integer');
            DB::statement('ALTER TABLE '.self::TABLE.' ALTER COLUMN band_low TYPE integer');
            DB::statement('ALTER TABLE '.self::TABLE.' ALTER COLUMN band_high TYPE integer');
            DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT generic_instrument_result_sources_contract_check');
            DB::unprepared(self::CONTRACT_CHECK_SQL_WITH_NON_NEGATIVE_RAW_SCORE);

            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->integer('raw_score')->change();
            $table->integer('band_low')->nullable()->change();
            $table->integer('band_high')->nullable()->change();
        });
        $this->recreateSqliteTriggers();
    }

    private function widenPostgres(): void
    {
        // ALTER COLUMN TYPE in place: no table rebuild, so triggers, RLS
        // policies, and grants are all untouched. The CHECK constraint is
        // handled separately below (see this file's docblock, "SECOND FIX").
        DB::statement('ALTER TABLE '.self::TABLE.' ALTER COLUMN raw_score TYPE numeric(8,3) USING raw_score::numeric(8,3)');
        DB::statement('ALTER TABLE '.self::TABLE.' ALTER COLUMN band_low TYPE numeric(8,3) USING band_low::numeric(8,3)');
        DB::statement('ALTER TABLE '.self::TABLE.' ALTER COLUMN band_high TYPE numeric(8,3) USING band_high::numeric(8,3)');
        DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT generic_instrument_result_sources_contract_check');
        DB::unprepared(self::CONTRACT_CHECK_SQL_WITHOUT_NON_NEGATIVE_RAW_SCORE);
    }

    /**
     * SQLite has no ALTER COLUMN TYPE; Laravel's Blueprint::change() rebuilds
     * the table (copy into a new table, drop the old one, rename). That
     * rebuild does not know about this table's hand-written guard triggers
     * (database/migrations/2026_09_13_000100_create_generic_instrument_result_ledger.php:298-301,
     * created via raw DB::unprepared, not the schema builder) and would
     * silently drop them. They are recreated verbatim immediately after.
     */
    private function widenSqlite(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->decimal('raw_score', 8, 3)->change();
            $table->decimal('band_low', 8, 3)->nullable()->change();
            $table->decimal('band_high', 8, 3)->nullable()->change();
        });
        $this->recreateSqliteTriggers();
    }

    private function recreateSqliteTriggers(): void
    {
        $existing = DB::table('sqlite_master')
            ->where('type', 'trigger')
            ->whereIn('name', [
                'generic_instrument_result_sources_guard_update',
                'generic_instrument_result_sources_guard_delete',
            ])
            ->count();
        if ($existing > 0) {
            // The rebuild preserved them after all on this SQLite version; do
            // not create duplicates.
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER generic_instrument_result_sources_guard_update
            BEFORE UPDATE ON generic_instrument_result_sources FOR EACH ROW
            BEGIN SELECT RAISE(ABORT, 'generic instrument result source history is append-only'); END;
            CREATE TRIGGER generic_instrument_result_sources_guard_delete
            BEFORE DELETE ON generic_instrument_result_sources FOR EACH ROW
            BEGIN SELECT RAISE(ABORT, 'generic instrument result source history is append-only'); END;
            SQL);
    }
};
