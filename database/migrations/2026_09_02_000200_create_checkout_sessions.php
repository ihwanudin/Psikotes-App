<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const array HANDOFF_SCOPE = [
        'id', 'assessment_participant_id', 'integration_client_id', 'organization_id',
        'participant_id', 'package_id', 'integration_source_id', 'source_system', 'contract_version',
    ];

    public function up(): void
    {
        $this->assertHandoffScopeCanBeReferenced();

        Schema::table('checkout_handoffs', function (Blueprint $table): void {
            $table->unique(self::HANDOFF_SCOPE, 'checkout_handoffs_checkout_session_scope_unique');
        });

        Schema::create('checkout_sessions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->char('selector_digest', 64)->unique();
            $table->char('csrf_digest', 64)->unique();
            $table->unsignedBigInteger('checkout_handoff_id')->unique();
            $table->unsignedBigInteger('assessment_participant_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('participant_id');
            $table->unsignedBigInteger('package_id');
            $table->unsignedBigInteger('integration_client_id');
            $table->unsignedBigInteger('integration_source_id');
            $table->string('source_system', 100);
            $table->string('contract_version', 24);
            $table->string('status', 16);
            $table->boolean('active_marker')->nullable();
            $table->timestampTz('established_at');
            $table->timestampTz('last_seen_at');
            $table->timestampTz('idle_expires_at');
            $table->timestampTz('absolute_expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('expired_at')->nullable();
            $table->string('revocation_reason', 32)->nullable();
            $table->timestampsTz();

            $table->foreign(
                ['checkout_handoff_id', 'assessment_participant_id', 'integration_client_id',
                    'organization_id', 'participant_id', 'package_id', 'integration_source_id',
                    'source_system', 'contract_version'],
                'checkout_sessions_handoff_scope_fk',
            )->references(self::HANDOFF_SCOPE)->on('checkout_handoffs')->cascadeOnDelete();
            $table->foreign(
                ['assessment_participant_id', 'integration_client_id', 'organization_id',
                    'participant_id', 'package_id', 'source_system'],
                'checkout_sessions_attempt_scope_fk',
            )->references(['id', 'integration_client_id', 'organization_id', 'participant_id',
                'package_id', 'source_system'])->on('assessment_participants')->cascadeOnDelete();
            $table->foreign(
                ['integration_client_id', 'organization_id'],
                'checkout_sessions_client_scope_fk',
            )->references(['id', 'organization_id'])->on('integration_clients')->restrictOnDelete();
            $table->foreign(
                ['integration_source_id', 'integration_client_id', 'source_system', 'contract_version'],
                'checkout_sessions_source_scope_fk',
            )->references(['id', 'integration_client_id', 'source_system', 'contract_version'])
                ->on('integration_sources')->restrictOnDelete();
            $table->unique(
                ['assessment_participant_id', 'active_marker'],
                'checkout_sessions_one_active_attempt_unique',
            );
            $table->index(
                ['assessment_participant_id', 'status', 'established_at', 'id'],
                'checkout_sessions_attempt_latest_idx',
            );
            $table->index(
                ['status', 'idle_expires_at', 'absolute_expires_at', 'id'],
                'checkout_sessions_expiry_cleanup_idx',
            );
        });

        if (DB::getDriverName() === 'pgsql') {
            $this->addPostgresContract();
        } elseif (DB::getDriverName() === 'sqlite') {
            $this->addSqliteContract();
        }
    }

    public function down(): void
    {
        if ($this->checkoutSessionHistoryExists()) {
            throw new RuntimeException('Checkout session history prevents rollback.');
        }

        Schema::dropIfExists('checkout_sessions');
        Schema::table('checkout_handoffs', function (Blueprint $table): void {
            $table->dropUnique('checkout_handoffs_checkout_session_scope_unique');
        });
    }

    private function assertHandoffScopeCanBeReferenced(): void
    {
        if (! Schema::hasTable('checkout_handoffs')) {
            throw new RuntimeException('Checkout handoff schema is required before checkout sessions.');
        }

        $check = function (): bool {
            $duplicate = DB::table('checkout_handoffs')->select(self::HANDOFF_SCOPE)
                ->groupBy(self::HANDOFF_SCOPE)->havingRaw('COUNT(*) > 1')->exists();
            $corrupt = DB::table('checkout_handoffs as handoff')
                ->leftJoin('assessment_participants as attempt', function ($join): void {
                    $join->on('attempt.id', '=', 'handoff.assessment_participant_id')
                        ->on('attempt.integration_client_id', '=', 'handoff.integration_client_id')
                        ->on('attempt.organization_id', '=', 'handoff.organization_id')
                        ->on('attempt.participant_id', '=', 'handoff.participant_id')
                        ->on('attempt.package_id', '=', 'handoff.package_id')
                        ->on('attempt.source_system', '=', 'handoff.source_system');
                })->leftJoin('integration_clients as client', function ($join): void {
                    $join->on('client.id', '=', 'handoff.integration_client_id')
                        ->on('client.organization_id', '=', 'handoff.organization_id');
                })->leftJoin('integration_sources as source', function ($join): void {
                    $join->on('source.id', '=', 'handoff.integration_source_id')
                        ->on('source.integration_client_id', '=', 'handoff.integration_client_id')
                        ->on('source.source_system', '=', 'handoff.source_system')
                        ->on('source.contract_version', '=', 'handoff.contract_version');
                })->where(function ($query): void {
                    $query->whereNull('attempt.id')->orWhereNull('client.id')->orWhereNull('source.id');
                })->exists();

            return $duplicate || $corrupt;
        };

        if ($this->withServiceVisibility($check)) {
            throw new RuntimeException('Checkout handoff scope prevents checkout session migration.');
        }
    }

    private function checkoutSessionHistoryExists(): bool
    {
        if (! Schema::hasTable('checkout_sessions')) {
            return false;
        }

        return $this->withServiceVisibility(fn (): bool => DB::table('checkout_sessions')->exists());
    }

    /** @param callable(): bool $callback */
    private function withServiceVisibility(callable $callback): bool
    {
        if (DB::getDriverName() !== 'pgsql') {
            return $callback();
        }

        return DB::transaction(function () use ($callback): bool {
            $previous = DB::selectOne(<<<'SQL'
                SELECT
                    current_setting('app.role', true) AS role,
                    current_setting('app.branch_id', true) AS branch_id,
                    current_setting('app.participant_id', true) AS participant_id
                SQL);
            if ($previous === null) {
                throw new RuntimeException('Checkout session migration visibility could not be established.');
            }

            try {
                DB::select(<<<'SQL'
                    SELECT
                        set_config('app.role', 'service', true),
                        set_config('app.branch_id', '', true),
                        set_config('app.participant_id', '', true)
                    SQL);

                return $callback();
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
            ALTER TABLE checkout_sessions
                ADD CONSTRAINT checkout_sessions_identity_check CHECK (
                    public_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND length(btrim(source_system)) > 0
                    AND contract_version = 'checkout-v2'
                ),
                ADD CONSTRAINT checkout_sessions_digest_check CHECK (
                    selector_digest ~ '^[0-9a-f]{64}$'
                    AND csrf_digest ~ '^[0-9a-f]{64}$'
                ),
                ADD CONSTRAINT checkout_sessions_time_check CHECK (
                    last_seen_at >= established_at
                    AND idle_expires_at > last_seen_at
                    AND idle_expires_at <= absolute_expires_at
                    AND absolute_expires_at > established_at
                ),
                ADD CONSTRAINT checkout_sessions_lifecycle_check CHECK (
                    (
                        status = 'ACTIVE' AND active_marker IS TRUE
                        AND revoked_at IS NULL AND expired_at IS NULL AND revocation_reason IS NULL
                    ) OR (
                        status = 'REVOKED' AND active_marker IS NULL
                        AND revoked_at IS NOT NULL AND revoked_at >= last_seen_at
                        AND expired_at IS NULL
                        AND revocation_reason IN ('LOGOUT','RECOVERY_REISSUED','SCOPE_REVOKED','REPLACED')
                    ) OR (
                        status = 'EXPIRED' AND active_marker IS NULL
                        AND revoked_at IS NULL AND expired_at IS NOT NULL
                        AND expired_at >= LEAST(idle_expires_at, absolute_expires_at)
                        AND revocation_reason IS NULL
                    )
                )
            SQL);
        DB::statement('ALTER TABLE checkout_sessions ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE checkout_sessions FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY checkout_sessions_service ON checkout_sessions
            FOR ALL TO psikotes_runtime
            USING (app_private.app_role() = 'service')
            WITH CHECK (app_private.app_role() = 'service')");
    }

    private function addSqliteContract(): void
    {
        $valid = <<<'SQL'
            length(NEW.public_id) = 26
            AND substr(NEW.public_id, 1, 1) GLOB '[0-7]'
            AND NEW.public_id NOT GLOB '*[^0-9A-HJKMNP-TV-Z]*'
            AND length(trim(NEW.source_system)) > 0
            AND NEW.contract_version = 'checkout-v2'
            AND length(NEW.selector_digest) = 64
            AND NEW.selector_digest NOT GLOB '*[^0-9a-f]*'
            AND length(NEW.csrf_digest) = 64
            AND NEW.csrf_digest NOT GLOB '*[^0-9a-f]*'
            AND NEW.last_seen_at >= NEW.established_at
            AND NEW.idle_expires_at > NEW.last_seen_at
            AND NEW.idle_expires_at <= NEW.absolute_expires_at
            AND NEW.absolute_expires_at > NEW.established_at
            AND (
                (
                    NEW.status = 'ACTIVE' AND NEW.active_marker = 1
                    AND NEW.revoked_at IS NULL AND NEW.expired_at IS NULL
                    AND NEW.revocation_reason IS NULL
                ) OR (
                    NEW.status = 'REVOKED' AND NEW.active_marker IS NULL
                    AND NEW.revoked_at IS NOT NULL AND NEW.revoked_at >= NEW.last_seen_at
                    AND NEW.expired_at IS NULL
                    AND NEW.revocation_reason IN ('LOGOUT','RECOVERY_REISSUED','SCOPE_REVOKED','REPLACED')
                ) OR (
                    NEW.status = 'EXPIRED' AND NEW.active_marker IS NULL
                    AND NEW.revoked_at IS NULL AND NEW.expired_at IS NOT NULL
                    AND NEW.expired_at >= MIN(NEW.idle_expires_at, NEW.absolute_expires_at)
                    AND NEW.revocation_reason IS NULL
                )
            )
            SQL;
        foreach (['INSERT' => 'insert', 'UPDATE' => 'update'] as $operation => $suffix) {
            DB::unprepared("CREATE TRIGGER checkout_sessions_contract_{$suffix}
                BEFORE {$operation} ON checkout_sessions
                WHEN COALESCE(({$valid}), 0) = 0
                BEGIN SELECT RAISE(ABORT, 'checkout session contract violation'); END");
        }
    }
};
