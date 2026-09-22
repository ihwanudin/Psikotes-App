<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-0032 PR2 (2026-09-23). Psychologist P4 (owner-decisions-2026-09-21.md
 * item 21): a session that expires without an explicit submit is still
 * scored from whatever answers already made it in. Before this migration,
 * `generic_instrument_results`' own append-only guard
 * (2026_09_13_000100_create_generic_instrument_result_ledger.php) only
 * accepted a source session with `status IN ('submitted','scored')` -- the
 * SAME gate PHP enforces in `LoadSealedGenericAnswerSet::execute()` and
 * each `PersistSealed*Result::sessionMatches()`, all three widened
 * alongside this migration to also accept 'expired'.
 *
 * `submitted_at` cannot simply be compared as-is for an expired session:
 * `test_sessions_lifecycle_check` (2026_09_08_000100) makes `submitted_at
 * IS NULL` and `expired_at IS NOT NULL` MUTUALLY REQUIRED for status
 * 'expired' -- there is no session row where both are non-null. The ledger
 * column stays named `submitted_at` (so does the domain field of the same
 * name throughout this codebase) but its SOURCE becomes
 * `COALESCE(session.submitted_at, session.expired_at)`: "the moment this
 * session's answers became final," not literally "the moment the
 * participant pressed submit." Both PHP and this trigger read that same
 * COALESCE, so a replay attempt with the wrong timestamp still fails
 * closed exactly as before.
 *
 * `test_sessions.status` itself is NOT touched by this migration or by
 * anything ADR-0032 adds -- an expired session that gets scored stays
 * `status = 'expired'` forever (Lead-approved 2026-09-22: the smallest
 * blast-radius option, and consistent with the state machine, which has
 * never allowed `expired -> scored`). The row's existence in
 * `generic_instrument_results` is the "has been scored" signal, decoupled
 * from `test_sessions.status`.
 *
 * CREATE OR REPLACE layered on top of 2026_09_13_000100, same pattern as
 * 2026_09_20_000200 (raw_score/band widening) and 2026_09_22_000100
 * (engine_version): that migration's own `assertPostgresState()` is
 * updated to accept EITHER trigger body when it re-verifies itself on a
 * replay, not rewritten to assume this one has already run.
 */
return new class extends Migration
{
    private const ORIGINAL_POSTGRES_BODY = <<<'SQL'
        BEGIN
            IF TG_OP <> 'INSERT' THEN
                RAISE EXCEPTION 'generic instrument result history is append-only' USING ERRCODE = 'P0001';
            END IF;
            IF NOT EXISTS (
                SELECT 1 FROM public.test_sessions session
                WHERE session.id = NEW.session_id
                  AND session.assessment_case_id = NEW.assessment_case_id
                  AND session.participant_id = NEW.participant_id
                  AND session.public_id = NEW.session_public_id
                  AND session.test_type = NEW.instrument_code
                  AND session.attempt_no = NEW.attempt_no
                  AND session.status IN ('submitted','scored')
                  AND session.submitted_at = NEW.submitted_at
                  AND session.answers_revision = NEW.answers_revision
                  AND session.session_definition_version = NEW.session_definition_version
                  AND session.session_definition_provenance = NEW.session_definition_provenance
                  AND session.session_definition_checksum = NEW.session_definition_checksum
                  AND session.session_definition_payload::jsonb = NEW.session_definition_payload::jsonb
            ) THEN
                RAISE EXCEPTION 'generic instrument result requires its exact submitted session source'
                    USING ERRCODE = '23514';
            END IF;
            RETURN NEW;
        END;
        SQL;

    private const WIDENED_POSTGRES_BODY = <<<'SQL'
        BEGIN
            IF TG_OP <> 'INSERT' THEN
                RAISE EXCEPTION 'generic instrument result history is append-only' USING ERRCODE = 'P0001';
            END IF;
            IF NOT EXISTS (
                SELECT 1 FROM public.test_sessions session
                WHERE session.id = NEW.session_id
                  AND session.assessment_case_id = NEW.assessment_case_id
                  AND session.participant_id = NEW.participant_id
                  AND session.public_id = NEW.session_public_id
                  AND session.test_type = NEW.instrument_code
                  AND session.attempt_no = NEW.attempt_no
                  AND session.status IN ('submitted','scored','expired')
                  AND COALESCE(session.submitted_at, session.expired_at) = NEW.submitted_at
                  AND session.answers_revision = NEW.answers_revision
                  AND session.session_definition_version = NEW.session_definition_version
                  AND session.session_definition_provenance = NEW.session_definition_provenance
                  AND session.session_definition_checksum = NEW.session_definition_checksum
                  AND session.session_definition_payload::jsonb = NEW.session_definition_payload::jsonb
            ) THEN
                RAISE EXCEPTION 'generic instrument result requires its exact submitted session source'
                    USING ERRCODE = '23514';
            END IF;
            RETURN NEW;
        END;
        SQL;

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->replacePostgresGuard(self::WIDENED_POSTGRES_BODY);

            return;
        }

        $this->replaceSqliteGuard(
            "session.status IN ('submitted','scored')",
            'session.submitted_at = NEW.submitted_at',
            "session.status IN ('submitted','scored','expired')",
            'COALESCE(session.submitted_at, session.expired_at) = NEW.submitted_at',
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->replacePostgresGuard(self::ORIGINAL_POSTGRES_BODY);

            return;
        }

        $this->replaceSqliteGuard(
            "session.status IN ('submitted','scored','expired')",
            'COALESCE(session.submitted_at, session.expired_at) = NEW.submitted_at',
            "session.status IN ('submitted','scored')",
            'session.submitted_at = NEW.submitted_at',
        );
    }

    private function replacePostgresGuard(string $body): void
    {
        $sql = <<<SQL
            CREATE OR REPLACE FUNCTION app_private.guard_generic_instrument_result() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS \$guard\$
            {$body}
            \$guard\$;
            SQL;
        if (DB::connection()->getPdo()->exec($sql) === false) {
            throw new RuntimeException('Unable to replace the generic instrument result ledger guard function.');
        }
    }

    private function replaceSqliteGuard(
        string $fromStatusClause,
        string $fromTimestampClause,
        string $toStatusClause,
        string $toTimestampClause,
    ): void {
        $definition = DB::table('sqlite_master')
            ->where('type', 'trigger')
            ->where('name', 'generic_instrument_results_guard_insert')
            ->value('sql');
        if (! is_string($definition)) {
            throw new RuntimeException('generic_instrument_results_guard_insert trigger is missing.');
        }
        $replaced = str_replace($fromStatusClause, $toStatusClause, $definition, $statusCount);
        $replaced = str_replace($fromTimestampClause, $toTimestampClause, $replaced, $timestampCount);
        if ($statusCount !== 1 || $timestampCount !== 1) {
            throw new RuntimeException('generic_instrument_results_guard_insert trigger clauses could not be located exactly once.');
        }

        DB::unprepared('DROP TRIGGER generic_instrument_results_guard_insert');
        // DB::unprepared() requires a literal-string; $replaced is built at
        // runtime from the trigger definition already stored in this same
        // database (never external input) -- same reasoning and fix as
        // 2026_09_22_000100's Postgres CHECK constraint.
        if (DB::connection()->getPdo()->exec($replaced) === false) {
            throw new RuntimeException('Unable to recreate the generic instrument result ledger SQLite guard trigger.');
        }
    }
};
