<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const INDEX = 'instrument_versions_one_active_code_unique';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $duplicate = DB::selectOne(<<<'SQL'
            SELECT code
            FROM instrument_versions
            WHERE is_active = TRUE
            GROUP BY code
            HAVING COUNT(*) > 1
            ORDER BY code
            LIMIT 1
            SQL);
        if ($duplicate !== null) {
            throw new RuntimeException(
                "Instrument version history has duplicate active rows for code {$duplicate->code}; migration refused.",
            );
        }

        DB::statement('CREATE UNIQUE INDEX '.self::INDEX.' ON instrument_versions (code) WHERE is_active = TRUE');
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION instrument_versions_guard_history() RETURNS trigger
            LANGUAGE plpgsql
            AS $function$
            BEGIN
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

                IF NOT OLD.is_active OR NEW.is_active OR NEW.updated_at <= OLD.updated_at THEN
                    RAISE EXCEPTION 'instrument version history only permits active-to-inactive deactivation with a newer updated_at'
                        USING ERRCODE = 'P0001';
                END IF;

                RETURN NEW;
            END;
            $function$;

            CREATE TRIGGER instrument_versions_guard_history_trigger
            BEFORE UPDATE OR DELETE ON instrument_versions
            FOR EACH ROW EXECUTE FUNCTION instrument_versions_guard_history();
            SQL);

        DB::unprepared(<<<'SQL'
            REVOKE ALL PRIVILEGES ON TABLE instrument_versions FROM psikotes_runtime;
            REVOKE ALL PRIVILEGES ON SEQUENCE instrument_versions_id_seq FROM psikotes_runtime;
            GRANT SELECT, INSERT, UPDATE ON TABLE instrument_versions TO psikotes_runtime;
            GRANT USAGE, SELECT ON SEQUENCE instrument_versions_id_seq TO psikotes_runtime;

            ALTER TABLE instrument_versions ENABLE ROW LEVEL SECURITY;
            ALTER TABLE instrument_versions FORCE ROW LEVEL SECURITY;

            CREATE POLICY instrument_versions_service_read ON instrument_versions
                FOR SELECT TO psikotes_runtime
                USING (app_private.app_role() = 'service');
            CREATE POLICY instrument_versions_service_insert ON instrument_versions
                FOR INSERT TO psikotes_runtime
                WITH CHECK (app_private.app_role() = 'service');
            CREATE POLICY instrument_versions_service_update ON instrument_versions
                FOR UPDATE TO psikotes_runtime
                USING (app_private.app_role() = 'service')
                WITH CHECK (app_private.app_role() = 'service');
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        if (DB::table('instrument_versions')->exists()) {
            throw new RuntimeException('Cannot weaken populated instrument version history; downgrade refused.');
        }

        DB::unprepared(<<<'SQL'
            DROP POLICY instrument_versions_service_update ON instrument_versions;
            DROP POLICY instrument_versions_service_insert ON instrument_versions;
            DROP POLICY instrument_versions_service_read ON instrument_versions;
            ALTER TABLE instrument_versions NO FORCE ROW LEVEL SECURITY;
            ALTER TABLE instrument_versions DISABLE ROW LEVEL SECURITY;

            REVOKE ALL PRIVILEGES ON TABLE instrument_versions FROM psikotes_runtime;
            REVOKE ALL PRIVILEGES ON SEQUENCE instrument_versions_id_seq FROM psikotes_runtime;
            GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE instrument_versions TO psikotes_runtime;
            GRANT USAGE, SELECT, UPDATE ON SEQUENCE instrument_versions_id_seq TO psikotes_runtime;

            DROP TRIGGER instrument_versions_guard_history_trigger ON instrument_versions;
            DROP FUNCTION instrument_versions_guard_history();
            SQL);
        DB::statement('DROP INDEX '.self::INDEX);
    }
};
