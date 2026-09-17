<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP POLICY IF EXISTS audit_logs_checkout_consent_privacy ON audit_logs;
            CREATE POLICY audit_logs_checkout_consent_privacy ON audit_logs AS RESTRICTIVE FOR SELECT TO psikotes_runtime
            USING (
                action NOT IN ('checkout.confirmed', 'checkout.consent_reaccepted')
                OR app_private.app_role() = 'service'
            );
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP POLICY IF EXISTS audit_logs_checkout_consent_privacy ON audit_logs;
            SQL);
    }
};
