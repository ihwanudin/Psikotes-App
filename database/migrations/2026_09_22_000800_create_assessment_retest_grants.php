<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Persistence for App\Domain\AssessmentSessions\AssessmentRetestGrant
 * (item 19, tasks/handoffs/f2/retest-limit-plan.md). The domain DTO and its
 * full validation logic already existed and were already tested
 * (AssessmentAttemptAllocationPolicy::decide()) -- what was missing was
 * anywhere to durably store a real grant. Owner's decision: the first
 * config('assessment_retests.free_attempt_limit') attempts need no
 * approval at all; every attempt past that needs its own individual grant
 * from super_admin/central_admin(once it exists)/psychologist, with no
 * upper bound -- repeated human approval is the deterrent, not a cap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_retest_grants', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('participant_id')->constrained()->restrictOnDelete();
            $table->string('test_type', 24);
            $table->foreignId('assessment_case_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->string('authorization_id', 100);
            $table->text('reason');
            $table->foreignId('approved_by_admin_id')->constrained('admins')->restrictOnDelete();
            $table->timestampTz('approved_at');
            $table->string('status', 24)->default('active');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();

            $table->index(['participant_id', 'test_type', 'created_at']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Partial, not table-wide: a grant can be superseded (revoked
        // before use) and a new one issued for the same attempt slot. At
        // most one ACTIVE grant per (participant, test_type, attempt) at
        // any time -- same shape as bridge_funding_grants' own partial
        // unique index.
        DB::statement(
            "CREATE UNIQUE INDEX assessment_retest_grants_active_attempt_unique ON assessment_retest_grants (participant_id, test_type, attempt_number) WHERE status = 'active'",
        );

        DB::statement(<<<'SQL'
            ALTER TABLE assessment_retest_grants
                ADD CONSTRAINT assessment_retest_grants_test_type_check CHECK (test_type IN ('ist', 'papi', 'rmib', 'kraepelin', 'dass21')),
                ADD CONSTRAINT assessment_retest_grants_attempt_check CHECK (attempt_number >= 1),
                ADD CONSTRAINT assessment_retest_grants_reason_check CHECK (length(btrim(reason)) > 0),
                ADD CONSTRAINT assessment_retest_grants_authorization_check CHECK (length(btrim(authorization_id)) > 0),
                ADD CONSTRAINT assessment_retest_grants_status_check CHECK (
                    status IN ('active', 'consumed', 'revoked')
                    AND ((status = 'consumed') = (consumed_at IS NOT NULL))
                )
            SQL);

        DB::statement('REVOKE ALL PRIVILEGES ON assessment_retest_grants FROM psikotes_runtime');
        DB::statement('GRANT SELECT, INSERT, UPDATE ON assessment_retest_grants TO psikotes_runtime');

        DB::statement('ALTER TABLE assessment_retest_grants ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE assessment_retest_grants FORCE ROW LEVEL SECURITY');

        // Read: the three roles who can ever approve a retest (item 19) --
        // super_admin and psychologist today, central_admin once that role
        // exists. Not branch_admin/staff-visible, matching the owner's
        // decision verbatim.
        DB::statement(<<<'SQL'
            CREATE POLICY assessment_retest_grants_read ON assessment_retest_grants FOR SELECT TO psikotes_runtime
                USING (app_private.app_role() IN ('service', 'super_admin', 'psychologist'));
            SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY assessment_retest_grants_service_write ON assessment_retest_grants FOR ALL TO psikotes_runtime
                USING (app_private.app_role() = 'service')
                WITH CHECK (app_private.app_role() = 'service');
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_retest_grants');
    }
};
