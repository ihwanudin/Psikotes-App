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

        Schema::create('eligibility_decision_versions', function (Blueprint $table) use ($driver): void {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('assessment_case_id');
            $table->unsignedInteger('version');
            $table->ulid('supersedes_id')->nullable();
            $table->string('standard_version', 32);
            $table->string('field_code', 24);
            $table->boolean('publication_blocked');
            $table->string('recommendation_label', 24)->nullable();
            $table->unsignedSmallInteger('iq');
            $table->string('validity', 2);
            $driver === 'pgsql'
                ? $table->jsonb('snapshot_json')
                : $table->json('snapshot_json');
            $driver === 'pgsql'
                ? $table->jsonb('canonical_input_json')
                : $table->json('canonical_input_json');
            $table->timestampTz('created_at', 6);

            $table->unique(
                ['assessment_case_id', 'version'],
                'eligibility_decision_case_version_unique',
            );
            $table->unique('supersedes_id', 'eligibility_decision_supersedes_unique');
            $table->index(
                ['assessment_case_id', 'version'],
                'eligibility_decision_latest_idx',
            );
            $table->foreign('assessment_case_id', 'eligibility_decision_case_fk')
                ->references('id')->on('assessment_cases')->restrictOnDelete();
        });

        Schema::table('eligibility_decision_versions', function (Blueprint $table): void {
            $table->foreign('supersedes_id', 'eligibility_decision_supersedes_fk')
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
            DB::unprepared('DROP FUNCTION IF EXISTS app_private.guard_eligibility_decision_version() CASCADE');
        } elseif ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS eligibility_decision_insert_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS eligibility_decision_update_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS eligibility_decision_delete_guard');
        }

        Schema::dropIfExists('eligibility_decision_versions');
    }

    private function addPostgresContract(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE eligibility_decision_versions
                ADD CONSTRAINT eligibility_decision_contract_check CHECK (
                    version >= 1
                    AND field_code IN ('KAIGO','KENSETSU','NOUGYOU','SEIZOU','GAISHOKU','UMUM')
                    AND validity IN ('V1','V2','V3')
                    AND (recommendation_label IS NULL OR recommendation_label IN ('DISARANKAN','DIPERTIMBANGKAN','TIDAK_DISARANKAN'))
                    AND iq BETWEEN 1 AND 300
                    AND length(standard_version) BETWEEN 1 AND 32
                    AND standard_version = btrim(standard_version)
                    AND standard_version !~ '[[:space:][:cntrl:]]'
                    AND jsonb_typeof(snapshot_json) = 'object'
                    AND jsonb_typeof(canonical_input_json) = 'object'
                );

            CREATE FUNCTION app_private.guard_eligibility_decision_version() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $guard$
            DECLARE previous eligibility_decision_versions%ROWTYPE;
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'eligibility decisions are append-only' USING ERRCODE = 'P0001';
                END IF;

                IF NEW.version = 1 THEN
                    IF NEW.supersedes_id IS NOT NULL THEN
                        RAISE EXCEPTION 'eligibility decision initial version invalid'
                            USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;

                SELECT * INTO previous
                FROM eligibility_decision_versions r
                WHERE r.id = NEW.supersedes_id;
                IF NOT FOUND
                    OR previous.assessment_case_id <> NEW.assessment_case_id
                    OR previous.version <> NEW.version - 1
                THEN
                    RAISE EXCEPTION 'eligibility decision chain invalid'
                        USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $guard$;
            CREATE TRIGGER eligibility_decision_guard
                BEFORE INSERT OR UPDATE OR DELETE ON eligibility_decision_versions
                FOR EACH ROW EXECUTE FUNCTION app_private.guard_eligibility_decision_version();

            REVOKE ALL ON FUNCTION app_private.guard_eligibility_decision_version() FROM PUBLIC;

            REVOKE ALL PRIVILEGES ON eligibility_decision_versions FROM psikotes_runtime;
            GRANT SELECT, INSERT ON eligibility_decision_versions TO psikotes_runtime;

            ALTER TABLE eligibility_decision_versions ENABLE ROW LEVEL SECURITY;
            ALTER TABLE eligibility_decision_versions FORCE ROW LEVEL SECURITY;
            CREATE POLICY eligibility_decisions_service_select ON eligibility_decision_versions
                FOR SELECT TO psikotes_runtime USING (app_private.app_role() = 'service');
            CREATE POLICY eligibility_decisions_service_insert ON eligibility_decision_versions
                FOR INSERT TO psikotes_runtime WITH CHECK (app_private.app_role() = 'service');
            SQL);
    }

    private function addSqliteContract(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER eligibility_decision_insert_guard
            BEFORE INSERT ON eligibility_decision_versions
            BEGIN
                SELECT CASE WHEN
                    NEW.version < 1
                    OR NEW.field_code NOT IN ('KAIGO','KENSETSU','NOUGYOU','SEIZOU','GAISHOKU','UMUM')
                    OR NEW.validity NOT IN ('V1','V2','V3')
                    OR (NEW.recommendation_label IS NOT NULL AND NEW.recommendation_label NOT IN ('DISARANKAN','DIPERTIMBANGKAN','TIDAK_DISARANKAN'))
                    OR NEW.iq < 1
                    OR NEW.iq > 300
                    OR length(NEW.standard_version) < 1
                    OR length(NEW.standard_version) > 32
                    OR (
                        NEW.version = 1
                        AND (NEW.supersedes_id IS NOT NULL)
                    )
                    OR (
                        NEW.version > 1
                        AND NOT EXISTS (
                            SELECT 1 FROM eligibility_decision_versions previous
                            WHERE previous.id = NEW.supersedes_id
                              AND previous.assessment_case_id = NEW.assessment_case_id
                              AND previous.version = NEW.version - 1
                        )
                    )
                THEN RAISE(ABORT, 'eligibility decision invariant violation') END;
            END
        SQL);
        DB::unprepared("CREATE TRIGGER eligibility_decision_update_guard BEFORE UPDATE ON eligibility_decision_versions BEGIN SELECT RAISE(ABORT, 'eligibility decisions are append-only'); END");
        DB::unprepared("CREATE TRIGGER eligibility_decision_delete_guard BEFORE DELETE ON eligibility_decision_versions BEGIN SELECT RAISE(ABORT, 'eligibility decisions are append-only'); END");
    }

    private function driver(): string
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('Eligibility decision versions require PostgreSQL or SQLite.');
        }

        return $driver;
    }
};
