<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add 'central_admin' to the packages/package_items RLS policies created
 * by 2026_09_22_000200_harden_test_package_catalog_rls.php (PR #99).
 *
 * central_admin has ManageTestPackages = true, so it needs:
 * - packages_read + packages_update (Filament TestPackageResource edits)
 * - package_items_read (same broad read as other admin roles)
 *
 * package_items_update stays service-only: no admin path ever writes to
 * package_items (Filament only edits packages fields, and package_items
 * has no admin UI at all).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->execute(<<<'SQL'
            DROP POLICY IF EXISTS packages_read ON packages;
            DROP POLICY IF EXISTS packages_update ON packages;
            DROP POLICY IF EXISTS package_items_read ON package_items;

            CREATE POLICY packages_read ON packages
                FOR SELECT TO psikotes_runtime
                USING (app_private.app_role() IN ('service', 'super_admin', 'central_admin', 'branch_admin', 'staff', 'psychologist'));
            CREATE POLICY packages_update ON packages
                FOR UPDATE TO psikotes_runtime
                USING (app_private.app_role() IN ('service', 'super_admin', 'central_admin'))
                WITH CHECK (app_private.app_role() IN ('service', 'super_admin', 'central_admin'));
            CREATE POLICY package_items_read ON package_items
                FOR SELECT TO psikotes_runtime
                USING (app_private.app_role() IN ('service', 'super_admin', 'central_admin', 'branch_admin', 'staff', 'psychologist'));
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->execute(<<<'SQL'
            DROP POLICY IF EXISTS packages_read ON packages;
            DROP POLICY IF EXISTS packages_update ON packages;
            DROP POLICY IF EXISTS package_items_read ON package_items;

            CREATE POLICY packages_read ON packages
                FOR SELECT TO psikotes_runtime
                USING (app_private.app_role() IN ('service', 'super_admin', 'branch_admin', 'staff', 'psychologist'));
            CREATE POLICY packages_update ON packages
                FOR UPDATE TO psikotes_runtime
                USING (app_private.app_role() IN ('service', 'super_admin'))
                WITH CHECK (app_private.app_role() IN ('service', 'super_admin'));
            CREATE POLICY package_items_read ON package_items
                FOR SELECT TO psikotes_runtime
                USING (app_private.app_role() IN ('service', 'super_admin', 'branch_admin', 'staff', 'psychologist'));
            SQL);
    }

    private function execute(string $sql): void
    {
        DB::connection()->getPdo()->exec($sql);
    }
};
