<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessment_participants', function (Blueprint $table): void {
            $table->unique(
                ['id', 'assessment_attempt_id'],
                'assessment_participants_id_attempt_unique',
            );
        });

        Schema::create('generic_assessment_result_versions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('assessment_participant_id')->constrained('assessment_participants')->restrictOnDelete();
            $table->ulid('assessment_attempt_id');
            $table->unsignedInteger('result_version');
            $table->ulid('supersedes_id')->nullable();
            $table->double('iq');
            $table->string('iq_canonical', 64);
            $table->string('engine_version', 100);
            $table->timestampTz('completed_at');
            $table->string('finality', 16);
            $table->timestampTz('revoked_at')->nullable();
            $table->char('result_checksum', 64)->unique();
            $table->timestampTz('created_at');

            $table->unique(
                ['assessment_participant_id', 'assessment_attempt_id', 'result_version'],
                'generic_result_attempt_version_unique',
            );
            $table->unique('supersedes_id', 'generic_result_supersedes_unique');
            $table->foreign(['assessment_participant_id', 'assessment_attempt_id'], 'generic_result_attempt_owner_fk')
                ->references(['id', 'assessment_attempt_id'])
                ->on('assessment_participants')
                ->restrictOnDelete();
            $table->foreign('supersedes_id', 'generic_result_supersedes_fk')
                ->references('id')
                ->on('generic_assessment_result_versions')
                ->restrictOnDelete();
            $table->index(
                ['assessment_attempt_id', 'result_version'],
                'generic_result_latest_idx',
            );
        });

        if (DB::getDriverName() === 'pgsql') {
            $this->addPostgresGuards();
        } elseif (DB::getDriverName() === 'sqlite') {
            $this->addSqliteGuards();
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS guard_generic_assessment_result_version() CASCADE');
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS generic_assessment_result_insert_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS generic_assessment_result_update_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS generic_assessment_result_delete_guard');
        }

        Schema::dropIfExists('generic_assessment_result_versions');
        Schema::table('assessment_participants', function (Blueprint $table): void {
            $table->dropUnique('assessment_participants_id_attempt_unique');
        });
    }

    private function addSqliteGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER generic_assessment_result_insert_guard
            BEFORE INSERT ON generic_assessment_result_versions
            BEGIN
                SELECT CASE WHEN
                    NEW.result_version < 1
                    OR NEW.finality <> 'FINALIZED'
                    OR NEW.iq <= 0
                    OR NEW.iq > 300
                    OR lower(CAST(NEW.iq AS TEXT)) IN ('inf', 'infinity', 'nan')
                    OR length(NEW.iq_canonical) < 1
                    OR length(NEW.iq_canonical) > 64
                    OR json_valid('[' || NEW.iq_canonical || ']') <> 1
                    OR json_type('[' || NEW.iq_canonical || ']', '$[0]') NOT IN ('integer', 'real')
                    OR CAST(NEW.iq_canonical AS REAL) <> NEW.iq
                    OR length(NEW.engine_version) < 1
                    OR length(NEW.engine_version) > 100
                    OR NEW.engine_version GLOB '*[^A-Za-z0-9._:+/-]*'
                    OR substr(NEW.engine_version, 1, 1) GLOB '[^A-Za-z0-9]'
                    OR length(NEW.result_checksum) <> 64
                    OR NEW.result_checksum GLOB '*[^0-9a-f]*'
                    OR (NEW.revoked_at IS NOT NULL AND NEW.revoked_at < NEW.completed_at)
                    OR NOT EXISTS (
                        SELECT 1 FROM assessment_participants p
                        WHERE p.id = NEW.assessment_participant_id
                          AND p.assessment_attempt_id = NEW.assessment_attempt_id
                    )
                    OR (
                        NEW.result_version = 1
                        AND (NEW.supersedes_id IS NOT NULL OR NEW.revoked_at IS NOT NULL)
                    )
                    OR (
                        NEW.result_version > 1
                        AND NOT EXISTS (
                            SELECT 1 FROM generic_assessment_result_versions previous
                            WHERE previous.id = NEW.supersedes_id
                              AND previous.assessment_participant_id = NEW.assessment_participant_id
                              AND previous.assessment_attempt_id = NEW.assessment_attempt_id
                              AND previous.result_version = NEW.result_version - 1
                              AND previous.revoked_at IS NULL
                        )
                    )
                    OR (
                        NEW.result_version > 1
                        AND NEW.revoked_at IS NOT NULL
                        AND EXISTS (
                            SELECT 1 FROM generic_assessment_result_versions previous
                            WHERE previous.id = NEW.supersedes_id
                              AND (
                                  previous.iq <> NEW.iq
                                  OR previous.engine_version <> NEW.engine_version
                                  OR previous.completed_at <> NEW.completed_at
                                  OR previous.finality <> NEW.finality
                              )
                        )
                    )
                    OR (
                        NEW.result_version > 1
                        AND NEW.revoked_at IS NULL
                        AND EXISTS (
                            SELECT 1 FROM generic_assessment_result_versions previous
                            WHERE previous.id = NEW.supersedes_id
                              AND previous.iq = NEW.iq
                              AND previous.engine_version = NEW.engine_version
                              AND previous.completed_at = NEW.completed_at
                              AND previous.finality = NEW.finality
                              AND previous.revoked_at IS NULL
                        )
                    )
                THEN RAISE(ABORT, 'generic assessment result invariant violation') END;
            END
        SQL);
        DB::unprepared("CREATE TRIGGER generic_assessment_result_update_guard BEFORE UPDATE ON generic_assessment_result_versions BEGIN SELECT RAISE(ABORT, 'generic assessment results are append-only'); END");
        DB::unprepared("CREATE TRIGGER generic_assessment_result_delete_guard BEFORE DELETE ON generic_assessment_result_versions BEGIN SELECT RAISE(ABORT, 'generic assessment results are append-only'); END");
    }

    private function addPostgresGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_generic_assessment_result_version()
            RETURNS trigger AS $$
            DECLARE previous generic_assessment_result_versions%ROWTYPE;
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'generic assessment results are append-only';
                END IF;

                IF NEW.result_version < 1
                    OR NEW.finality <> 'FINALIZED'
                    OR NEW.iq <= 0
                    OR NEW.iq > 300
                    OR NEW.iq = 'NaN'::double precision
                    OR NEW.iq = 'Infinity'::double precision
                    OR length(NEW.iq_canonical) NOT BETWEEN 1 AND 64
                    OR NEW.iq_canonical::double precision <> NEW.iq
                    OR NEW.engine_version !~ '^[A-Za-z0-9][A-Za-z0-9._:+/-]{0,99}$'
                    OR NEW.result_checksum !~ '^[a-f0-9]{64}$'
                    OR (NEW.revoked_at IS NOT NULL AND NEW.revoked_at < NEW.completed_at)
                THEN
                    RAISE EXCEPTION 'generic assessment result invariant violation';
                END IF;

                IF NEW.result_version = 1 THEN
                    IF NEW.supersedes_id IS NOT NULL OR NEW.revoked_at IS NOT NULL THEN
                        RAISE EXCEPTION 'generic assessment result initial version invalid';
                    END IF;
                    RETURN NEW;
                END IF;

                SELECT * INTO previous
                FROM generic_assessment_result_versions r
                WHERE r.id = NEW.supersedes_id;
                IF NOT FOUND
                    OR previous.assessment_participant_id <> NEW.assessment_participant_id
                    OR previous.assessment_attempt_id <> NEW.assessment_attempt_id
                    OR previous.result_version <> NEW.result_version - 1
                    OR previous.revoked_at IS NOT NULL
                THEN
                    RAISE EXCEPTION 'generic assessment result chain invalid';
                END IF;

                IF NEW.revoked_at IS NOT NULL THEN
                    IF previous.iq IS DISTINCT FROM NEW.iq
                        OR previous.engine_version IS DISTINCT FROM NEW.engine_version
                        OR previous.completed_at IS DISTINCT FROM NEW.completed_at
                        OR previous.finality IS DISTINCT FROM NEW.finality
                    THEN
                        RAISE EXCEPTION 'generic assessment result revocation invalid';
                    END IF;
                ELSIF previous.iq IS NOT DISTINCT FROM NEW.iq
                    AND previous.engine_version IS NOT DISTINCT FROM NEW.engine_version
                    AND previous.completed_at IS NOT DISTINCT FROM NEW.completed_at
                    AND previous.finality IS NOT DISTINCT FROM NEW.finality
                THEN
                    RAISE EXCEPTION 'generic assessment result correction has no change';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER generic_assessment_result_guard
            BEFORE INSERT OR UPDATE OR DELETE ON generic_assessment_result_versions
            FOR EACH ROW EXECUTE FUNCTION guard_generic_assessment_result_version()
        SQL);
    }
};
