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
        Schema::table('participants', function (Blueprint $table): void {
            $table->unique(['id', 'branch_id'], 'participants_billing_scope_unique');
        });
        Schema::table('assessment_participants', function (Blueprint $table): void {
            $table->unique(['id', 'organization_id', 'participant_id', 'package_id'], 'assessment_attempt_billing_scope_unique');
        });
        Schema::create('assessment_charges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('assessment_participant_id')->unique();
            $table->foreignId('organization_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('participant_id');
            $table->foreignId('package_id')->constrained('packages')->restrictOnDelete();
            $table->string('payer_type', 24);
            $table->bigInteger('base_amount');
            $table->bigInteger('consultation_amount');
            $table->boolean('consultation_requested')->default(false);
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->json('price_snapshot');
            $table->json('policy_snapshot');
            $table->timestampTz('free_settled_at')->nullable();
            $table->timestampsTz();
            $table->foreign(['assessment_participant_id', 'organization_id', 'participant_id', 'package_id'], 'assessment_charge_attempt_scope_fk')
                ->references(['id', 'organization_id', 'participant_id', 'package_id'])->on('assessment_participants')->restrictOnDelete();
            $table->foreign(['participant_id', 'organization_id'], 'assessment_charge_participant_scope_fk')
                ->references(['id', 'branch_id'])->on('participants')->restrictOnDelete();
            $table->index(['organization_id', 'created_at']);
            $table->index(['participant_id', 'created_at']);
        });
        Schema::create('assessment_bills', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('branches')->restrictOnDelete();
            $table->string('payer_type', 24);
            $table->foreignId('payer_participant_id')->nullable();
            $table->string('public_reference', 29)->unique();
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->integer('item_count');
            $table->char('selection_hash', 64);
            $table->string('idempotency_key', 200);
            $table->char('request_hash', 64);
            $table->string('status', 24)->default('reserved');
            $table->foreignId('payment_method_id')->constrained('payment_methods')->restrictOnDelete();
            $table->string('gateway_ref', 160)->nullable()->unique();
            $table->text('invoice_url')->nullable();
            $table->string('proof_object_key', 512)->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->foreignId('verified_by_admin_id')->nullable()->constrained('admins')->restrictOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->timestampsTz();
            $table->foreign(['payer_participant_id', 'organization_id'], 'assessment_bill_payer_scope_fk')
                ->references(['id', 'branch_id'])->on('participants')->restrictOnDelete();
            $table->index(['organization_id', 'status', 'created_at']);
            $table->index(['status', 'expires_at']);
        });
        // NULL payer_participant_id must not allow duplicate organization keys.
        DB::statement("CREATE UNIQUE INDEX assessment_bill_org_idempotency_unique ON assessment_bills (organization_id, idempotency_key) WHERE payer_type = 'organization'");
        DB::statement("CREATE UNIQUE INDEX assessment_bill_self_idempotency_unique ON assessment_bills (organization_id, payer_participant_id, idempotency_key) WHERE payer_type = 'self'");
        $this->addPostgresControls();
    }

    private function addPostgresControls(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE assessment_charges
                ADD CONSTRAINT assessment_charge_money_check CHECK (
                    currency = 'IDR' AND base_amount >= 0 AND consultation_amount >= 0 AND amount >= 0
                    AND base_amount::numeric + consultation_amount::numeric = amount::numeric
                    AND ((consultation_requested AND consultation_amount > 0)
                        OR (NOT consultation_requested AND consultation_amount = 0))
                ),
                ADD CONSTRAINT assessment_charge_payer_check CHECK (payer_type IN ('self', 'organization')),
                ADD CONSTRAINT assessment_charge_snapshot_check CHECK (
                    jsonb_typeof(price_snapshot::jsonb) = 'object'
                    AND jsonb_typeof(policy_snapshot::jsonb) = 'object'
                ),
                ADD CONSTRAINT assessment_charge_free_check CHECK (free_settled_at IS NULL OR amount = 0)
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE assessment_bills
                ADD CONSTRAINT assessment_bill_money_check CHECK (currency = 'IDR' AND amount > 0 AND item_count > 0),
                ADD CONSTRAINT assessment_bill_payer_check CHECK (
                    (payer_type = 'organization' AND payer_participant_id IS NULL)
                    OR (payer_type = 'self' AND payer_participant_id IS NOT NULL AND item_count = 1)
                ),
                ADD CONSTRAINT assessment_bill_identity_check CHECK (
                    public_reference ~ '^AB_[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND selection_hash ~ '^[0-9a-f]{64}$' AND request_hash ~ '^[0-9a-f]{64}$'
                    AND length(btrim(idempotency_key)) > 0
                ),
                ADD CONSTRAINT assessment_bill_status_check CHECK (
                    status IN ('reserved', 'issuing', 'unknown', 'pending', 'paid', 'expired', 'rejected')
                    AND ((status = 'paid' AND paid_at IS NOT NULL) OR (status <> 'paid' AND paid_at IS NULL))
                ),
                ADD CONSTRAINT assessment_bill_verifier_check CHECK (
                    (verified_at IS NULL AND verified_by_admin_id IS NULL)
                    OR (verified_at IS NOT NULL AND verified_by_admin_id IS NOT NULL)
                )
            SQL);

        // Fail closed while the participant/branch policies are implemented in P6c.
        foreach (['assessment_bills', 'assessment_charges'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY {$table}_service ON {$table} FOR ALL TO psikotes_runtime USING (app_private.app_role() = 'service') WITH CHECK (app_private.app_role() = 'service')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_bills');
        Schema::dropIfExists('assessment_charges');
        Schema::table('assessment_participants', function (Blueprint $table): void {
            $table->dropUnique('assessment_attempt_billing_scope_unique');
        });
        Schema::table('participants', function (Blueprint $table): void {
            $table->dropUnique('participants_billing_scope_unique');
        });
    }
};
