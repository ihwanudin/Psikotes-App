<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The invoice-to-collect-later for bridge funding (item 18 -- the holding
 * company funds a participant's assessment, an admin approves on
 * management's instruction, the system bills the holding company later).
 * See tasks/handoffs/f2/legacy-entitlement-provisioning-closure-plan.md for
 * why this is a small dedicated table instead of assessment_bills:
 * assessment_bills' charge_id is a NOT NULL FK into assessment_charges,
 * which is only ever populated by selection-integration provisioning --
 * structurally impossible to reuse for a DIRECT_PUBLIC order without
 * fabricating a fake selection-integration row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bridge_funding_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('participant_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3)->default('IDR');
            $table->text('management_reference');
            $table->foreignId('approved_by_admin_id')->constrained('admins')->restrictOnDelete();
            $table->timestampTz('approved_at');
            $table->string('status', 24)->default('invoiced');
            $table->timestampTz('collected_at')->nullable();
            $table->timestampsTz();

            $table->index(['branch_id', 'created_at']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Partial, not table-wide: a grant can be written off and a new one
        // issued later for the same order (e.g. the first approval was
        // reversed). At most one ACTIVE (invoiced/collected) grant per
        // order at any time -- the actual concurrency guard, so two admins
        // approving the same order at once can produce at most one grant.
        DB::statement(
            "CREATE UNIQUE INDEX bridge_funding_grants_active_order_unique ON bridge_funding_grants (order_id) WHERE status IN ('invoiced', 'collected')",
        );

        DB::statement(<<<'SQL'
            ALTER TABLE bridge_funding_grants
                ADD CONSTRAINT bridge_funding_grants_money_check CHECK (amount > 0 AND currency = 'IDR'),
                ADD CONSTRAINT bridge_funding_grants_reference_check CHECK (length(btrim(management_reference)) > 0),
                ADD CONSTRAINT bridge_funding_grants_status_check CHECK (
                    status IN ('invoiced', 'collected', 'written_off')
                    AND ((status = 'collected') = (collected_at IS NOT NULL))
                )
            SQL);

        DB::statement('REVOKE ALL PRIVILEGES ON bridge_funding_grants FROM psikotes_runtime');
        DB::statement('GRANT SELECT, INSERT, UPDATE ON bridge_funding_grants TO psikotes_runtime');

        DB::statement('ALTER TABLE bridge_funding_grants ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE bridge_funding_grants FORCE ROW LEVEL SECURITY');

        // Read: super_admin only for now (holding-company-level financial
        // data, not branch-operational). central_admin will be added to
        // this list once that role exists (tasks/handoffs/f2/central-admin-role-plan.md),
        // consistent with the approving-role recommendation in the plan.
        DB::statement(<<<'SQL'
            CREATE POLICY bridge_funding_grants_read ON bridge_funding_grants FOR SELECT TO psikotes_runtime
                USING (app_private.app_role() IN ('service', 'super_admin'));
            CREATE POLICY bridge_funding_grants_service_write ON bridge_funding_grants FOR ALL TO psikotes_runtime
                USING (app_private.app_role() = 'service')
                WITH CHECK (app_private.app_role() = 'service');
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('bridge_funding_grants');
    }
};
