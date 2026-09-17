<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = $this->driver();

        Schema::create('narrative_cluster_edits', function (Blueprint $table) use ($driver): void {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('assessment_case_id');
            $table->char('cluster', 1);
            $table->text('edited_text');
            $table->char('baseline_snapshot_checksum', 64);
            $table->ulid('baseline_version_id');
            $table->timestampTz('edited_at', 6);
            $table->timestampTz('created_at', 6);

            $table->unique(
                ['assessment_case_id', 'cluster'],
                'narrative_cluster_edits_case_cluster_unique',
            );
            $table->index('baseline_version_id', 'narrative_cluster_edits_baseline_idx');
            $table->foreign('assessment_case_id', 'narrative_cluster_edits_case_fk')
                ->references('id')->on('assessment_cases')->restrictOnDelete();
        });

        Schema::table('narrative_cluster_edits', function (Blueprint $table): void {
            $table->foreign('baseline_version_id', 'narrative_cluster_edits_baseline_fk')
                ->references('id')
                ->on('bilingual_narrative_versions')
                ->restrictOnDelete();
        });

        if ($driver === 'pgsql') {
            $this->addPostgresContract();
        } elseif ($driver === 'sqlite') {
            $this->addSqliteContract();
        }
    }

    public function down(): void
    {
        $driver = $this->driver();
        if ($driver === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS app_private.guard_narrative_cluster_edit() CASCADE');
        } elseif ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS narrative_cluster_edit_insert_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS narrative_cluster_edit_update_guard');
        }

        Schema::dropIfExists('narrative_cluster_edits');
    }

    private function addPostgresContract(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE narrative_cluster_edits
                ADD CONSTRAINT narrative_cluster_edits_contract_check CHECK (
                    cluster IN ('A', 'B', 'C', 'D')
                    AND edited_text = btrim(edited_text)
                );

            CREATE FUNCTION app_private.guard_narrative_cluster_edit() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $guard$
            DECLARE
                baseline_case_id BIGINT;
            BEGIN
                SELECT assessment_case_id INTO baseline_case_id
                FROM bilingual_narrative_versions
                WHERE id = NEW.baseline_version_id;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'narrative cluster edit references a non-existent baseline version'
                        USING ERRCODE = '23514';
                END IF;

                IF baseline_case_id <> NEW.assessment_case_id THEN
                    RAISE EXCEPTION 'narrative cluster edit baseline_version_id does not belong to the same assessment_case_id'
                        USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $guard$;
            CREATE TRIGGER narrative_cluster_edit_guard
                BEFORE INSERT OR UPDATE ON narrative_cluster_edits
                FOR EACH ROW EXECUTE FUNCTION app_private.guard_narrative_cluster_edit();

            REVOKE ALL ON FUNCTION app_private.guard_narrative_cluster_edit() FROM PUBLIC;

            REVOKE ALL PRIVILEGES ON narrative_cluster_edits FROM psikotes_runtime;
            GRANT SELECT, INSERT, UPDATE ON narrative_cluster_edits TO psikotes_runtime;

            ALTER TABLE narrative_cluster_edits ENABLE ROW LEVEL SECURITY;
            ALTER TABLE narrative_cluster_edits FORCE ROW LEVEL SECURITY;
            CREATE POLICY narrative_edits_service_select ON narrative_cluster_edits
                FOR SELECT TO psikotes_runtime USING (app_private.app_role() = 'service');
            CREATE POLICY narrative_edits_service_insert ON narrative_cluster_edits
                FOR INSERT TO psikotes_runtime WITH CHECK (app_private.app_role() = 'service');
            CREATE POLICY narrative_edits_service_update ON narrative_cluster_edits
                FOR UPDATE TO psikotes_runtime USING (app_private.app_role() = 'service');
            SQL);
    }

    private function addSqliteContract(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER narrative_cluster_edit_insert_guard
            BEFORE INSERT ON narrative_cluster_edits
            BEGIN
                SELECT CASE WHEN
                    NEW.cluster NOT IN ('A', 'B', 'C', 'D')
                    OR NEW.edited_text <> TRIM(NEW.edited_text)
                    OR LENGTH(TRIM(NEW.edited_text)) = 0
                THEN RAISE(ABORT, 'narrative cluster edit invariant violation') END;

                SELECT CASE WHEN NOT EXISTS (
                    SELECT 1 FROM bilingual_narrative_versions b
                    WHERE b.id = NEW.baseline_version_id
                      AND b.assessment_case_id = NEW.assessment_case_id
                )
                THEN RAISE(ABORT, 'narrative cluster edit baseline cross-case violation') END;
            END
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER narrative_cluster_edit_update_guard
            BEFORE UPDATE ON narrative_cluster_edits
            BEGIN
                SELECT CASE WHEN
                    NEW.cluster NOT IN ('A', 'B', 'C', 'D')
                    OR NEW.edited_text <> TRIM(NEW.edited_text)
                    OR LENGTH(TRIM(NEW.edited_text)) = 0
                THEN RAISE(ABORT, 'narrative cluster edit invariant violation') END;

                SELECT CASE WHEN NOT EXISTS (
                    SELECT 1 FROM bilingual_narrative_versions b
                    WHERE b.id = NEW.baseline_version_id
                      AND b.assessment_case_id = NEW.assessment_case_id
                )
                THEN RAISE(ABORT, 'narrative cluster edit baseline cross-case violation') END;
            END
        SQL);
    }

    private function driver(): string
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('Narrative cluster edits require PostgreSQL or SQLite.');
        }

        return $driver;
    }
};
