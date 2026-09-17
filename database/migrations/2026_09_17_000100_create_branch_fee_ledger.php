<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const array TABLES = [
        'branch_fee_rules',
        'commission_entries',
        'withdrawal_requests',
        'withdrawal_request_items',
    ];

    public function up(): void
    {
        Schema::create('branch_fee_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('rate_basis', 32)->default('base_amount');
            $table->string('rate_type', 24);
            $table->unsignedInteger('percentage_bps')->nullable();
            $table->bigInteger('fixed_amount')->nullable();
            $table->char('currency', 3)->default('IDR');
            $table->string('rounding_mode', 16)->default('floor');
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_until')->nullable();
            $table->foreignId('created_by_admin_id')->constrained('admins')->restrictOnDelete();
            $table->json('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['branch_id', 'effective_from']);
            $table->index(['branch_id', 'effective_until']);
        });

        Schema::create('commission_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('participant_id')->nullable()->constrained('participants')->restrictOnDelete();
            $table->foreignId('assessment_bill_id')->nullable()->constrained('assessment_bills')->restrictOnDelete();
            $table->foreignId('assessment_bill_item_id')->nullable()->constrained('assessment_bill_items')->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id');
            $table->date('period_month');
            $table->timestampTz('paid_at');
            $table->foreignId('fee_rule_id')->constrained('branch_fee_rules')->restrictOnDelete();
            $table->string('rate_basis', 32);
            $table->bigInteger('rate_basis_amount');
            $table->bigInteger('gross_amount');
            $table->bigInteger('commission_amount');
            $table->char('currency', 3)->default('IDR');
            $table->json('calculation_snapshot');
            $table->string('status', 32)->default('accrued');
            $table->timestampTz('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['source_type', 'source_id'], 'commission_entries_source_unique');
            $table->unique(['id', 'branch_id'], 'commission_entries_branch_scope_unique');
            $table->index(['branch_id', 'period_month', 'status']);
            $table->index('assessment_bill_id');
            $table->index('order_id');
        });

        Schema::create('withdrawal_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->date('period_month');
            $table->string('public_reference', 29)->unique();
            $table->string('status', 32)->default('draft');
            $table->bigInteger('requested_amount');
            $table->bigInteger('approved_amount')->nullable();
            $table->char('currency', 3)->default('IDR');
            $table->foreignId('requested_by_admin_id')->constrained('admins')->restrictOnDelete();
            $table->foreignId('approved_by_admin_id')->nullable()->constrained('admins')->restrictOnDelete();
            $table->foreignId('paid_by_admin_id')->nullable()->constrained('admins')->restrictOnDelete();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('payout_reference', 160)->nullable();
            $table->json('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['branch_id', 'period_month', 'status']);
            $table->unique(['id', 'branch_id'], 'withdrawal_requests_branch_scope_unique');
        });

        Schema::create('withdrawal_request_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->unsignedBigInteger('withdrawal_request_id');
            $table->unsignedBigInteger('commission_entry_id');
            $table->bigInteger('amount_snapshot');
            $table->char('currency', 3)->default('IDR');
            $table->timestampsTz();

            $table->unique('commission_entry_id', 'withdrawal_request_items_commission_unique');
            $table->unique(['withdrawal_request_id', 'commission_entry_id'], 'withdrawal_request_items_pair_unique');
            $table->foreign(['withdrawal_request_id', 'branch_id'], 'withdrawal_request_items_request_scope_fk')
                ->references(['id', 'branch_id'])->on('withdrawal_requests')->restrictOnDelete();
            $table->foreign(['commission_entry_id', 'branch_id'], 'withdrawal_request_items_commission_scope_fk')
                ->references(['id', 'branch_id'])->on('commission_entries')->restrictOnDelete();
        });

        $this->addPostgresContract();
    }

    private function addPostgresContract(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE branch_fee_rules
                ADD CONSTRAINT branch_fee_rules_basis_check CHECK (rate_basis IN ('base_amount', 'paid_amount', 'net_amount')),
                ADD CONSTRAINT branch_fee_rules_type_check CHECK (
                    (rate_type = 'percentage' AND percentage_bps IS NOT NULL AND fixed_amount IS NULL AND percentage_bps >= 0)
                    OR (rate_type = 'fixed' AND fixed_amount IS NOT NULL AND percentage_bps IS NULL AND fixed_amount >= 0)
                ),
                ADD CONSTRAINT branch_fee_rules_currency_rounding_check CHECK (currency = 'IDR' AND rounding_mode = 'floor'),
                ADD CONSTRAINT branch_fee_rules_effective_window_check CHECK (
                    effective_until IS NULL OR effective_until > effective_from
                )
            SQL);
        // Historical interval overlap is intentionally guarded by the F7 service layer:
        // the service closes the current open rule before opening the next one and does
        // not create already-closed rules directly. The database still prevents two
        // currently-open rules per branch without adding a PostgreSQL-only exclusion
        // constraint at this phase.
        DB::statement('CREATE UNIQUE INDEX branch_fee_rules_one_open_per_branch ON branch_fee_rules (branch_id) WHERE effective_until IS NULL');

        DB::statement(<<<'SQL'
            ALTER TABLE commission_entries
                ADD CONSTRAINT commission_entries_source_check CHECK (
                    (source_type = 'assessment_bill_item'
                        AND assessment_bill_id IS NOT NULL
                        AND assessment_bill_item_id IS NOT NULL
                        AND source_id = assessment_bill_item_id
                        AND order_id IS NULL)
                    OR (source_type = 'direct_order'
                        AND order_id IS NOT NULL
                        AND source_id = order_id
                        AND assessment_bill_id IS NULL
                        AND assessment_bill_item_id IS NULL)
                ),
                ADD CONSTRAINT commission_entries_basis_check CHECK (rate_basis IN ('base_amount', 'paid_amount', 'net_amount')),
                ADD CONSTRAINT commission_entries_money_check CHECK (
                    currency = 'IDR'
                    AND rate_basis_amount >= 0
                    AND gross_amount >= 0
                    AND commission_amount >= 0
                ),
                ADD CONSTRAINT commission_entries_status_check CHECK (
                    status IN ('accrued', 'withdrawal_pending', 'withdrawn', 'void')
                    AND ((status = 'void' AND voided_at IS NOT NULL AND void_reason IS NOT NULL AND length(btrim(void_reason)) > 0)
                        OR (status <> 'void' AND voided_at IS NULL AND void_reason IS NULL))
                ),
                ADD CONSTRAINT commission_entries_snapshot_check CHECK (jsonb_typeof(calculation_snapshot::jsonb) = 'object')
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE withdrawal_requests
                ADD CONSTRAINT withdrawal_requests_reference_check CHECK (public_reference ~ '^WR_[0-7][0-9A-HJKMNP-TV-Z]{25}$'),
                ADD CONSTRAINT withdrawal_requests_status_check CHECK (status IN ('draft', 'submitted', 'approved', 'paid', 'rejected', 'cancelled')),
                ADD CONSTRAINT withdrawal_requests_money_check CHECK (
                    currency = 'IDR'
                    AND requested_amount >= 0
                    AND (approved_amount IS NULL OR approved_amount >= 0)
                ),
                ADD CONSTRAINT withdrawal_requests_lifecycle_check CHECK (
                    (status = 'draft' AND submitted_at IS NULL AND approved_at IS NULL AND paid_at IS NULL AND rejected_at IS NULL)
                    OR (status = 'submitted' AND submitted_at IS NOT NULL AND approved_at IS NULL AND paid_at IS NULL AND rejected_at IS NULL)
                    OR (status = 'approved' AND submitted_at IS NOT NULL AND approved_at IS NOT NULL AND paid_at IS NULL AND rejected_at IS NULL AND approved_by_admin_id IS NOT NULL)
                    OR (status = 'paid' AND submitted_at IS NOT NULL AND approved_at IS NOT NULL AND paid_at IS NOT NULL AND rejected_at IS NULL AND approved_by_admin_id IS NOT NULL AND paid_by_admin_id IS NOT NULL)
                    OR (status = 'rejected' AND submitted_at IS NOT NULL AND rejected_at IS NOT NULL AND paid_at IS NULL AND rejection_reason IS NOT NULL AND length(btrim(rejection_reason)) > 0)
                    OR (status = 'cancelled' AND paid_at IS NULL)
                )
            SQL);
        DB::statement("CREATE UNIQUE INDEX withdrawal_requests_one_active_period ON withdrawal_requests (branch_id, period_month) WHERE status IN ('draft', 'submitted', 'approved', 'paid')");

        DB::statement(<<<'SQL'
            ALTER TABLE withdrawal_request_items
                ADD CONSTRAINT withdrawal_request_items_money_check CHECK (currency = 'IDR' AND amount_snapshot >= 0)
            SQL);

        $this->addPostgresRls();
    }

    private function addPostgresRls(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("REVOKE ALL PRIVILEGES ON {$table} FROM PUBLIC, psikotes_runtime");
            DB::statement("REVOKE ALL PRIVILEGES ON SEQUENCE {$table}_id_seq FROM PUBLIC, psikotes_runtime");
            DB::statement("GRANT SELECT, INSERT, UPDATE ON {$table} TO psikotes_runtime");
            DB::statement("GRANT USAGE, SELECT ON SEQUENCE {$table}_id_seq TO psikotes_runtime");
            DB::statement("CREATE POLICY {$table}_service ON {$table} FOR ALL TO psikotes_runtime USING (app_private.app_role() = 'service') WITH CHECK (app_private.app_role() = 'service')");
            DB::statement("CREATE POLICY {$table}_tenant_read ON {$table} FOR SELECT TO psikotes_runtime USING (
                app_private.app_role() = 'super_admin'
                OR (app_private.app_role() IN ('branch_admin', 'staff') AND branch_id = app_private.app_branch_id())
            )");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawal_request_items');
        Schema::dropIfExists('withdrawal_requests');
        Schema::dropIfExists('commission_entries');
        Schema::dropIfExists('branch_fee_rules');
    }
};
