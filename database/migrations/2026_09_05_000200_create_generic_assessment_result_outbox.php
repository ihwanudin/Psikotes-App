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
        Schema::table('generic_assessment_result_versions', function (Blueprint $table): void {
            $table->unique(
                ['id', 'assessment_participant_id', 'assessment_attempt_id', 'result_version', 'result_checksum'],
                'generic_result_outbox_source_unique',
            );
        });

        Schema::create('generic_assessment_result_outbox', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('generic_assessment_result_version_id')->unique('generic_result_outbox_source_id_unique');
            $table->foreignId('assessment_participant_id');
            $table->ulid('assessment_attempt_id');
            $table->unsignedInteger('result_version');
            $table->char('result_checksum', 64);
            $table->string('envelope_contract', 48);
            $table->timestampTz('created_at');

            $table->unique(
                ['assessment_participant_id', 'assessment_attempt_id', 'result_version'],
                'generic_result_outbox_attempt_version_unique',
            );
            $table->foreign(
                ['generic_assessment_result_version_id', 'assessment_participant_id', 'assessment_attempt_id', 'result_version', 'result_checksum'],
                'generic_result_outbox_source_fk',
            )->references(['id', 'assessment_participant_id', 'assessment_attempt_id', 'result_version', 'result_checksum'])
                ->on('generic_assessment_result_versions')
                ->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION guard_generic_assessment_result_outbox()
                RETURNS trigger AS $$
                BEGIN
                    IF TG_OP <> 'INSERT' THEN
                        RAISE EXCEPTION 'generic assessment result outbox is append-only';
                    END IF;

                    IF NEW.result_version < 1
                        OR NEW.result_checksum !~ '^[a-f0-9]{64}$'
                        OR NEW.envelope_contract <> 'generic-assessment-result:v1'
                    THEN
                        RAISE EXCEPTION 'generic assessment result outbox invariant violation';
                    END IF;

                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql
            SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER generic_assessment_result_outbox_guard
                BEFORE INSERT OR UPDATE OR DELETE ON generic_assessment_result_outbox
                FOR EACH ROW EXECUTE FUNCTION guard_generic_assessment_result_outbox()
            SQL);
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER generic_assessment_result_outbox_insert_guard
                BEFORE INSERT ON generic_assessment_result_outbox
                BEGIN
                    SELECT CASE WHEN
                        NEW.result_version < 1
                        OR length(NEW.result_checksum) <> 64
                        OR NEW.result_checksum GLOB '*[^0-9a-f]*'
                        OR NEW.envelope_contract <> 'generic-assessment-result:v1'
                    THEN RAISE(ABORT, 'generic assessment result outbox invariant violation') END;
                END
            SQL);
            DB::unprepared("CREATE TRIGGER generic_assessment_result_outbox_update_guard BEFORE UPDATE ON generic_assessment_result_outbox BEGIN SELECT RAISE(ABORT, 'generic assessment result outbox is append-only'); END");
            DB::unprepared("CREATE TRIGGER generic_assessment_result_outbox_delete_guard BEFORE DELETE ON generic_assessment_result_outbox BEGIN SELECT RAISE(ABORT, 'generic assessment result outbox is append-only'); END");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS guard_generic_assessment_result_outbox() CASCADE');
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS generic_assessment_result_outbox_insert_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS generic_assessment_result_outbox_update_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS generic_assessment_result_outbox_delete_guard');
        }

        Schema::dropIfExists('generic_assessment_result_outbox');
        Schema::table('generic_assessment_result_versions', function (Blueprint $table): void {
            $table->dropUnique('generic_result_outbox_source_unique');
        });
    }
};
