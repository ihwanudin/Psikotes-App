<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F2 lane increment 3a (Lead-approved 2026-09-20). `instrument_versions.payload`
 * is `jsonb`; PostgreSQL normalizes jsonb on write, so `checksum` — computed by
 * `InstrumentSeeder` over the raw file bytes — can never be reproduced by
 * hashing `payload` back. `ScoreSealedIstAnswerSet` re-hashes on every read and
 * fails closed, so scoring a real seeded IST row is currently impossible in
 * PostgreSQL (proof: tests/Postgres/InstrumentChecksumScoringIntegrityTest.php).
 *
 * This adds `source_text`: the exact raw bytes the checksum was computed from,
 * stored verbatim alongside the jsonb `payload` derivative. `payload` is
 * unchanged and remains available for jsonb querying; it is simply no longer
 * the checksum's source of truth.
 *
 * `source_text` is NULLABLE at the schema level, not NOT NULL. The existing
 * history-immutability trigger (2026_09_09_000100) freezes a row's identity
 * columns forever once written; a historical row whose on-disk source file has
 * since been overwritten by a newer version cannot have its original raw bytes
 * honestly reconstructed post hoc, and backfilling it from `payload::text`
 * would only reproduce the same checksum-mismatch bug under a new name. A
 * row with a NULL `source_text` is left honestly incomplete; the scorer's
 * application-level fail-closed check (`is_string($sourceText)`) — not a
 * table constraint, and with no fallback to `payload` — is what actually
 * prevents it from being used
 * (app/Services/AssessmentResults/ScoreSealedIstAnswerSet.php).
 */
return new class extends Migration
{
    private const TRIGGER = 'instrument_versions_guard_history_trigger';

    public function up(): void
    {
        Schema::table('instrument_versions', function (Blueprint $table): void {
            $table->text('source_text')->nullable()->after('payload');
        });

        $isPgsql = DB::getDriverName() === 'pgsql';

        if ($isPgsql) {
            // The existing trigger does not yet know about `source_text` and
            // would reject this backfill as a mutation of an already "immutable"
            // row (it only ever permits an active-to-inactive transition). It is
            // disabled only for the duration of this one-time, owner-run,
            // additive backfill and re-enabled immediately after, before the
            // function is replaced below to lock `source_text` for good.
            DB::statement('ALTER TABLE instrument_versions DISABLE TRIGGER '.self::TRIGGER);
        }

        try {
            $this->backfillSourceTextFromDisk();
        } finally {
            if ($isPgsql) {
                DB::statement('ALTER TABLE instrument_versions ENABLE TRIGGER '.self::TRIGGER);
            }
        }

        if (! $isPgsql) {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION instrument_versions_guard_history() RETURNS trigger
            LANGUAGE plpgsql
            AS $function$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.is_active AND NEW.updated_at IS NULL THEN
                        RAISE EXCEPTION 'active instrument version requires updated_at' USING ERRCODE = 'P0001';
                    END IF;

                    RETURN NEW;
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'instrument version history cannot be deleted' USING ERRCODE = 'P0001';
                END IF;

                IF ROW(
                    NEW.id, NEW.code, NEW.version, NEW.source_file, NEW.checksum, NEW.payload, NEW.source_text, NEW.created_at
                ) IS DISTINCT FROM ROW(
                    OLD.id, OLD.code, OLD.version, OLD.source_file, OLD.checksum, OLD.payload, OLD.source_text, OLD.created_at
                ) THEN
                    RAISE EXCEPTION 'instrument version history is immutable' USING ERRCODE = 'P0001';
                END IF;

                IF NOT OLD.is_active OR NEW.is_active
                    OR OLD.updated_at IS NULL OR NEW.updated_at IS NULL
                    OR NEW.updated_at <= OLD.updated_at THEN
                    RAISE EXCEPTION 'instrument version history only permits active-to-inactive deactivation with a newer updated_at'
                        USING ERRCODE = 'P0001';
                END IF;

                RETURN NEW;
            END;
            $function$;
            SQL);
    }

    public function down(): void
    {
        $isPgsql = DB::getDriverName() === 'pgsql';

        if ($isPgsql) {
            $populated = (int) DB::table('instrument_versions')->whereNotNull('source_text')->count();
            if ($populated > 0) {
                throw new RuntimeException(
                    "Cannot remove instrument_versions.source_text while {$populated} row(s) carry it; downgrade refused.",
                );
            }

            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION instrument_versions_guard_history() RETURNS trigger
                LANGUAGE plpgsql
                AS $function$
                BEGIN
                    IF TG_OP = 'INSERT' THEN
                        IF NEW.is_active AND NEW.updated_at IS NULL THEN
                            RAISE EXCEPTION 'active instrument version requires updated_at' USING ERRCODE = 'P0001';
                        END IF;

                        RETURN NEW;
                    END IF;

                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'instrument version history cannot be deleted' USING ERRCODE = 'P0001';
                    END IF;

                    IF ROW(
                        NEW.id, NEW.code, NEW.version, NEW.source_file, NEW.checksum, NEW.payload, NEW.created_at
                    ) IS DISTINCT FROM ROW(
                        OLD.id, OLD.code, OLD.version, OLD.source_file, OLD.checksum, OLD.payload, OLD.created_at
                    ) THEN
                        RAISE EXCEPTION 'instrument version history is immutable' USING ERRCODE = 'P0001';
                    END IF;

                    IF NOT OLD.is_active OR NEW.is_active
                        OR OLD.updated_at IS NULL OR NEW.updated_at IS NULL
                        OR NEW.updated_at <= OLD.updated_at THEN
                        RAISE EXCEPTION 'instrument version history only permits active-to-inactive deactivation with a newer updated_at'
                            USING ERRCODE = 'P0001';
                    END IF;

                    RETURN NEW;
                END;
                $function$;
                SQL);
        }

        Schema::table('instrument_versions', function (Blueprint $table): void {
            $table->dropColumn('source_text');
        });
    }

    /**
     * Best-effort, honest backfill: a row's `source_text` is populated only when
     * its exact original bytes are still recoverable — i.e. the on-disk source
     * file for its `source_file` still hashes to exactly its stored `checksum`.
     * A missing source file (partial checkout, a CI environment that never
     * needed scoring data) is not a migration failure: the row is simply
     * skipped and left NULL, same as any other unrecoverable historical row.
     */
    private function backfillSourceTextFromDisk(): void
    {
        $rows = DB::table('instrument_versions')->select(['id', 'source_file', 'checksum'])->get();
        $filled = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $path = database_path('seeders/data/'.$row->source_file);
            if (! is_file($path)) {
                $skipped++;

                continue;
            }

            $bytes = file_get_contents($path);
            if ($bytes === false || ! hash_equals((string) $row->checksum, hash('sha256', $bytes))) {
                $skipped++;

                continue;
            }

            DB::table('instrument_versions')->where('id', $row->id)->update(['source_text' => $bytes]);
            $filled++;
        }

        fwrite(STDOUT, sprintf(
            "instrument_versions.source_text backfill: filled=%d skipped=%d total=%d\n",
            $filled,
            $skipped,
            $rows->count(),
        ));
    }
};
