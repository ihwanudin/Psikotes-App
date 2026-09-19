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

        Schema::create('report_signing_snapshots', function (Blueprint $table) use ($driver): void {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('assessment_case_id');
            $table->unsignedInteger('version');
            $table->ulid('supersedes_id')->nullable();
            $table->string('state', 16);
            $table->ulid('eligibility_version_id');
            $table->ulid('narrative_version_id');
            $driver === 'pgsql'
                ? $table->jsonb('snapshot_json')
                : $table->json('snapshot_json');
            $table->unsignedBigInteger('signed_by_admin_id');
            $table->timestampTz('signed_at', 6);
            $table->timestampTz('created_at', 6);

            $table->unique(
                ['assessment_case_id', 'version'],
                'report_signing_snapshots_case_version_unique',
            );
            $table->unique('supersedes_id', 'report_signing_snapshots_supersedes_unique');
            $table->index(
                ['assessment_case_id', 'version'],
                'report_signing_snapshots_latest_idx',
            );
            $table->foreign('assessment_case_id', 'report_signing_snapshots_case_fk')
                ->references('id')->on('assessment_cases')->restrictOnDelete();
        });

        Schema::table('report_signing_snapshots', function (Blueprint $table): void {
            $table->foreign('supersedes_id', 'report_signing_snapshots_supersedes_fk')
                ->references('id')
                ->on('report_signing_snapshots')
                ->restrictOnDelete();
            $table->foreign('eligibility_version_id', 'report_signing_snapshots_eligibility_fk')
                ->references('id')
                ->on('eligibility_decision_versions')
                ->restrictOnDelete();
            $table->foreign('narrative_version_id', 'report_signing_snapshots_narrative_fk')
                ->references('id')
                ->on('bilingual_narrative_versions')
                ->restrictOnDelete();
            $table->foreign('signed_by_admin_id', 'report_signing_snapshots_admin_fk')
                ->references('id')
                ->on('admins')
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
            DB::unprepared('DROP FUNCTION IF EXISTS app_private.guard_report_signing_snapshot() CASCADE');
        } elseif ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS report_signing_snapshot_insert_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS report_signing_snapshot_update_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS report_signing_snapshot_delete_guard');
        }

        Schema::dropIfExists('report_signing_snapshots');
    }

    private function addPostgresContract(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE report_signing_snapshots
                ADD CONSTRAINT report_signing_snapshots_contract_check CHECK (
                    state = 'SIGNED'
                    AND signed_by_admin_id IS NOT NULL
                    AND signed_at IS NOT NULL
                    AND jsonb_typeof(snapshot_json) = 'object'
                );

            CREATE FUNCTION app_private.guard_report_signing_snapshot() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $guard$
            DECLARE
                previous report_signing_snapshots%ROWTYPE;
                eligibility_case_id BIGINT;
                narrative_case_id BIGINT;
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'report signing snapshots are append-only' USING ERRCODE = 'P0001';
                END IF;

                SELECT assessment_case_id INTO eligibility_case_id
                FROM eligibility_decision_versions
                WHERE id = NEW.eligibility_version_id;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'report signing snapshot references a non-existent eligibility version'
                        USING ERRCODE = '23514';
                END IF;

                IF eligibility_case_id <> NEW.assessment_case_id THEN
                    RAISE EXCEPTION 'report signing snapshot eligibility_version_id does not belong to the same assessment_case_id'
                        USING ERRCODE = '23514';
                END IF;

                SELECT assessment_case_id INTO narrative_case_id
                FROM bilingual_narrative_versions
                WHERE id = NEW.narrative_version_id;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'report signing snapshot references a non-existent narrative version'
                        USING ERRCODE = '23514';
                END IF;

                IF narrative_case_id <> NEW.assessment_case_id THEN
                    RAISE EXCEPTION 'report signing snapshot narrative_version_id does not belong to the same assessment_case_id'
                        USING ERRCODE = '23514';
                END IF;

                IF NEW.version = 1 THEN
                    IF NEW.supersedes_id IS NOT NULL THEN
                        RAISE EXCEPTION 'report signing snapshot initial version invalid'
                            USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;

                SELECT * INTO previous
                FROM report_signing_snapshots r
                WHERE r.id = NEW.supersedes_id;
                IF NOT FOUND
                    OR previous.assessment_case_id <> NEW.assessment_case_id
                    OR previous.version <> NEW.version - 1
                THEN
                    RAISE EXCEPTION 'report signing snapshot chain invalid'
                        USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $guard$;
            CREATE TRIGGER report_signing_snapshot_guard
                BEFORE INSERT OR UPDATE OR DELETE ON report_signing_snapshots
                FOR EACH ROW EXECUTE FUNCTION app_private.guard_report_signing_snapshot();

            REVOKE ALL ON FUNCTION app_private.guard_report_signing_snapshot() FROM PUBLIC;

            REVOKE ALL PRIVILEGES ON report_signing_snapshots FROM psikotes_runtime;
            GRANT SELECT, INSERT ON report_signing_snapshots TO psikotes_runtime;

            ALTER TABLE report_signing_snapshots ENABLE ROW LEVEL SECURITY;
            ALTER TABLE report_signing_snapshots FORCE ROW LEVEL SECURITY;
            CREATE POLICY report_signing_snapshots_service_select ON report_signing_snapshots
                FOR SELECT TO psikotes_runtime USING (app_private.app_role() = 'service');
            CREATE POLICY report_signing_snapshots_service_insert ON report_signing_snapshots
                FOR INSERT TO psikotes_runtime WITH CHECK (app_private.app_role() = 'service');
            SQL);
    }

    private function addSqliteContract(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER report_signing_snapshot_insert_guard
            BEFORE INSERT ON report_signing_snapshots
            BEGIN
                SELECT CASE WHEN
                    NEW.state <> 'SIGNED'
                    OR NEW.signed_by_admin_id IS NULL
                    OR NEW.signed_at IS NULL
                THEN RAISE(ABORT, 'report signing snapshot invariant violation') END;

                SELECT CASE WHEN NOT EXISTS (
                    SELECT 1 FROM eligibility_decision_versions e
                    WHERE e.id = NEW.eligibility_version_id
                      AND e.assessment_case_id = NEW.assessment_case_id
                )
                THEN RAISE(ABORT, 'report signing snapshot eligibility cross-case violation') END;

                SELECT CASE WHEN NOT EXISTS (
                    SELECT 1 FROM bilingual_narrative_versions b
                    WHERE b.id = NEW.narrative_version_id
                      AND b.assessment_case_id = NEW.assessment_case_id
                )
                THEN RAISE(ABORT, 'report signing snapshot narrative cross-case violation') END;

                SELECT CASE WHEN
                    NEW.version = 1
                    AND (NEW.supersedes_id IS NOT NULL)
                THEN RAISE(ABORT, 'report signing snapshot initial version invalid') END;

                SELECT CASE WHEN
                    NEW.version > 1
                    AND NOT EXISTS (
                        SELECT 1 FROM report_signing_snapshots previous
                        WHERE previous.id = NEW.supersedes_id
                          AND previous.assessment_case_id = NEW.assessment_case_id
                          AND previous.version = NEW.version - 1
                    )
                THEN RAISE(ABORT, 'report signing snapshot chain invalid') END;
            END
        SQL);
        DB::unprepared("CREATE TRIGGER report_signing_snapshot_update_guard BEFORE UPDATE ON report_signing_snapshots BEGIN SELECT RAISE(ABORT, 'report signing snapshots are append-only'); END");
        DB::unprepared("CREATE TRIGGER report_signing_snapshot_delete_guard BEFORE DELETE ON report_signing_snapshots BEGIN SELECT RAISE(ABORT, 'report signing snapshots are append-only'); END");
    }

    private function driver(): string
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('Report signing snapshots require PostgreSQL or SQLite.');
        }

        return $driver;
    }
};
