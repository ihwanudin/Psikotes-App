<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0032 PR1 (2026-09-22). `generic_instrument_results` (2026_09_13_000100)
 * ties a result to the INSTRUMENT DATA version (`instrument_version_id`/
 * `instrument_checksum` -- norms, keys, rotation) but has no field tying it
 * to the SCORING CODE version that computed it. Three separate "version"
 * concepts already exist in this codebase and none of them cover this:
 * SCORING_ALGORITHM.md's own "Versi kontrak" (a document version, bumped
 * manually), SealedRmibResult::CONTRACT_VERSION / SealedIstResult's
 * equivalent (a JSON-shape version, not a scoring-rule version), and
 * generic_assessment_result_versions.engine_version (a downstream F3/F4
 * projection column that accepts whatever string it's handed -- it has no
 * upstream source of truth). Added here, before ADR-0032 PR3 changes the
 * RMIB formula, so PR3 has a field to populate rather than adding one
 * mid-change. Lead-approved 2026-09-22.
 *
 * The table is empty in every environment (nothing has ever written to it --
 * see ADR-0032's Context section), so this can add a Postgres NOT NULL
 * column directly without a backfill.
 *
 * Postgres gets a CHECK constraint (format + non-null, same regex already
 * used by generic_assessment_result_versions.engine_version, for
 * consistency). SQLite does NOT get an equivalent: this table's SQLite
 * contract has never had one (see 2026_09_20_000200's docblock, "SQLite has
 * no equivalent CHECK constraint for this table") -- format/presence is a
 * PHP-level guarantee here (Sealed*Result::seal()), matching the existing
 * split where Postgres enforces via CHECK and SQLite relies on the domain
 * layer. SQLite's ALTER TABLE ADD COLUMN also cannot add a NOT NULL column
 * without a DEFAULT regardless of row count (a SQL syntax rule, not a
 * runtime check), so the SQLite column is schema-nullable; every writer
 * populates it unconditionally in practice.
 */
return new class extends Migration
{
    private const TABLE = 'generic_instrument_results';

    private const CHECK_NAME = 'generic_instrument_results_engine_version_check';

    public function up(): void
    {
        // Lands after `created_at` (last), not "after result_contract_version"
        // -- neither Postgres nor SQLite supports column positioning on
        // ALTER TABLE ADD COLUMN (that is a MySQL-only extension), so
        // ->after() below is a no-op hint, kept only for readability.
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->string('engine_version', 100)->nullable()->after('result_contract_version');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE '.self::TABLE.' ALTER COLUMN engine_version SET NOT NULL');
        DB::unprepared(<<<SQL
            ALTER TABLE {$this->table()}
                ADD CONSTRAINT {$this->checkName()} CHECK (
                    engine_version ~ '^[A-Za-z0-9][A-Za-z0-9._:+/-]{0,99}\$'
                );
            SQL);
    }

    public function down(): void
    {
        $populated = (int) DB::table(self::TABLE)->count();
        if ($populated > 0) {
            throw new RuntimeException(
                "Cannot drop generic_instrument_results.engine_version while {$populated} row(s) exist; downgrade refused.",
            );
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT '.self::CHECK_NAME);
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropColumn('engine_version');
        });
    }

    private function table(): string
    {
        return self::TABLE;
    }

    private function checkName(): string
    {
        return self::CHECK_NAME;
    }
};
