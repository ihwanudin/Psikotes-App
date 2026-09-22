<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin account lifecycle (2026-09-22, Lead sign-off). One-time set-password
 * links for admin creation and password reset -- same shape as
 * assessment_invitations (opaque random token, HMAC-hashed at rest, never
 * stored raw), issued by an operator running a console command (there is no
 * authenticated admin acting, so `issued_by_operator` is a free-text
 * identifier, not an admins.id foreign key) rather than another admin.
 *
 * `active_marker` mirrors assessment_invitations' one-active-per-subject
 * pattern: true only while status='PENDING', null otherwise, backed by a
 * unique index on (admin_id, active_marker) -- issuing a new link for the
 * same admin must revoke the prior active one first (application-level),
 * and this constraint makes a missed revoke a hard failure instead of a
 * silent second-active-link bug.
 *
 * RLS follows the established least-privilege pattern from this
 * remediation effort: REVOKE the default blanket grant, GRANT only
 * SELECT/INSERT/UPDATE (no DELETE -- a consumed/revoked/expired token
 * stays as a row, its `status` changes, matching admins' own
 * never-delete design), service-role-only policies. Both the issuing
 * command and the (unauthenticated, by necessity -- the new admin has no
 * session yet) consuming web endpoint run under
 * RlsContextRunner::runAsService().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_password_setup_tokens', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('admin_id')->constrained('admins')->restrictOnDelete();
            $table->string('issued_by_operator', 160);
            $table->char('token_hash', 64)->unique();
            $table->boolean('active_marker')->nullable();
            $table->string('status', 24)->default('PENDING');
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['admin_id', 'active_marker'], 'admin_password_setup_tokens_one_active_unique');
            $table->index(['status', 'expires_at']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->execute(<<<'SQL'
            ALTER TABLE admin_password_setup_tokens ADD CONSTRAINT admin_password_setup_tokens_status_check
                CHECK (status IN ('PENDING', 'CONSUMED', 'REVOKED', 'EXPIRED'));

            REVOKE ALL PRIVILEGES ON TABLE admin_password_setup_tokens FROM psikotes_runtime;
            GRANT SELECT, INSERT, UPDATE ON TABLE admin_password_setup_tokens TO psikotes_runtime;

            ALTER TABLE admin_password_setup_tokens ENABLE ROW LEVEL SECURITY;
            ALTER TABLE admin_password_setup_tokens FORCE ROW LEVEL SECURITY;

            CREATE POLICY admin_password_setup_tokens_service_select ON admin_password_setup_tokens
                FOR SELECT TO psikotes_runtime
                USING (app_private.app_role() = 'service');
            CREATE POLICY admin_password_setup_tokens_service_insert ON admin_password_setup_tokens
                FOR INSERT TO psikotes_runtime
                WITH CHECK (app_private.app_role() = 'service');
            CREATE POLICY admin_password_setup_tokens_service_update ON admin_password_setup_tokens
                FOR UPDATE TO psikotes_runtime
                USING (app_private.app_role() = 'service')
                WITH CHECK (app_private.app_role() = 'service');
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_password_setup_tokens');
    }

    /**
     * DB::unprepared() requires a literal-string argument (PHPStan); this
     * migration's SQL is a hardcoded heredoc, never external input, so raw
     * PDO exec() is the established workaround (see PR #57).
     */
    private function execute(string $sql): void
    {
        DB::connection()->getPdo()->exec($sql);
    }
};
