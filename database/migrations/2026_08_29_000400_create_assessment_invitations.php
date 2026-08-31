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
        Schema::create('assessment_invitations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('assessment_participant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('issued_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->boolean('active_marker')->nullable();
            $table->string('status', 24)->default('PENDING');
            $table->unsignedInteger('issue_number');
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['assessment_participant_id', 'active_marker'], 'assessment_invitations_one_active_unique');
            $table->index(['status', 'expires_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE assessment_invitations ADD CONSTRAINT assessment_invitations_status_check CHECK (status IN ('PENDING','CONSUMED','REVOKED','EXPIRED'))");
            DB::statement('ALTER TABLE assessment_invitations ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE assessment_invitations FORCE ROW LEVEL SECURITY');
            DB::statement("CREATE POLICY assessment_invitations_service ON assessment_invitations FOR ALL TO psikotes_runtime USING (app_private.app_role() = 'service') WITH CHECK (app_private.app_role() = 'service')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_invitations');
    }
};
