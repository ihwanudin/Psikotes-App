<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0032 §3 (2026-09-22): the audit trail the ADR says is "the part that
 * was genuinely missing" -- when the orchestrator tries to score a session
 * and the outcome is not a clean `scored` result, that outcome must be a
 * durable, queryable row, not a lost log line. One row per orchestrator
 * attempt (not one row per session -- a session can be attempted more than
 * once, e.g. a transient failure retried later; a future reader must take
 * the latest row per `session_id`, not assume uniqueness).
 *
 * `outcome`:
 * - `scored`: a `generic_instrument_results` row was written (or already
 *   existed, replay-safe). `result_public_id` points at it.
 * - `failed_to_score`: a predictable, coded rejection (e.g. PAPI's
 *   all-or-nothing completeness policy). `result_public_id` is NULL.
 * - `not_scorable`: a legitimate psychometric non-result (e.g. RMIB with
 *   two or more defective rank groups, ADR-0032 PR3) -- not a bug, no
 *   `generic_instrument_results` row exists or ever will for this attempt.
 *   Lead (2026-09-22): rows with this outcome must stay visible for
 *   psychologist review (a Filament resource is deliberately out of scope
 *   for the PR that adds this migration, same as ADR-0032 marks its own
 *   admin panel as follow-up work -- but the underlying query must be
 *   possible from day one, which is why this gets its own table rather
 *   than folding into the purgeable `audit_logs`).
 *
 * Deliberately NOT reusing `audit_logs`: that table has a mandatory
 * `expires_at` and a purge command (`PurgeExpiredAuditLogsCommand`).
 * `not_scorable`/review-needed rows must not be silently deleted on a
 * generic retention timer.
 *
 * Service-role-only RLS (SELECT/INSERT), append-only, mirroring
 * `generic_instrument_results` (2026_09_13_000100) -- without that
 * migration's byte-hash-pinned `assertExactState()`, which is extra
 * hardening that migration's own author chose for that specific table, not
 * a universal requirement (2026_09_20_000200's follow-up migration doesn't
 * have one either).
 */
return new class extends Migration
{
    private const TABLE = 'assessment_scoring_attempts';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id');
            $table->unsignedBigInteger('session_id');
            $table->ulid('session_public_id');
            $table->string('instrument_code', 24);
            $table->timestampTz('attempted_at', 6);
            $table->string('outcome', 24);
            $table->string('reason_code', 64)->nullable();
            $table->char('result_public_id', 26)->nullable();
            $table->timestampTz('created_at', 6);

            $table->unique('public_id', 'assessment_scoring_attempts_public_id_unique');
            $table->index(['session_id', 'attempted_at'], 'assessment_scoring_attempts_session_idx');
            $table->index(['outcome', 'attempted_at'], 'assessment_scoring_attempts_outcome_idx');
            $table->foreign('session_id', 'assessment_scoring_attempts_session_fk')
                ->references('id')->on('test_sessions')->restrictOnUpdate()->restrictOnDelete();
        });

        DB::getDriverName() === 'pgsql' ? $this->addPostgresContract() : $this->addSqliteContract();
    }

    public function down(): void
    {
        if ((int) DB::table(self::TABLE)->count() > 0) {
            throw new RuntimeException('Assessment scoring attempt history prevents rollback.');
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS app_private.guard_assessment_scoring_attempt() CASCADE');
        }

        Schema::dropIfExists(self::TABLE);
    }

    private function addPostgresContract(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE assessment_scoring_attempts
                ADD CONSTRAINT assessment_scoring_attempts_contract_check CHECK (
                    public_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND session_public_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND instrument_code IN ('ist','papi','rmib','kraepelin')
                    AND outcome IN ('scored','failed_to_score','not_scorable')
                    AND (reason_code IS NULL OR length(btrim(reason_code)) > 0)
                    AND (result_public_id IS NULL OR result_public_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$')
                    AND (outcome = 'scored') = (result_public_id IS NOT NULL)
                    AND (outcome = 'scored') = (reason_code IS NULL)
                );

            CREATE FUNCTION app_private.guard_assessment_scoring_attempt() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $guard$
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'assessment scoring attempt history is append-only' USING ERRCODE = 'P0001';
                END IF;
                IF NOT EXISTS (
                    SELECT 1 FROM public.test_sessions session
                    WHERE session.id = NEW.session_id
                      AND session.public_id = NEW.session_public_id
                      AND session.test_type = NEW.instrument_code
                ) THEN
                    RAISE EXCEPTION 'assessment scoring attempt requires its exact session source'
                        USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $guard$;
            CREATE TRIGGER assessment_scoring_attempts_guard
                BEFORE INSERT OR UPDATE OR DELETE ON assessment_scoring_attempts
                FOR EACH ROW EXECUTE FUNCTION app_private.guard_assessment_scoring_attempt();

            REVOKE ALL PRIVILEGES ON assessment_scoring_attempts FROM psikotes_runtime;
            REVOKE ALL PRIVILEGES ON SEQUENCE assessment_scoring_attempts_id_seq FROM psikotes_runtime;
            GRANT SELECT, INSERT ON assessment_scoring_attempts TO psikotes_runtime;
            GRANT USAGE, SELECT ON SEQUENCE assessment_scoring_attempts_id_seq TO psikotes_runtime;

            ALTER TABLE assessment_scoring_attempts ENABLE ROW LEVEL SECURITY;
            ALTER TABLE assessment_scoring_attempts FORCE ROW LEVEL SECURITY;
            CREATE POLICY assessment_scoring_attempts_service_select ON assessment_scoring_attempts
                FOR SELECT TO psikotes_runtime USING (app_private.app_role() = 'service');
            CREATE POLICY assessment_scoring_attempts_service_insert ON assessment_scoring_attempts
                FOR INSERT TO psikotes_runtime WITH CHECK (app_private.app_role() = 'service');

            REVOKE ALL ON FUNCTION app_private.guard_assessment_scoring_attempt() FROM PUBLIC;
            SQL);
    }

    private function addSqliteContract(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER assessment_scoring_attempts_guard_insert
            BEFORE INSERT ON assessment_scoring_attempts FOR EACH ROW
            WHEN NEW.outcome NOT IN ('scored','failed_to_score','not_scorable')
                OR (NEW.outcome = 'scored' AND (NEW.result_public_id IS NULL OR NEW.reason_code IS NOT NULL))
                OR (NEW.outcome <> 'scored' AND (NEW.result_public_id IS NOT NULL OR NEW.reason_code IS NULL))
                OR NOT EXISTS (
                    SELECT 1 FROM test_sessions session
                    WHERE session.id = NEW.session_id
                      AND session.public_id = NEW.session_public_id
                      AND session.test_type = NEW.instrument_code
                )
            BEGIN SELECT RAISE(ABORT, 'assessment scoring attempt requires its exact session source'); END;
            CREATE TRIGGER assessment_scoring_attempts_guard_update
            BEFORE UPDATE ON assessment_scoring_attempts FOR EACH ROW
            BEGIN SELECT RAISE(ABORT, 'assessment scoring attempt history is append-only'); END;
            CREATE TRIGGER assessment_scoring_attempts_guard_delete
            BEFORE DELETE ON assessment_scoring_attempts FOR EACH ROW
            BEGIN SELECT RAISE(ABORT, 'assessment scoring attempt history is append-only'); END;
            SQL);
    }
};
