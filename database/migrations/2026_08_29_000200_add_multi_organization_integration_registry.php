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
        Schema::table('branches', function (Blueprint $table): void {
            $table->string('organization_code', 64)->nullable()->unique();
            $table->string('organization_type', 40)->default('INTERNAL_BRANCH');
            $table->string('display_name', 160)->nullable();
            $table->string('status', 24)->default('ACTIVE');
            $table->json('capabilities')->nullable();
            $table->json('allowed_funding_modes')->nullable();
        });

        DB::table('branches')->orderBy('id')->each(function (object $branch): void {
            DB::table('branches')->where('id', $branch->id)->update([
                'organization_code' => $branch->code,
                'display_name' => $branch->name,
                'status' => $branch->is_active ? 'ACTIVE' : 'INACTIVE',
                'allowed_funding_modes' => json_encode(
                    ['COMMERCIAL_SELF_PAY', 'SPONSORED', 'INVOICED_TO_ORGANIZATION', 'INTERNAL', 'WAIVED'],
                    JSON_THROW_ON_ERROR,
                ),
            ]);
        });

        Schema::table('participants', function (Blueprint $table): void {
            $table->string('source_system', 100)->default('DIRECT_PUBLIC');
            $table->string('attribution_source', 100)->nullable();
            $table->index(['source_system', 'created_at']);
        });
        DB::table('participants')->orderBy('id')->each(function (object $participant): void {
            $refCode = DB::table('branches')->where('id', $participant->referral_branch_id)->value('ref_code');
            DB::table('participants')->where('id', $participant->id)->update([
                'attribution_source' => is_string($refCode) ? $refCode : null,
            ]);
        });
        DB::table('participants')->whereIn('id', DB::table('selection_participants')->select('participant_id'))
            ->update(['source_system' => 'SELEKSI_BEASISWA_JEPANG']);

        Schema::create('integration_clients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('branches')->restrictOnDelete();
            $table->string('client_id', 100)->unique();
            $table->string('credential_reference', 160);
            $table->text('callback_base_url')->nullable();
            $table->string('result_delivery_mode', 32)->default('NONE');
            $table->boolean('enabled')->default(false);
            $table->json('rate_limit_policy')->nullable();
            $table->timestampTz('effective_from')->nullable();
            $table->timestampTz('effective_until')->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'enabled']);
        });

        Schema::create('integration_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('integration_client_id')->constrained()->cascadeOnDelete();
            $table->string('source_system', 100);
            $table->string('contract_version', 24)->default('v1');
            $table->string('authentication_mode', 32)->default('HMAC_SHA256');
            $table->json('allowed_assessment_packages');
            $table->string('participant_provisioning_mode', 32)->default('API');
            $table->string('commercial_mode', 32)->default('CONTRACT');
            $table->json('allowed_funding_modes');
            $table->string('callback_path', 255)->nullable();
            $table->json('callback_configuration')->nullable();
            $table->string('status', 24)->default('ACTIVE');
            $table->timestampTz('effective_from')->nullable();
            $table->timestampTz('effective_until')->nullable();
            $table->timestampsTz();

            $table->unique(['integration_client_id', 'source_system', 'contract_version'], 'integration_sources_client_source_version_unique');
        });

        Schema::create('assessment_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('integration_client_id')->constrained()->restrictOnDelete();
            $table->foreignId('organization_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('participant_id')->constrained()->restrictOnDelete();
            $table->foreignId('package_id')->constrained('packages')->restrictOnDelete();
            $table->ulid('assessment_attempt_id')->unique();
            $table->string('source_system', 100);
            $table->string('external_candidate_id', 100);
            $table->string('external_process_id', 100)->nullable();
            $table->string('external_registration_id', 100)->nullable();
            $table->string('assessment_round_id', 100)->nullable();
            $table->string('funding_mode', 40);
            $table->string('assessment_status', 32)->default('READY');
            $table->string('recommendation', 40)->nullable();
            $table->unsignedInteger('result_version')->default(0);
            $table->timestampTz('finalized_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->string('idempotency_key', 200);
            $table->char('request_hash', 64);
            $table->char('logical_assessment_key', 64);
            $table->json('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(['integration_client_id', 'idempotency_key'], 'assessment_participants_client_idempotency_unique');
            $table->unique(['integration_client_id', 'logical_assessment_key'], 'assessment_participants_logical_attempt_unique');
            $table->index(['integration_client_id', 'source_system', 'external_candidate_id'], 'assessment_participants_external_identity_idx');
            $table->index(['organization_id', 'assessment_status', 'created_at'], 'assessment_participants_org_status_idx');
        });

        $this->addPostgresControls();
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_participants');
        Schema::dropIfExists('integration_sources');
        Schema::dropIfExists('integration_clients');
        Schema::table('participants', function (Blueprint $table): void {
            $table->dropIndex(['source_system', 'created_at']);
            $table->dropColumn(['source_system', 'attribution_source']);
        });
        Schema::table('branches', function (Blueprint $table): void {
            $table->dropColumn([
                'organization_code', 'organization_type', 'display_name', 'status',
                'capabilities', 'allowed_funding_modes',
            ]);
        });
    }

    private function addPostgresControls(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE branches ALTER COLUMN organization_code SET NOT NULL');
        DB::statement('ALTER TABLE branches ALTER COLUMN display_name SET NOT NULL');
        DB::statement("ALTER TABLE branches ADD CONSTRAINT branches_organization_type_check CHECK (organization_type IN ('INTERNAL_CENTER','INTERNAL_BRANCH','SCHOLARSHIP_OPERATOR','EXTERNAL_LPK','COMPANY_PARTNER','EDUCATION_PARTNER','DIRECT_PUBLIC','OTHER_PARTNER'))");
        DB::statement("ALTER TABLE branches ADD CONSTRAINT branches_status_check CHECK (status IN ('ACTIVE','INACTIVE','SUSPENDED'))");
        DB::statement("ALTER TABLE integration_clients ADD CONSTRAINT integration_clients_delivery_check CHECK (result_delivery_mode IN ('CALLBACK','POLL','CALLBACK_AND_POLL','PORTAL_ONLY','NONE'))");
        DB::statement("ALTER TABLE integration_sources ADD CONSTRAINT integration_sources_authentication_check CHECK (authentication_mode = 'HMAC_SHA256')");
        DB::statement("ALTER TABLE integration_sources ADD CONSTRAINT integration_sources_provisioning_check CHECK (participant_provisioning_mode IN ('API','PORTAL','BATCH'))");
        DB::statement("ALTER TABLE integration_sources ADD CONSTRAINT integration_sources_commercial_check CHECK (commercial_mode IN ('CONTRACT','SELF_PAY','MIXED'))");
        DB::statement("ALTER TABLE integration_sources ADD CONSTRAINT integration_sources_status_check CHECK (status IN ('DRAFT','ACTIVE','SUSPENDED','RETIRED'))");
        DB::statement("ALTER TABLE assessment_participants ADD CONSTRAINT assessment_participants_status_check CHECK (assessment_status IN ('PROVISIONED','READY','IN_PROGRESS','COMPLETED','UNDER_REVIEW','FINALIZED','REVOKED','VOID'))");
        DB::statement("ALTER TABLE assessment_participants ADD CONSTRAINT assessment_participants_recommendation_check CHECK (recommendation IS NULL OR recommendation IN ('RECOMMENDED','RECOMMENDED_WITH_NOTES','NOT_RECOMMENDED','NEEDS_REVIEW'))");

        foreach (['integration_clients', 'integration_sources', 'assessment_participants'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY {$table}_service ON {$table} FOR ALL TO psikotes_runtime USING (app_private.app_role() = 'service') WITH CHECK (app_private.app_role() = 'service')");
        }
        foreach (['integration_clients', 'integration_sources'] as $table) {
            DB::statement("CREATE POLICY {$table}_super_admin ON {$table} FOR ALL TO psikotes_runtime USING (app_private.app_role() = 'super_admin') WITH CHECK (app_private.app_role() = 'super_admin')");
        }
        DB::statement("CREATE POLICY assessment_participants_organization_read ON assessment_participants FOR SELECT TO psikotes_runtime USING (app_private.app_role() IN ('super_admin','psychologist') OR (app_private.app_role() IN ('branch_admin','staff') AND organization_id = app_private.app_branch_id()))");
    }
};
