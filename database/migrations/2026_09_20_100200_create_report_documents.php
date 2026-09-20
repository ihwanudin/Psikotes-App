<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per PDF actually rendered and stored for a signed report. Two
 * independent version numbers, per Lead's 2026-09-20 review (see
 * tasks/handoffs/f6/report-documents-schema-proposal.md §4): report_version
 * (the report's version for the recipient, copied from
 * report_signing_snapshots.version, rises only on re-sign) and render_seq
 * (which physical file this is for that snapshot+document type, rises
 * only when the PDF is genuinely re-rendered — never for re-issuing an
 * expired download link). Append-only: no UPDATE, no DELETE, ever.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = $this->driver();

        Schema::create('report_documents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('assessment_case_id');
            $table->ulid('signing_snapshot_id');
            $table->string('document_type', 16);
            $table->string('report_number', 32);
            $table->unsignedInteger('report_version');
            $table->unsignedInteger('render_seq');
            $table->string('object_key', 160);
            $table->char('sha256', 64);
            $table->unsignedInteger('size_bytes');
            $table->unsignedBigInteger('psychologist_admin_id');
            $table->string('psychologist_name_snapshot', 160);
            $table->string('psychologist_silp_snapshot', 64);
            $table->string('psychologist_str_snapshot', 64)->nullable();
            $table->string('facility_name_snapshot', 160);
            $table->timestampTz('generated_at', 6);
            $table->timestampTz('created_at', 6);

            $table->unique(
                ['document_type', 'report_number', 'report_version'],
                'report_documents_number_unique',
            );
            $table->unique(
                ['signing_snapshot_id', 'document_type', 'render_seq'],
                'report_documents_render_unique',
            );
            $table->index(
                ['assessment_case_id', 'document_type', 'report_version'],
                'report_documents_case_idx',
            );
            $table->unique('object_key', 'report_documents_object_key_unique');

            $table->foreign('assessment_case_id', 'report_documents_case_fk')
                ->references('id')->on('assessment_cases')->restrictOnDelete();
            $table->foreign('signing_snapshot_id', 'report_documents_snapshot_fk')
                ->references('id')->on('report_signing_snapshots')->restrictOnDelete();
            $table->foreign('psychologist_admin_id', 'report_documents_admin_fk')
                ->references('id')->on('admins')->restrictOnDelete();
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
            DB::unprepared('DROP FUNCTION IF EXISTS app_private.guard_report_document() CASCADE');
        } elseif ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS report_document_insert_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS report_document_update_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS report_document_delete_guard');
        }

        Schema::dropIfExists('report_documents');
    }

    private function addPostgresContract(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE report_documents
                ADD CONSTRAINT report_documents_contract_check CHECK (
                    document_type IN ('hpp', 'internal')
                    AND report_number ~ '^HPP/[0-9]{4}/[0-9]{2}/[0-9]{4}$'
                    AND report_version >= 1
                    AND render_seq >= 1
                    AND sha256 ~ '^[a-f0-9]{64}$'
                    AND size_bytes > 0
                    AND trim(psychologist_name_snapshot) <> ''
                    AND trim(psychologist_silp_snapshot) <> ''
                    AND (psychologist_str_snapshot IS NULL OR trim(psychologist_str_snapshot) <> '')
                    AND trim(facility_name_snapshot) <> ''
                );

            CREATE FUNCTION app_private.guard_report_document() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $guard$
            DECLARE
                snapshot_state TEXT;
                snapshot_case_id BIGINT;
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'report documents are append-only' USING ERRCODE = 'P0001';
                END IF;

                SELECT state, assessment_case_id INTO snapshot_state, snapshot_case_id
                FROM report_signing_snapshots
                WHERE id = NEW.signing_snapshot_id;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'report document references a non-existent signing snapshot'
                        USING ERRCODE = '23514';
                END IF;

                IF snapshot_state <> 'SIGNED' THEN
                    RAISE EXCEPTION 'report document requires a SIGNED signing snapshot'
                        USING ERRCODE = '23514';
                END IF;

                IF snapshot_case_id <> NEW.assessment_case_id THEN
                    RAISE EXCEPTION 'report document signing_snapshot_id does not belong to the same assessment_case_id'
                        USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $guard$;
            CREATE TRIGGER report_document_guard
                BEFORE INSERT OR UPDATE OR DELETE ON report_documents
                FOR EACH ROW EXECUTE FUNCTION app_private.guard_report_document();

            REVOKE ALL ON FUNCTION app_private.guard_report_document() FROM PUBLIC;

            REVOKE ALL PRIVILEGES ON report_documents FROM psikotes_runtime;
            GRANT SELECT, INSERT ON report_documents TO psikotes_runtime;

            ALTER TABLE report_documents ENABLE ROW LEVEL SECURITY;
            ALTER TABLE report_documents FORCE ROW LEVEL SECURITY;
            CREATE POLICY report_documents_service_select ON report_documents
                FOR SELECT TO psikotes_runtime USING (app_private.app_role() = 'service');
            CREATE POLICY report_documents_service_insert ON report_documents
                FOR INSERT TO psikotes_runtime WITH CHECK (app_private.app_role() = 'service');
            SQL);
    }

    private function addSqliteContract(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER report_document_insert_guard
            BEFORE INSERT ON report_documents
            BEGIN
                SELECT CASE WHEN
                    NEW.document_type NOT IN ('hpp', 'internal')
                    OR NEW.report_version < 1
                    OR NEW.render_seq < 1
                    OR trim(NEW.psychologist_name_snapshot) = ''
                    OR trim(NEW.psychologist_silp_snapshot) = ''
                    OR (NEW.psychologist_str_snapshot IS NOT NULL AND trim(NEW.psychologist_str_snapshot) = '')
                    OR trim(NEW.facility_name_snapshot) = ''
                THEN RAISE(ABORT, 'report document invariant violation') END;

                SELECT CASE WHEN NOT EXISTS (
                    SELECT 1 FROM report_signing_snapshots s
                    WHERE s.id = NEW.signing_snapshot_id
                      AND s.state = 'SIGNED'
                      AND s.assessment_case_id = NEW.assessment_case_id
                )
                THEN RAISE(ABORT, 'report document requires a SIGNED signing snapshot for the same case') END;
            END
        SQL);
        DB::unprepared("CREATE TRIGGER report_document_update_guard BEFORE UPDATE ON report_documents BEGIN SELECT RAISE(ABORT, 'report documents are append-only'); END");
        DB::unprepared("CREATE TRIGGER report_document_delete_guard BEFORE DELETE ON report_documents BEGIN SELECT RAISE(ABORT, 'report documents are append-only'); END");
    }

    private function driver(): string
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('Report documents require PostgreSQL or SQLite.');
        }

        return $driver;
    }
};
