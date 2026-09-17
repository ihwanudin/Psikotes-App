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
        Schema::table('assessment_bills', function (Blueprint $table): void {
            $table->unique(['id', 'organization_id', 'payer_type', 'currency'], 'bill_item_parent_scope_unique');
            $table->unique(['id', 'organization_id', 'payer_participant_id'], 'bill_item_payer_scope_unique');
        });
        Schema::table('assessment_charges', function (Blueprint $table): void {
            $table->unique(['id', 'organization_id', 'participant_id', 'payer_type', 'amount', 'currency'], 'charge_item_scope_unique');
        });
        Schema::create('assessment_bill_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bill_id');
            $table->foreignId('charge_id')->unique();
            $table->foreignId('organization_id');
            $table->foreignId('participant_id');
            $table->string('payer_type', 24);
            $table->foreignId('payer_participant_id')->nullable();
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->timestampTz('settled_at')->nullable();
            $table->timestampsTz();
            $table->foreign(['bill_id', 'organization_id', 'payer_type', 'currency'], 'bill_item_parent_scope_fk')
                ->references(['id', 'organization_id', 'payer_type', 'currency'])->on('assessment_bills')->restrictOnDelete();
            $table->foreign(['bill_id', 'organization_id', 'payer_participant_id'], 'bill_item_payer_scope_fk')
                ->references(['id', 'organization_id', 'payer_participant_id'])->on('assessment_bills')->restrictOnDelete();
            $table->foreign(['charge_id', 'organization_id', 'participant_id', 'payer_type', 'amount', 'currency'], 'bill_item_charge_scope_fk')
                ->references(['id', 'organization_id', 'participant_id', 'payer_type', 'amount', 'currency'])->on('assessment_charges')->restrictOnDelete();
            $table->index(['organization_id', 'bill_id']);
        });
        DB::statement("CREATE UNIQUE INDEX bill_item_single_self_unique ON assessment_bill_items (bill_id) WHERE payer_type = 'self'");
        if (DB::getDriverName() === 'pgsql') {
            // MATCH SIMPLE skips nullable FKs; this CHECK makes self binding mandatory.
            DB::statement(<<<'SQL'
                ALTER TABLE assessment_bill_items
                    ADD CONSTRAINT bill_item_money_check CHECK (amount > 0 AND currency = 'IDR'),
                    ADD CONSTRAINT bill_item_payer_check CHECK (
                        (payer_type = 'organization' AND payer_participant_id IS NULL)
                        OR (payer_type = 'self' AND payer_participant_id IS NOT NULL AND payer_participant_id = participant_id)
                    )
                SQL);
            DB::statement('ALTER TABLE assessment_bill_items ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE assessment_bill_items FORCE ROW LEVEL SECURITY');
            DB::statement("CREATE POLICY assessment_bill_items_service ON assessment_bill_items FOR ALL TO psikotes_runtime USING (app_private.app_role() = 'service') WITH CHECK (app_private.app_role() = 'service')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_bill_items');
        Schema::table('assessment_charges', function (Blueprint $table): void {
            $table->dropUnique('charge_item_scope_unique');
        });
        Schema::table('assessment_bills', function (Blueprint $table): void {
            $table->dropUnique('bill_item_payer_scope_unique');
            $table->dropUnique('bill_item_parent_scope_unique');
        });
    }
};
