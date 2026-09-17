<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const array TABLES = ['assessment_bills', 'assessment_bill_items', 'assessment_charges', 'assessment_entitlements'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::TABLES as $table) {
            $participantRead = in_array($table, ['assessment_charges', 'assessment_entitlements'], true)
                ? "OR (app_private.app_role() = 'participant' AND organization_id = app_private.app_branch_id() AND participant_id = app_private.app_participant_id())"
                : '';
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            // User roles only read. Existing service policies remain the only write path.
            DB::statement("CREATE POLICY {$table}_tenant_read ON {$table} FOR SELECT TO psikotes_runtime USING (
                app_private.app_role() = 'super_admin'
                OR (app_private.app_role() = 'branch_admin' AND organization_id = app_private.app_branch_id())
                {$participantRead}
            )");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::TABLES as $table) {
            DB::statement("DROP POLICY IF EXISTS {$table}_tenant_read ON {$table}");
        }
    }
};
