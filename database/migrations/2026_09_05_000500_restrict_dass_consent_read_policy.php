<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DROP POLICY IF EXISTS consent_records_read ON consent_records;
            DROP POLICY IF EXISTS consent_records_dass_privacy ON consent_records;
            CREATE POLICY consent_records_read ON consent_records FOR SELECT TO psikotes_runtime
            USING (
                app_private.app_role() IN ('service', 'psychologist')
                OR (app_private.app_role() = 'participant' AND participant_id = app_private.app_participant_id())
                OR (
                    consent_type <> 'dass'
                    AND (
                        app_private.app_role() = 'super_admin'
                        OR (
                            app_private.app_role() IN ('branch_admin', 'staff')
                            AND EXISTS (
                                SELECT 1 FROM participants
                                WHERE participants.id = consent_records.participant_id
                                  AND participants.branch_id = app_private.app_branch_id()
                            )
                        )
                    )
                )
            );
            CREATE POLICY consent_records_dass_privacy ON consent_records AS RESTRICTIVE FOR SELECT TO psikotes_runtime
            USING (
                consent_type <> 'dass'
                OR app_private.app_role() IN ('service', 'psychologist')
                OR (app_private.app_role() = 'participant' AND participant_id = app_private.app_participant_id())
            );
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP POLICY IF EXISTS consent_records_dass_privacy ON consent_records;
            DROP POLICY IF EXISTS consent_records_read ON consent_records;
            CREATE POLICY consent_records_read ON consent_records FOR SELECT TO psikotes_runtime
            USING (
                app_private.app_role() IN ('service', 'super_admin', 'psychologist')
                OR (app_private.app_role() = 'participant' AND participant_id = app_private.app_participant_id())
                OR (
                    app_private.app_role() IN ('branch_admin', 'staff')
                    AND EXISTS (
                        SELECT 1 FROM participants
                        WHERE participants.id = consent_records.participant_id
                          AND participants.branch_id = app_private.app_branch_id()
                    )
                )
            );
            SQL);
    }
};
