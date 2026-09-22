<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F2 timed-segments stage 1 (2026-09-22), per Lead-approved plan
 * tasks/handoffs/f2/timed-segments-plan.md (a27b95c). Adds the three
 * nullable session-state columns revision 2 of that plan describes:
 * current_segment_index, current_segment_became_current_at,
 * current_segment_started_at. Purely additive -- no existing column,
 * constraint, or trigger behaviour changes for rows that never touch these
 * new columns, which is every row until the later timed-segments stages
 * (domain + actions) ship and start populating them.
 *
 * Shape enforced (both Postgres CHECK and its SQLite trigger mirror):
 *   - status = 'created'            => all three NULL.
 *   - status <> 'created'           => either all three still NULL (the
 *     transitional case: a session started before the app code that
 *     populates them shipped) OR a valid triple: index >= 0,
 *     became_current_at NOT NULL and >= started_at, and started_at (the
 *     new column) NULL or >= became_current_at.
 *
 * Mutability (Postgres trigger + SQLite trigger mirror, extending
 * guard_test_sessions_identity_revision()/test_sessions_identity_revision_guard
 * from 2026_09_08_000100_create_generic_assessment_sessions.php): these
 * three columns may only change while a row is starting
 * (created -> in_progress) or staying in_progress (in_progress ->
 * in_progress, e.g. a future subtest/next transition) -- never on any other
 * transition -- and current_segment_index must never decrease. The existing
 * participant-role defensive branch is extended the same way: even though
 * every current write path uses the service role (see
 * AutosaveAssessmentAnswers/StartAssessmentSession), a direct
 * participant-role write still can't smuggle a segment-column change
 * through, matching how answers_revision is already guarded there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('test_sessions', function (Blueprint $table): void {
            $table->smallInteger('current_segment_index')->nullable();
            $table->timestampTz('current_segment_became_current_at', 6)->nullable();
            $table->timestampTz('current_segment_started_at', 6)->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            $this->addPostgresSegmentSupport();
        } elseif (DB::getDriverName() === 'sqlite') {
            $this->addSqliteSegmentSupport();
        }
    }

    public function down(): void
    {
        if ($this->segmentHistoryExists()) {
            throw new RuntimeException('Timed segment history prevents rollback.');
        }

        if (DB::getDriverName() === 'pgsql') {
            $this->downPostgres();

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->downSqlite();
        }

        Schema::table('test_sessions', function (Blueprint $table): void {
            $table->dropColumn([
                'current_segment_index',
                'current_segment_became_current_at',
                'current_segment_started_at',
            ]);
        });
    }

    private function segmentHistoryExists(): bool
    {
        return Schema::hasTable('test_sessions')
            && Schema::hasColumn('test_sessions', 'current_segment_index')
            && DB::table('test_sessions')->whereNotNull('current_segment_index')->exists();
    }

    private function addPostgresSegmentSupport(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE test_sessions
                ADD CONSTRAINT test_sessions_segment_check CHECK (
                    (status = 'created'
                        AND current_segment_index IS NULL
                        AND current_segment_became_current_at IS NULL
                        AND current_segment_started_at IS NULL)
                    OR (status <> 'created' AND (
                        (current_segment_index IS NULL
                            AND current_segment_became_current_at IS NULL
                            AND current_segment_started_at IS NULL)
                        OR (current_segment_index >= 0
                            AND current_segment_became_current_at IS NOT NULL
                            AND current_segment_became_current_at >= started_at
                            AND (current_segment_started_at IS NULL
                                OR current_segment_started_at >= current_segment_became_current_at))
                    ))
                )
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_test_sessions_identity_revision() RETURNS trigger AS $$
            BEGIN
                IF NEW.public_id IS DISTINCT FROM OLD.public_id
                    OR NEW.participant_id IS DISTINCT FROM OLD.participant_id
                    OR NEW.test_type IS DISTINCT FROM OLD.test_type
                    OR NEW.attempt_no IS DISTINCT FROM OLD.attempt_no
                    OR NEW.authorization_id IS DISTINCT FROM OLD.authorization_id
                    OR NEW.allocation_intent_id IS DISTINCT FROM OLD.allocation_intent_id
                    OR NEW.duration_seconds IS DISTINCT FROM OLD.duration_seconds THEN
                    RAISE EXCEPTION 'test session identity or revision contract violation';
                END IF;
                IF NEW.status IS DISTINCT FROM OLD.status AND NOT (
                    (OLD.status = 'created' AND NEW.status IN ('in_progress','void'))
                    OR (OLD.status = 'in_progress' AND NEW.status IN ('submitted','expired','void'))
                    OR (OLD.status = 'submitted' AND NEW.status IN ('scored','void'))
                    OR (OLD.status = 'expired' AND NEW.status = 'void')
                ) THEN
                    RAISE EXCEPTION 'illegal test session state transition';
                END IF;
                IF (NEW.current_segment_index IS DISTINCT FROM OLD.current_segment_index
                    OR NEW.current_segment_became_current_at IS DISTINCT FROM OLD.current_segment_became_current_at
                    OR NEW.current_segment_started_at IS DISTINCT FROM OLD.current_segment_started_at) THEN
                    IF NOT (
                        (OLD.status = 'created' AND NEW.status = 'in_progress')
                        OR (OLD.status = 'in_progress' AND NEW.status = 'in_progress')
                    ) THEN
                        RAISE EXCEPTION 'test session segment state is only mutable while starting or in progress';
                    END IF;
                    IF OLD.current_segment_index IS NOT NULL AND NEW.current_segment_index IS NOT NULL
                        AND NEW.current_segment_index < OLD.current_segment_index THEN
                        RAISE EXCEPTION 'test session segment index must be monotonically non-decreasing';
                    END IF;
                END IF;
                IF app_private.app_role() = 'participant' THEN
                    IF NEW.answers_revision IS DISTINCT FROM OLD.answers_revision
                        OR NEW.current_segment_index IS DISTINCT FROM OLD.current_segment_index
                        OR NEW.current_segment_became_current_at IS DISTINCT FROM OLD.current_segment_became_current_at
                        OR NEW.current_segment_started_at IS DISTINCT FROM OLD.current_segment_started_at
                        OR NOT (
                            (OLD.status = 'created' AND NEW.status = 'in_progress')
                            OR (OLD.status = 'in_progress' AND NEW.status = 'submitted')
                        ) THEN
                        RAISE EXCEPTION 'participant session mutation is not authorized';
                    END IF;
                    IF OLD.status = 'created' THEN
                        NEW.started_at := statement_timestamp();
                        NEW.ends_at := NEW.started_at + make_interval(secs => OLD.duration_seconds);
                    ELSE
                        NEW.submitted_at := statement_timestamp();
                    END IF;
                END IF;
                IF NEW.answers_revision IS DISTINCT FROM OLD.answers_revision AND (
                    NEW.answers_revision <> OLD.answers_revision + 1
                    OR NOT EXISTS (
                        SELECT 1 FROM assessment_autosave_mutations mutation
                        WHERE mutation.session_id = NEW.id
                            AND mutation.revision = NEW.answers_revision
                    )
                ) THEN
                    RAISE EXCEPTION 'test session revision requires its exact mutation receipt';
                END IF;
                IF NOT (OLD.status = 'created' AND NEW.status = 'in_progress')
                    AND (NEW.started_at IS DISTINCT FROM OLD.started_at
                        OR NEW.ends_at IS DISTINCT FROM OLD.ends_at) THEN
                    RAISE EXCEPTION 'test session timing window is immutable';
                END IF;
                IF NEW.submitted_at IS DISTINCT FROM OLD.submitted_at
                    AND NOT (OLD.status = 'in_progress' AND NEW.status = 'submitted') THEN
                    RAISE EXCEPTION 'submitted evidence is immutable';
                END IF;
                IF NEW.scored_at IS DISTINCT FROM OLD.scored_at
                    AND NOT (OLD.status = 'submitted' AND NEW.status = 'scored') THEN
                    RAISE EXCEPTION 'scored evidence is immutable';
                END IF;
                IF NEW.expired_at IS DISTINCT FROM OLD.expired_at
                    AND NOT (OLD.status = 'in_progress' AND NEW.status = 'expired') THEN
                    RAISE EXCEPTION 'expired evidence is immutable';
                END IF;
                IF (NEW.voided_at IS DISTINCT FROM OLD.voided_at
                    OR NEW.void_reason IS DISTINCT FROM OLD.void_reason)
                    AND NOT (OLD.status <> 'void' AND NEW.status = 'void') THEN
                    RAISE EXCEPTION 'void evidence is immutable';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            SQL);
    }

    private function downPostgres(): void
    {
        DB::transaction(function (): void {
            DB::statement('LOCK TABLE test_sessions IN ACCESS EXCLUSIVE MODE');
            DB::statement("SELECT set_config('app.role', 'service', true)");
            if ($this->segmentHistoryExists()) {
                throw new RuntimeException('Timed segment history prevents rollback.');
            }

            DB::statement('ALTER TABLE test_sessions DROP CONSTRAINT IF EXISTS test_sessions_segment_check');

            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION guard_test_sessions_identity_revision() RETURNS trigger AS $$
                BEGIN
                    IF NEW.public_id IS DISTINCT FROM OLD.public_id
                        OR NEW.participant_id IS DISTINCT FROM OLD.participant_id
                        OR NEW.test_type IS DISTINCT FROM OLD.test_type
                        OR NEW.attempt_no IS DISTINCT FROM OLD.attempt_no
                        OR NEW.authorization_id IS DISTINCT FROM OLD.authorization_id
                        OR NEW.allocation_intent_id IS DISTINCT FROM OLD.allocation_intent_id
                        OR NEW.duration_seconds IS DISTINCT FROM OLD.duration_seconds THEN
                        RAISE EXCEPTION 'test session identity or revision contract violation';
                    END IF;
                    IF NEW.status IS DISTINCT FROM OLD.status AND NOT (
                        (OLD.status = 'created' AND NEW.status IN ('in_progress','void'))
                        OR (OLD.status = 'in_progress' AND NEW.status IN ('submitted','expired','void'))
                        OR (OLD.status = 'submitted' AND NEW.status IN ('scored','void'))
                        OR (OLD.status = 'expired' AND NEW.status = 'void')
                    ) THEN
                        RAISE EXCEPTION 'illegal test session state transition';
                    END IF;
                    IF app_private.app_role() = 'participant' THEN
                        IF NEW.answers_revision IS DISTINCT FROM OLD.answers_revision
                            OR NOT (
                                (OLD.status = 'created' AND NEW.status = 'in_progress')
                                OR (OLD.status = 'in_progress' AND NEW.status = 'submitted')
                            ) THEN
                            RAISE EXCEPTION 'participant session mutation is not authorized';
                        END IF;
                        IF OLD.status = 'created' THEN
                            NEW.started_at := statement_timestamp();
                            NEW.ends_at := NEW.started_at + make_interval(secs => OLD.duration_seconds);
                        ELSE
                            NEW.submitted_at := statement_timestamp();
                        END IF;
                    END IF;
                    IF NEW.answers_revision IS DISTINCT FROM OLD.answers_revision AND (
                        NEW.answers_revision <> OLD.answers_revision + 1
                        OR NOT EXISTS (
                            SELECT 1 FROM assessment_autosave_mutations mutation
                            WHERE mutation.session_id = NEW.id
                                AND mutation.revision = NEW.answers_revision
                        )
                    ) THEN
                        RAISE EXCEPTION 'test session revision requires its exact mutation receipt';
                    END IF;
                    IF NOT (OLD.status = 'created' AND NEW.status = 'in_progress')
                        AND (NEW.started_at IS DISTINCT FROM OLD.started_at
                            OR NEW.ends_at IS DISTINCT FROM OLD.ends_at) THEN
                        RAISE EXCEPTION 'test session timing window is immutable';
                    END IF;
                    IF NEW.submitted_at IS DISTINCT FROM OLD.submitted_at
                        AND NOT (OLD.status = 'in_progress' AND NEW.status = 'submitted') THEN
                        RAISE EXCEPTION 'submitted evidence is immutable';
                    END IF;
                    IF NEW.scored_at IS DISTINCT FROM OLD.scored_at
                        AND NOT (OLD.status = 'submitted' AND NEW.status = 'scored') THEN
                        RAISE EXCEPTION 'scored evidence is immutable';
                    END IF;
                    IF NEW.expired_at IS DISTINCT FROM OLD.expired_at
                        AND NOT (OLD.status = 'in_progress' AND NEW.status = 'expired') THEN
                        RAISE EXCEPTION 'expired evidence is immutable';
                    END IF;
                    IF (NEW.voided_at IS DISTINCT FROM OLD.voided_at
                        OR NEW.void_reason IS DISTINCT FROM OLD.void_reason)
                        AND NOT (OLD.status <> 'void' AND NEW.status = 'void') THEN
                        RAISE EXCEPTION 'void evidence is immutable';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
                SQL);
        });
    }

    private function addSqliteSegmentSupport(): void
    {
        $segment = <<<'SQL'
            (
                (NEW.status = 'created'
                    AND NEW.current_segment_index IS NULL
                    AND NEW.current_segment_became_current_at IS NULL
                    AND NEW.current_segment_started_at IS NULL)
                OR (NEW.status <> 'created' AND (
                    (NEW.current_segment_index IS NULL
                        AND NEW.current_segment_became_current_at IS NULL
                        AND NEW.current_segment_started_at IS NULL)
                    OR (NEW.current_segment_index >= 0
                        AND NEW.current_segment_became_current_at IS NOT NULL
                        AND NEW.current_segment_became_current_at >= NEW.started_at
                        AND (NEW.current_segment_started_at IS NULL
                            OR NEW.current_segment_started_at >= NEW.current_segment_became_current_at))
                ))
            )
            SQL;

        DB::unprepared('DROP TRIGGER IF EXISTS test_sessions_contract_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS test_sessions_contract_update');

        $session = <<<SQL
            length(NEW.public_id) = 26
            AND substr(NEW.public_id, 1, 1) GLOB '[0-7]'
            AND NEW.public_id NOT GLOB '*[^0-9A-HJKMNP-TV-Z]*'
            AND NEW.test_type IN ('ist','papi','rmib','kraepelin')
            AND NEW.status IN ('created','in_progress','submitted','scored','expired','void')
            AND NEW.attempt_no > 0 AND NEW.answers_revision >= 0
            AND NEW.duration_seconds > 0
            AND length(trim(NEW.authorization_id)) > 0
            AND length(trim(NEW.allocation_intent_id)) > 0
            AND (
                (NEW.status = 'created' AND NEW.started_at IS NULL AND NEW.ends_at IS NULL
                    AND NEW.submitted_at IS NULL AND NEW.scored_at IS NULL AND NEW.expired_at IS NULL
                    AND NEW.voided_at IS NULL AND NEW.void_reason IS NULL)
                OR (NEW.status = 'in_progress' AND NEW.started_at IS NOT NULL AND NEW.ends_at > NEW.started_at
                    AND NEW.submitted_at IS NULL AND NEW.scored_at IS NULL AND NEW.expired_at IS NULL
                    AND NEW.voided_at IS NULL AND NEW.void_reason IS NULL)
                OR (NEW.status = 'submitted' AND NEW.started_at IS NOT NULL AND NEW.ends_at > NEW.started_at
                    AND NEW.submitted_at BETWEEN NEW.started_at AND NEW.ends_at AND NEW.scored_at IS NULL
                    AND NEW.expired_at IS NULL AND NEW.voided_at IS NULL AND NEW.void_reason IS NULL)
                OR (NEW.status = 'scored' AND NEW.started_at IS NOT NULL AND NEW.ends_at > NEW.started_at
                    AND NEW.submitted_at BETWEEN NEW.started_at AND NEW.ends_at
                    AND NEW.scored_at >= NEW.submitted_at AND NEW.expired_at IS NULL
                    AND NEW.voided_at IS NULL AND NEW.void_reason IS NULL)
                OR (NEW.status = 'expired' AND NEW.started_at IS NOT NULL AND NEW.ends_at > NEW.started_at
                    AND NEW.submitted_at IS NULL AND NEW.scored_at IS NULL AND NEW.expired_at > NEW.ends_at
                    AND NEW.voided_at IS NULL AND NEW.void_reason IS NULL)
                OR (NEW.status = 'void' AND NEW.scored_at IS NULL AND NEW.voided_at IS NOT NULL
                    AND length(trim(NEW.void_reason)) > 0
                    AND (
                        (NEW.started_at IS NULL AND NEW.ends_at IS NULL
                            AND NEW.submitted_at IS NULL AND NEW.expired_at IS NULL)
                        OR (NEW.started_at IS NOT NULL AND NEW.ends_at > NEW.started_at AND (
                            (NEW.submitted_at IS NULL AND NEW.expired_at IS NULL)
                            OR (NEW.submitted_at BETWEEN NEW.started_at AND NEW.ends_at
                                AND NEW.expired_at IS NULL)
                            OR (NEW.submitted_at IS NULL AND NEW.expired_at > NEW.ends_at)
                        ))
                    ))
            )
            AND {$segment}
            SQL;

        foreach (['INSERT' => 'insert', 'UPDATE' => 'update'] as $operation => $suffix) {
            DB::unprepared("CREATE TRIGGER test_sessions_contract_{$suffix}
                BEFORE {$operation} ON test_sessions
                WHEN COALESCE(({$session}), 0) = 0
                BEGIN SELECT RAISE(ABORT, 'test session contract violation'); END");
        }

        DB::unprepared('DROP TRIGGER IF EXISTS test_sessions_identity_revision_guard');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER test_sessions_identity_revision_guard
            BEFORE UPDATE ON test_sessions
            WHEN NEW.public_id IS NOT OLD.public_id
                OR NEW.participant_id IS NOT OLD.participant_id
                OR NEW.test_type IS NOT OLD.test_type
                OR NEW.attempt_no IS NOT OLD.attempt_no
                OR NEW.authorization_id IS NOT OLD.authorization_id
                OR NEW.allocation_intent_id IS NOT OLD.allocation_intent_id
                OR NEW.duration_seconds IS NOT OLD.duration_seconds
                OR (NEW.answers_revision IS NOT OLD.answers_revision AND (
                    NEW.answers_revision <> OLD.answers_revision + 1
                    OR NOT EXISTS (
                        SELECT 1 FROM assessment_autosave_mutations mutation
                        WHERE mutation.session_id = NEW.id
                            AND mutation.revision = NEW.answers_revision
                    )
                ))
                OR (NEW.status IS NOT OLD.status AND NOT (
                    (OLD.status = 'created' AND NEW.status IN ('in_progress','void'))
                    OR (OLD.status = 'in_progress' AND NEW.status IN ('submitted','expired','void'))
                    OR (OLD.status = 'submitted' AND NEW.status IN ('scored','void'))
                    OR (OLD.status = 'expired' AND NEW.status = 'void')
                ))
                OR (NOT (OLD.status = 'created' AND NEW.status = 'in_progress') AND (
                    NEW.started_at IS NOT OLD.started_at OR NEW.ends_at IS NOT OLD.ends_at
                ))
                OR (NEW.submitted_at IS NOT OLD.submitted_at
                    AND NOT (OLD.status = 'in_progress' AND NEW.status = 'submitted'))
                OR (NEW.scored_at IS NOT OLD.scored_at
                    AND NOT (OLD.status = 'submitted' AND NEW.status = 'scored'))
                OR (NEW.expired_at IS NOT OLD.expired_at
                    AND NOT (OLD.status = 'in_progress' AND NEW.status = 'expired'))
                OR ((NEW.voided_at IS NOT OLD.voided_at OR NEW.void_reason IS NOT OLD.void_reason)
                    AND NOT (OLD.status <> 'void' AND NEW.status = 'void'))
                OR ((NEW.current_segment_index IS NOT OLD.current_segment_index
                        OR NEW.current_segment_became_current_at IS NOT OLD.current_segment_became_current_at
                        OR NEW.current_segment_started_at IS NOT OLD.current_segment_started_at)
                    AND NOT (
                        (OLD.status = 'created' AND NEW.status = 'in_progress')
                        OR (OLD.status = 'in_progress' AND NEW.status = 'in_progress')
                    ))
                OR (OLD.current_segment_index IS NOT NULL AND NEW.current_segment_index IS NOT NULL
                    AND NEW.current_segment_index < OLD.current_segment_index)
            BEGIN SELECT RAISE(ABORT, 'test session identity or revision contract violation'); END
            SQL);
    }

    private function downSqlite(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS test_sessions_contract_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS test_sessions_contract_update');
        DB::unprepared('DROP TRIGGER IF EXISTS test_sessions_identity_revision_guard');

        $session = <<<'SQL'
            length(NEW.public_id) = 26
            AND substr(NEW.public_id, 1, 1) GLOB '[0-7]'
            AND NEW.public_id NOT GLOB '*[^0-9A-HJKMNP-TV-Z]*'
            AND NEW.test_type IN ('ist','papi','rmib','kraepelin')
            AND NEW.status IN ('created','in_progress','submitted','scored','expired','void')
            AND NEW.attempt_no > 0 AND NEW.answers_revision >= 0
            AND NEW.duration_seconds > 0
            AND length(trim(NEW.authorization_id)) > 0
            AND length(trim(NEW.allocation_intent_id)) > 0
            AND (
                (NEW.status = 'created' AND NEW.started_at IS NULL AND NEW.ends_at IS NULL
                    AND NEW.submitted_at IS NULL AND NEW.scored_at IS NULL AND NEW.expired_at IS NULL
                    AND NEW.voided_at IS NULL AND NEW.void_reason IS NULL)
                OR (NEW.status = 'in_progress' AND NEW.started_at IS NOT NULL AND NEW.ends_at > NEW.started_at
                    AND NEW.submitted_at IS NULL AND NEW.scored_at IS NULL AND NEW.expired_at IS NULL
                    AND NEW.voided_at IS NULL AND NEW.void_reason IS NULL)
                OR (NEW.status = 'submitted' AND NEW.started_at IS NOT NULL AND NEW.ends_at > NEW.started_at
                    AND NEW.submitted_at BETWEEN NEW.started_at AND NEW.ends_at AND NEW.scored_at IS NULL
                    AND NEW.expired_at IS NULL AND NEW.voided_at IS NULL AND NEW.void_reason IS NULL)
                OR (NEW.status = 'scored' AND NEW.started_at IS NOT NULL AND NEW.ends_at > NEW.started_at
                    AND NEW.submitted_at BETWEEN NEW.started_at AND NEW.ends_at
                    AND NEW.scored_at >= NEW.submitted_at AND NEW.expired_at IS NULL
                    AND NEW.voided_at IS NULL AND NEW.void_reason IS NULL)
                OR (NEW.status = 'expired' AND NEW.started_at IS NOT NULL AND NEW.ends_at > NEW.started_at
                    AND NEW.submitted_at IS NULL AND NEW.scored_at IS NULL AND NEW.expired_at > NEW.ends_at
                    AND NEW.voided_at IS NULL AND NEW.void_reason IS NULL)
                OR (NEW.status = 'void' AND NEW.scored_at IS NULL AND NEW.voided_at IS NOT NULL
                    AND length(trim(NEW.void_reason)) > 0
                    AND (
                        (NEW.started_at IS NULL AND NEW.ends_at IS NULL
                            AND NEW.submitted_at IS NULL AND NEW.expired_at IS NULL)
                        OR (NEW.started_at IS NOT NULL AND NEW.ends_at > NEW.started_at AND (
                            (NEW.submitted_at IS NULL AND NEW.expired_at IS NULL)
                            OR (NEW.submitted_at BETWEEN NEW.started_at AND NEW.ends_at
                                AND NEW.expired_at IS NULL)
                            OR (NEW.submitted_at IS NULL AND NEW.expired_at > NEW.ends_at)
                        ))
                    ))
            )
            SQL;
        foreach (['INSERT' => 'insert', 'UPDATE' => 'update'] as $operation => $suffix) {
            DB::unprepared("CREATE TRIGGER test_sessions_contract_{$suffix}
                BEFORE {$operation} ON test_sessions
                WHEN COALESCE(({$session}), 0) = 0
                BEGIN SELECT RAISE(ABORT, 'test session contract violation'); END");
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER test_sessions_identity_revision_guard
            BEFORE UPDATE ON test_sessions
            WHEN NEW.public_id IS NOT OLD.public_id
                OR NEW.participant_id IS NOT OLD.participant_id
                OR NEW.test_type IS NOT OLD.test_type
                OR NEW.attempt_no IS NOT OLD.attempt_no
                OR NEW.authorization_id IS NOT OLD.authorization_id
                OR NEW.allocation_intent_id IS NOT OLD.allocation_intent_id
                OR NEW.duration_seconds IS NOT OLD.duration_seconds
                OR (NEW.answers_revision IS NOT OLD.answers_revision AND (
                    NEW.answers_revision <> OLD.answers_revision + 1
                    OR NOT EXISTS (
                        SELECT 1 FROM assessment_autosave_mutations mutation
                        WHERE mutation.session_id = NEW.id
                            AND mutation.revision = NEW.answers_revision
                    )
                ))
                OR (NEW.status IS NOT OLD.status AND NOT (
                    (OLD.status = 'created' AND NEW.status IN ('in_progress','void'))
                    OR (OLD.status = 'in_progress' AND NEW.status IN ('submitted','expired','void'))
                    OR (OLD.status = 'submitted' AND NEW.status IN ('scored','void'))
                    OR (OLD.status = 'expired' AND NEW.status = 'void')
                ))
                OR (NOT (OLD.status = 'created' AND NEW.status = 'in_progress') AND (
                    NEW.started_at IS NOT OLD.started_at OR NEW.ends_at IS NOT OLD.ends_at
                ))
                OR (NEW.submitted_at IS NOT OLD.submitted_at
                    AND NOT (OLD.status = 'in_progress' AND NEW.status = 'submitted'))
                OR (NEW.scored_at IS NOT OLD.scored_at
                    AND NOT (OLD.status = 'submitted' AND NEW.status = 'scored'))
                OR (NEW.expired_at IS NOT OLD.expired_at
                    AND NOT (OLD.status = 'in_progress' AND NEW.status = 'expired'))
                OR ((NEW.voided_at IS NOT OLD.voided_at OR NEW.void_reason IS NOT OLD.void_reason)
                    AND NOT (OLD.status <> 'void' AND NEW.status = 'void'))
            BEGIN SELECT RAISE(ABORT, 'test session identity or revision contract violation'); END
            SQL);
    }
};
