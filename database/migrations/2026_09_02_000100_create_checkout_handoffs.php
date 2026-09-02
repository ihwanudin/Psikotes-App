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
        Schema::table('assessment_participants', function (Blueprint $table): void {
            $table->unique(
                ['id', 'integration_client_id', 'organization_id', 'participant_id', 'package_id'],
                'assessment_attempt_checkout_handoff_scope_unique',
            );
        });
        Schema::table('integration_sources', function (Blueprint $table): void {
            $table->unique(
                ['id', 'integration_client_id', 'source_system', 'contract_version'],
                'integration_sources_checkout_handoff_scope_unique',
            );
        });

        Schema::create('checkout_handoffs', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->unsignedBigInteger('assessment_participant_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('participant_id');
            $table->unsignedBigInteger('package_id');
            $table->unsignedBigInteger('integration_client_id');
            $table->unsignedBigInteger('integration_source_id');
            $table->string('source_system', 100);
            $table->string('contract_version', 24);
            $table->string('purpose', 40);
            $table->string('destination', 64);
            $table->char('token_digest', 64)->unique();
            $table->boolean('active_marker')->nullable();
            $table->string('status', 16);
            $table->unsignedInteger('issue_number');
            $table->char('issue_idempotency_key_digest', 64);
            $table->char('request_hash', 64);
            $table->string('revocation_reason', 32)->nullable();
            $table->timestampTz('issued_at');
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('expired_at')->nullable();
            $table->timestampsTz();

            $table->foreign(
                ['assessment_participant_id', 'integration_client_id', 'organization_id', 'participant_id', 'package_id'],
                'checkout_handoffs_attempt_scope_fk',
            )->references(['id', 'integration_client_id', 'organization_id', 'participant_id', 'package_id'])
                ->on('assessment_participants')->cascadeOnDelete();
            $table->foreign('integration_client_id', 'checkout_handoffs_client_fk')
                ->references('id')->on('integration_clients')->restrictOnDelete();
            $table->foreign(
                ['integration_source_id', 'integration_client_id', 'source_system', 'contract_version'],
                'checkout_handoffs_source_scope_fk',
            )->references(['id', 'integration_client_id', 'source_system', 'contract_version'])
                ->on('integration_sources')->restrictOnDelete();
            $table->unique(
                ['integration_client_id', 'issue_idempotency_key_digest'],
                'checkout_handoffs_client_idempotency_unique',
            );
            $table->unique(
                ['assessment_participant_id', 'purpose', 'destination', 'issue_number'],
                'checkout_handoffs_attempt_issue_unique',
            );
            $table->unique(
                ['assessment_participant_id', 'purpose', 'destination', 'active_marker'],
                'checkout_handoffs_one_active_unique',
            );
            $table->index(
                ['organization_id', 'status', 'expires_at', 'id'],
                'checkout_handoffs_cleanup_idx',
            );
            $table->index(
                ['integration_source_id', 'status', 'expires_at'],
                'checkout_handoffs_source_status_idx',
            );
        });

        if (DB::getDriverName() === 'pgsql') {
            $this->addPostgresContract();
        }
    }

    public function down(): void
    {
        if ($this->checkoutHandoffHistoryExists()) {
            throw new RuntimeException('Checkout handoff history prevents rollback.');
        }

        Schema::dropIfExists('checkout_handoffs');
        Schema::table('integration_sources', function (Blueprint $table): void {
            $table->dropUnique('integration_sources_checkout_handoff_scope_unique');
        });
        Schema::table('assessment_participants', function (Blueprint $table): void {
            $table->dropUnique('assessment_attempt_checkout_handoff_scope_unique');
        });
    }

    private function checkoutHandoffHistoryExists(): bool
    {
        if (! Schema::hasTable('checkout_handoffs')) {
            return false;
        }

        if (DB::getDriverName() !== 'pgsql') {
            return DB::table('checkout_handoffs')->exists();
        }

        return DB::transaction(function (): bool {
            $previous = DB::selectOne(<<<'SQL'
                SELECT
                    current_setting('app.role', true) AS role,
                    current_setting('app.branch_id', true) AS branch_id,
                    current_setting('app.participant_id', true) AS participant_id
                SQL);
            if ($previous === null) {
                throw new RuntimeException('Checkout handoff rollback visibility could not be established.');
            }

            try {
                DB::select(<<<'SQL'
                    SELECT
                        set_config('app.role', 'service', true),
                        set_config('app.branch_id', '', true),
                        set_config('app.participant_id', '', true)
                    SQL);
                $visibility = DB::selectOne(<<<'SQL'
                    SELECT app_private.app_role() AS role, EXISTS (
                        SELECT 1 FROM checkout_handoffs
                    ) AS has_history
                    SQL);
                if ($visibility === null || $visibility->role !== 'service' || ! is_bool($visibility->has_history)) {
                    throw new RuntimeException('Checkout handoff rollback visibility could not be established.');
                }

                return $visibility->has_history;
            } finally {
                DB::select(<<<'SQL'
                    SELECT
                        set_config('app.role', ?, true),
                        set_config('app.branch_id', ?, true),
                        set_config('app.participant_id', ?, true)
                    SQL, [
                    is_string($previous->role) ? $previous->role : '',
                    is_string($previous->branch_id) ? $previous->branch_id : '',
                    is_string($previous->participant_id) ? $previous->participant_id : '',
                ]);
            }
        });
    }

    private function addPostgresContract(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE checkout_handoffs
                ADD CONSTRAINT checkout_handoffs_identity_check CHECK (
                    public_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND issue_number >= 1
                    AND length(btrim(source_system)) > 0
                ),
                ADD CONSTRAINT checkout_handoffs_digest_check CHECK (
                    token_digest ~ '^[0-9a-f]{64}$'
                    AND issue_idempotency_key_digest ~ '^[0-9a-f]{64}$'
                    AND request_hash ~ '^[0-9a-f]{64}$'
                ),
                ADD CONSTRAINT checkout_handoffs_fixed_binding_check CHECK (
                    contract_version = 'checkout-v2'
                    AND purpose = 'checkout-handoff'
                    AND destination = 'integrated-checkout-session'
                ),
                ADD CONSTRAINT checkout_handoffs_ttl_check CHECK (
                    expires_at > issued_at
                    AND expires_at <= issued_at + INTERVAL '600 seconds'
                ),
                ADD CONSTRAINT checkout_handoffs_lifecycle_check CHECK (
                    (
                        status = 'ISSUED' AND active_marker IS TRUE
                        AND consumed_at IS NULL AND revoked_at IS NULL AND expired_at IS NULL
                        AND revocation_reason IS NULL
                    ) OR (
                        status = 'CONSUMED' AND active_marker IS NULL
                        AND consumed_at IS NOT NULL AND consumed_at >= issued_at AND consumed_at < expires_at
                        AND revoked_at IS NULL AND expired_at IS NULL AND revocation_reason IS NULL
                    ) OR (
                        status = 'REVOKED' AND active_marker IS NULL
                        AND consumed_at IS NULL AND revoked_at IS NOT NULL AND revoked_at >= issued_at
                        AND expired_at IS NULL
                        AND revocation_reason IS NOT NULL
                        AND revocation_reason IN ('REISSUED','ATTEMPT_REVOKED','SOURCE_REVOKED','CLIENT_REVOKED')
                    ) OR (
                        status = 'EXPIRED' AND active_marker IS NULL
                        AND consumed_at IS NULL AND revoked_at IS NULL AND expired_at IS NOT NULL
                        AND expired_at >= expires_at AND revocation_reason IS NULL
                    )
                )
            SQL);
        DB::statement('ALTER TABLE checkout_handoffs ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE checkout_handoffs FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY checkout_handoffs_service ON checkout_handoffs
            FOR ALL TO psikotes_runtime
            USING (app_private.app_role() = 'service')
            WITH CHECK (app_private.app_role() = 'service')");
    }
};
