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
        Schema::create('generic_assessment_result_dispatch_attempts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('outbox_id');
            $table->unsignedSmallInteger('attempt_number');
            $table->string('mode', 32);
            $table->char('lease_token_hash', 64)->unique();
            $table->timestampTz('claimed_at');
            $table->timestampTz('lease_expires_at');
            $table->string('outcome', 24);
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
            $table->string('reason_code', 64)->nullable();
            $table->timestampTz('created_at');

            $table->foreign('outbox_id')->references('id')->on('generic_assessment_result_outbox')->restrictOnDelete();
            $table->unique(['outbox_id', 'attempt_number'], 'generic_result_dispatch_attempt_unique');
            $table->index(['outbox_id', 'outcome'], 'generic_result_dispatch_state_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            $this->addPostgresGuard();
        } elseif (DB::getDriverName() === 'sqlite') {
            $this->addSqliteGuards();
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS guard_generic_assessment_result_dispatch_attempt() CASCADE');
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS generic_result_dispatch_attempt_insert_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS generic_result_dispatch_attempt_update_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS generic_result_dispatch_attempt_delete_guard');
        }

        Schema::dropIfExists('generic_assessment_result_dispatch_attempts');
    }

    private function addSqliteGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER generic_result_dispatch_attempt_insert_guard
            BEFORE INSERT ON generic_assessment_result_dispatch_attempts
            BEGIN
                SELECT CASE WHEN
                    NEW.attempt_number < 1
                    OR NEW.mode <> 'CALLBACK_AND_POLL'
                    OR length(NEW.lease_token_hash) <> 64
                    OR NEW.lease_token_hash GLOB '*[^0-9a-f]*'
                    OR NEW.outcome <> 'PROCESSING'
                    OR NEW.completed_at IS NOT NULL
                    OR NEW.next_attempt_at IS NOT NULL
                    OR NEW.reason_code IS NOT NULL
                    OR NEW.claimed_at >= NEW.lease_expires_at
                    OR (NEW.attempt_number = 1 AND EXISTS (
                        SELECT 1 FROM generic_assessment_result_dispatch_attempts a
                        WHERE a.outbox_id = NEW.outbox_id
                    ))
                    OR (NEW.attempt_number > 1 AND NOT EXISTS (
                        SELECT 1 FROM generic_assessment_result_dispatch_attempts previous
                        WHERE previous.outbox_id = NEW.outbox_id
                          AND previous.attempt_number = NEW.attempt_number - 1
                          AND (
                            (previous.outcome = 'PROCESSING' AND previous.lease_expires_at <= NEW.claimed_at)
                            OR (previous.outcome = 'RETRYABLE' AND previous.next_attempt_at IS NOT NULL AND previous.next_attempt_at <= NEW.claimed_at)
                          )
                    ))
                THEN RAISE(ABORT, 'generic result dispatch attempt invariant violation') END;
            END
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER generic_result_dispatch_attempt_update_guard
            BEFORE UPDATE ON generic_assessment_result_dispatch_attempts
            BEGIN
                SELECT CASE WHEN
                    NEW.id IS NOT OLD.id
                    OR NEW.outbox_id IS NOT OLD.outbox_id
                    OR NEW.attempt_number IS NOT OLD.attempt_number
                    OR NEW.mode IS NOT OLD.mode
                    OR NEW.lease_token_hash IS NOT OLD.lease_token_hash
                    OR NEW.claimed_at IS NOT OLD.claimed_at
                    OR NEW.lease_expires_at IS NOT OLD.lease_expires_at
                    OR NEW.created_at IS NOT OLD.created_at
                    OR OLD.outcome <> 'PROCESSING'
                    OR NEW.outcome NOT IN ('ACKNOWLEDGED', 'RETRYABLE', 'PERMANENT', 'UNKNOWN')
                    OR NEW.completed_at IS NULL
                    OR NEW.completed_at < NEW.claimed_at
                    OR NEW.completed_at >= NEW.lease_expires_at
                    OR (NEW.outcome = 'ACKNOWLEDGED' AND (NEW.reason_code IS NOT NULL OR NEW.next_attempt_at IS NOT NULL))
                    OR (NEW.outcome = 'RETRYABLE' AND (NEW.reason_code IS NULL OR NEW.reason_code NOT IN ('TRANSIENT_UNAVAILABLE', 'RATE_LIMITED')))
                    OR (NEW.outcome = 'PERMANENT' AND (NEW.reason_code IS NULL OR NEW.reason_code <> 'REMOTE_REJECTED' OR NEW.next_attempt_at IS NOT NULL))
                    OR (NEW.outcome = 'UNKNOWN' AND (NEW.reason_code IS NULL OR NEW.reason_code <> 'OUTCOME_UNCERTAIN' OR NEW.next_attempt_at IS NOT NULL))
                    OR (NEW.next_attempt_at IS NOT NULL AND NEW.next_attempt_at <= NEW.completed_at)
                THEN RAISE(ABORT, 'generic result dispatch completion invariant violation') END;
            END
        SQL);
        DB::unprepared("CREATE TRIGGER generic_result_dispatch_attempt_delete_guard BEFORE DELETE ON generic_assessment_result_dispatch_attempts BEGIN SELECT RAISE(ABORT, 'generic result dispatch attempts are append-only'); END");
    }

    private function addPostgresGuard(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_generic_assessment_result_dispatch_attempt()
            RETURNS trigger AS $$
            DECLARE previous generic_assessment_result_dispatch_attempts%ROWTYPE;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'generic result dispatch attempts are append-only';
                END IF;

                IF TG_OP = 'INSERT' THEN
                    IF NEW.attempt_number < 1
                        OR NEW.mode <> 'CALLBACK_AND_POLL'
                        OR NEW.lease_token_hash !~ '^[a-f0-9]{64}$'
                        OR NEW.outcome <> 'PROCESSING'
                        OR NEW.completed_at IS NOT NULL
                        OR NEW.next_attempt_at IS NOT NULL
                        OR NEW.reason_code IS NOT NULL
                        OR NEW.claimed_at >= NEW.lease_expires_at
                    THEN
                        RAISE EXCEPTION 'generic result dispatch attempt invariant violation';
                    END IF;

                    SELECT * INTO previous
                    FROM generic_assessment_result_dispatch_attempts a
                    WHERE a.outbox_id = NEW.outbox_id
                    ORDER BY a.attempt_number DESC
                    LIMIT 1;
                    IF NEW.attempt_number = 1 THEN
                        IF FOUND THEN RAISE EXCEPTION 'generic result dispatch initial attempt invalid'; END IF;
                    ELSIF NOT FOUND
                        OR previous.attempt_number <> NEW.attempt_number - 1
                        OR NOT (
                            (previous.outcome = 'PROCESSING' AND previous.lease_expires_at <= NEW.claimed_at)
                            OR (previous.outcome = 'RETRYABLE' AND previous.next_attempt_at IS NOT NULL AND previous.next_attempt_at <= NEW.claimed_at)
                        )
                    THEN
                        RAISE EXCEPTION 'generic result dispatch attempt sequence invalid';
                    END IF;

                    RETURN NEW;
                END IF;

                IF NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.outbox_id IS DISTINCT FROM OLD.outbox_id
                    OR NEW.attempt_number IS DISTINCT FROM OLD.attempt_number
                    OR NEW.mode IS DISTINCT FROM OLD.mode
                    OR NEW.lease_token_hash IS DISTINCT FROM OLD.lease_token_hash
                    OR NEW.claimed_at IS DISTINCT FROM OLD.claimed_at
                    OR NEW.lease_expires_at IS DISTINCT FROM OLD.lease_expires_at
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                    OR OLD.outcome <> 'PROCESSING'
                    OR NEW.outcome NOT IN ('ACKNOWLEDGED', 'RETRYABLE', 'PERMANENT', 'UNKNOWN')
                    OR NEW.completed_at IS NULL
                    OR NEW.completed_at < NEW.claimed_at
                    OR NEW.completed_at >= NEW.lease_expires_at
                    OR (NEW.outcome = 'ACKNOWLEDGED' AND (NEW.reason_code IS NOT NULL OR NEW.next_attempt_at IS NOT NULL))
                    OR (NEW.outcome = 'RETRYABLE' AND (NEW.reason_code IS NULL OR NEW.reason_code NOT IN ('TRANSIENT_UNAVAILABLE', 'RATE_LIMITED')))
                    OR (NEW.outcome = 'PERMANENT' AND (NEW.reason_code IS NULL OR NEW.reason_code <> 'REMOTE_REJECTED' OR NEW.next_attempt_at IS NOT NULL))
                    OR (NEW.outcome = 'UNKNOWN' AND (NEW.reason_code IS NULL OR NEW.reason_code <> 'OUTCOME_UNCERTAIN' OR NEW.next_attempt_at IS NOT NULL))
                    OR (NEW.next_attempt_at IS NOT NULL AND NEW.next_attempt_at <= NEW.completed_at)
                THEN
                    RAISE EXCEPTION 'generic result dispatch completion invariant violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER generic_result_dispatch_attempt_guard
            BEFORE INSERT OR UPDATE OR DELETE ON generic_assessment_result_dispatch_attempts
            FOR EACH ROW EXECUTE FUNCTION guard_generic_assessment_result_dispatch_attempt()
        SQL);
    }
};
