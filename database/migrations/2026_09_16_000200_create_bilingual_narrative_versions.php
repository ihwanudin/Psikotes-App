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

        Schema::create('bilingual_narrative_versions', function (Blueprint $table) use ($driver): void {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('assessment_case_id');
            $table->unsignedInteger('version');
            $table->ulid('supersedes_id')->nullable();
            $table->ulid('eligibility_version_id')->nullable();
            $table->boolean('review_required');
            $table->text('cluster_a_id')->nullable();
            $table->text('cluster_a_jp')->nullable();
            $table->text('cluster_b_id')->nullable();
            $table->text('cluster_b_jp')->nullable();
            $table->text('cluster_c_id')->nullable();
            $table->text('cluster_c_jp')->nullable();
            $table->text('cluster_d_id')->nullable();
            $table->text('cluster_d_jp')->nullable();
            $driver === 'pgsql'
                ? $table->jsonb('snapshot_json')
                : $table->json('snapshot_json');
            $table->timestampTz('created_at', 6);

            $table->unique(
                ['assessment_case_id', 'version'],
                'bilingual_narrative_case_version_unique',
            );
            $table->unique('supersedes_id', 'bilingual_narrative_supersedes_unique');
            $table->index(
                ['assessment_case_id', 'version'],
                'bilingual_narrative_latest_idx',
            );
            $table->index('eligibility_version_id', 'bilingual_narrative_eligibility_idx');
            $table->foreign('assessment_case_id', 'bilingual_narrative_case_fk')
                ->references('id')->on('assessment_cases')->restrictOnDelete();
        });

        Schema::table('bilingual_narrative_versions', function (Blueprint $table): void {
            $table->foreign('supersedes_id', 'bilingual_narrative_supersedes_fk')
                ->references('id')
                ->on('bilingual_narrative_versions')
                ->restrictOnDelete();

            $table->foreign('eligibility_version_id', 'bilingual_narrative_eligibility_fk')
                ->references('id')
                ->on('eligibility_decision_versions')
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
            DB::unprepared('DROP FUNCTION IF EXISTS app_private.guard_bilingual_narrative_version() CASCADE');
        } elseif ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS bilingual_narrative_insert_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS bilingual_narrative_update_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS bilingual_narrative_delete_guard');
        }

        Schema::dropIfExists('bilingual_narrative_versions');
    }

    private function addPostgresContract(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE bilingual_narrative_versions
                ADD CONSTRAINT bilingual_narrative_contract_check CHECK (
                    version >= 1
                    AND jsonb_typeof(snapshot_json) = 'object'
                );

            CREATE FUNCTION app_private.guard_bilingual_narrative_version() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $guard$
            DECLARE previous bilingual_narrative_versions%ROWTYPE;
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'bilingual narratives are append-only' USING ERRCODE = 'P0001';
                END IF;

                IF NEW.version = 1 THEN
                    IF NEW.supersedes_id IS NOT NULL THEN
                        RAISE EXCEPTION 'bilingual narrative initial version invalid'
                            USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;

                SELECT * INTO previous
                FROM bilingual_narrative_versions r
                WHERE r.id = NEW.supersedes_id;
                IF NOT FOUND
                    OR previous.assessment_case_id <> NEW.assessment_case_id
                    OR previous.version <> NEW.version - 1
                THEN
                    RAISE EXCEPTION 'bilingual narrative chain invalid'
                        USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $guard$;
            CREATE TRIGGER bilingual_narrative_guard
                BEFORE INSERT OR UPDATE OR DELETE ON bilingual_narrative_versions
                FOR EACH ROW EXECUTE FUNCTION app_private.guard_bilingual_narrative_version();

            REVOKE ALL ON FUNCTION app_private.guard_bilingual_narrative_version() FROM PUBLIC;

            REVOKE ALL PRIVILEGES ON bilingual_narrative_versions FROM psikotes_runtime;
            GRANT SELECT, INSERT ON bilingual_narrative_versions TO psikotes_runtime;

            ALTER TABLE bilingual_narrative_versions ENABLE ROW LEVEL SECURITY;
            ALTER TABLE bilingual_narrative_versions FORCE ROW LEVEL SECURITY;
            CREATE POLICY bilingual_narratives_service_select ON bilingual_narrative_versions
                FOR SELECT TO psikotes_runtime USING (app_private.app_role() = 'service');
            CREATE POLICY bilingual_narratives_service_insert ON bilingual_narrative_versions
                FOR INSERT TO psikotes_runtime WITH CHECK (app_private.app_role() = 'service');
            SQL);
    }

    private function addSqliteContract(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER bilingual_narrative_insert_guard
            BEFORE INSERT ON bilingual_narrative_versions
            BEGIN
                SELECT CASE WHEN
                    NEW.version < 1
                    OR (
                        NEW.version = 1
                        AND (NEW.supersedes_id IS NOT NULL)
                    )
                    OR (
                        NEW.version > 1
                        AND NOT EXISTS (
                            SELECT 1 FROM bilingual_narrative_versions previous
                            WHERE previous.id = NEW.supersedes_id
                              AND previous.assessment_case_id = NEW.assessment_case_id
                              AND previous.version = NEW.version - 1
                        )
                    )
                THEN RAISE(ABORT, 'bilingual narrative invariant violation') END;
            END
        SQL);
        DB::unprepared("CREATE TRIGGER bilingual_narrative_update_guard BEFORE UPDATE ON bilingual_narrative_versions BEGIN SELECT RAISE(ABORT, 'bilingual narratives are append-only'); END");
        DB::unprepared("CREATE TRIGGER bilingual_narrative_delete_guard BEFORE DELETE ON bilingual_narrative_versions BEGIN SELECT RAISE(ABORT, 'bilingual narratives are append-only'); END");
    }

    private function driver(): string
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('Bilingual narrative versions require PostgreSQL or SQLite.');
        }

        return $driver;
    }
};
