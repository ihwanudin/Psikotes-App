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
        Schema::create('commission_ledger_gaps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id');
            $table->string('reason_code', 64);
            $table->timestampTz('paid_at');
            $table->char('currency', 3)->default('IDR');
            $table->bigInteger('amount')->default(0);
            $table->json('context')->nullable();
            $table->string('status', 24)->default('open');
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->unique(['source_type', 'source_id'], 'commission_ledger_gaps_source_unique');
            $table->index(['branch_id', 'status', 'reason_code']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE commission_ledger_gaps
                ADD CONSTRAINT commission_ledger_gaps_source_check CHECK (
                    source_type IN ('direct_order', 'assessment_bill_item')
                ),
                ADD CONSTRAINT commission_ledger_gaps_reason_check CHECK (
                    reason_code IN ('fee_rule_missing', 'fee_rule_ambiguous', 'basis_unavailable', 'calculation_error')
                ),
                ADD CONSTRAINT commission_ledger_gaps_status_check CHECK (
                    status IN ('open', 'resolved')
                    AND ((status = 'open' AND resolved_at IS NULL)
                        OR (status = 'resolved' AND resolved_at IS NOT NULL))
                ),
                ADD CONSTRAINT commission_ledger_gaps_money_check CHECK (
                    currency = 'IDR' AND amount >= 0
                ),
                ADD CONSTRAINT commission_ledger_gaps_context_check CHECK (
                    context IS NULL OR jsonb_typeof(context::jsonb) = 'object'
                )
            SQL);
        DB::statement('ALTER TABLE commission_ledger_gaps ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE commission_ledger_gaps FORCE ROW LEVEL SECURITY');
        DB::statement('REVOKE ALL PRIVILEGES ON commission_ledger_gaps FROM PUBLIC, psikotes_runtime');
        DB::statement('REVOKE ALL PRIVILEGES ON SEQUENCE commission_ledger_gaps_id_seq FROM PUBLIC, psikotes_runtime');
        DB::statement('GRANT SELECT, INSERT, UPDATE ON commission_ledger_gaps TO psikotes_runtime');
        DB::statement('GRANT USAGE, SELECT ON SEQUENCE commission_ledger_gaps_id_seq TO psikotes_runtime');
        DB::statement("CREATE POLICY commission_ledger_gaps_service ON commission_ledger_gaps FOR ALL TO psikotes_runtime USING (app_private.app_role() = 'service') WITH CHECK (app_private.app_role() = 'service')");
        DB::statement("CREATE POLICY commission_ledger_gaps_tenant_read ON commission_ledger_gaps FOR SELECT TO psikotes_runtime USING (
            app_private.app_role() = 'super_admin'
            OR (app_private.app_role() IN ('branch_admin', 'staff') AND branch_id = app_private.app_branch_id())
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_ledger_gaps');
    }
};
