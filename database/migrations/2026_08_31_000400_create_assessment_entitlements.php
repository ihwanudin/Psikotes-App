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
        Schema::table('assessment_charges', function (Blueprint $table): void {
            $table->unique(['id', 'assessment_participant_id', 'organization_id', 'participant_id'], 'charge_entitlement_scope_unique');
        });
        Schema::create('assessment_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('charge_id');
            $table->foreignId('assessment_participant_id');
            $table->foreignId('organization_id');
            $table->foreignId('participant_id');
            $table->string('test_type', 24);
            $table->string('status', 24)->default('locked');
            $table->timestampTz('ready_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['assessment_participant_id', 'test_type'], 'assessment_entitlement_attempt_test_unique');
            $table->foreign(['charge_id', 'assessment_participant_id', 'organization_id', 'participant_id'], 'assessment_entitlement_charge_scope_fk')
                ->references(['id', 'assessment_participant_id', 'organization_id', 'participant_id'])->on('assessment_charges')->restrictOnDelete();
            $table->index(['organization_id', 'participant_id']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE assessment_entitlements
                    ADD CONSTRAINT assessment_entitlement_type_check CHECK (test_type IN ('ist', 'papi', 'rmib', 'kraepelin', 'dass21')),
                    ADD CONSTRAINT assessment_entitlement_state_check CHECK (
                        (status = 'locked' AND ready_at IS NULL AND started_at IS NULL AND completed_at IS NULL)
                        OR (status = 'ready' AND ready_at IS NOT NULL AND started_at IS NULL AND completed_at IS NULL)
                        OR (status = 'in_progress' AND ready_at IS NOT NULL AND started_at IS NOT NULL AND completed_at IS NULL)
                        OR (status = 'done' AND ready_at IS NOT NULL AND started_at IS NOT NULL AND completed_at IS NOT NULL)
                    ),
                    ADD CONSTRAINT assessment_entitlement_time_check CHECK (
                        (started_at IS NULL OR started_at >= ready_at)
                        AND (completed_at IS NULL OR completed_at >= started_at)
                    )
                SQL);
            DB::statement('ALTER TABLE assessment_entitlements ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE assessment_entitlements FORCE ROW LEVEL SECURITY');
            DB::statement("CREATE POLICY assessment_entitlements_service ON assessment_entitlements FOR ALL TO psikotes_runtime USING (app_private.app_role() = 'service') WITH CHECK (app_private.app_role() = 'service')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_entitlements');
        Schema::table('assessment_charges', function (Blueprint $table): void {
            $table->dropUnique('charge_entitlement_scope_unique');
        });
    }
};
