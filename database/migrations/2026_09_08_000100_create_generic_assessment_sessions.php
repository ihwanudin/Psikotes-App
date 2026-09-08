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
        Schema::create('test_sessions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('participant_id')->constrained()->restrictOnDelete();
            $table->string('test_type', 24);
            $table->unsignedInteger('attempt_no');
            $table->string('authorization_id', 100);
            $table->string('allocation_intent_id', 100);
            $table->unsignedInteger('duration_seconds');
            $table->string('status', 24)->default('created');
            $table->unsignedInteger('answers_revision')->default(0);
            $table->timestampTz('started_at', 6)->nullable();
            $table->timestampTz('ends_at', 6)->nullable();
            $table->timestampTz('submitted_at', 6)->nullable();
            $table->timestampTz('scored_at', 6)->nullable();
            $table->timestampTz('expired_at', 6)->nullable();
            $table->timestampTz('voided_at', 6)->nullable();
            $table->text('void_reason')->nullable();
            $table->timestampsTz(6);

            $table->unique(['participant_id', 'test_type', 'attempt_no'], 'test_sessions_attempt_unique');
            $table->unique(['participant_id', 'test_type', 'authorization_id'], 'test_sessions_authorization_unique');
            $table->unique(['participant_id', 'test_type', 'allocation_intent_id'], 'test_sessions_allocation_intent_unique');
            $table->index(['status', 'ends_at', 'id'], 'test_sessions_deadline_idx');
            $table->index(['participant_id', 'test_type', 'status', 'id'], 'test_sessions_participant_state_idx');
        });

        Schema::create('answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('session_id')->constrained('test_sessions')->restrictOnDelete();
            $table->unsignedInteger('item_no');
            $table->json('value');
            $table->unsignedInteger('revision');
            $table->timestampTz('answered_at', 6);
            $table->timestampsTz(6);

            $table->unique(['session_id', 'item_no'], 'answers_session_item_unique');
            $table->index(['session_id', 'revision'], 'answers_session_revision_idx');
        });

        Schema::create('assessment_autosave_mutations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('session_id')->constrained('test_sessions')->restrictOnDelete();
            $table->ulid('mutation_id');
            $table->unsignedInteger('revision');
            $table->char('request_hash', 64);
            $table->json('accepted_item_numbers');
            $table->timestampTz('received_at', 6);
            $table->timestampTz('created_at', 6);

            $table->unique(['session_id', 'mutation_id'], 'assessment_autosave_mutations_session_mutation_unique');
            $table->unique(['session_id', 'revision'], 'assessment_autosave_mutations_session_revision_unique');
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX test_sessions_one_active_attempt_unique
                ON test_sessions (participant_id, test_type)
                WHERE status IN ('created','in_progress')
            SQL);

        if (DB::getDriverName() === 'pgsql') {
            $this->addPostgresContract();
            $this->addPostgresSecurity();
        } elseif (DB::getDriverName() === 'sqlite') {
            $this->addSqliteContract();
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->downPostgres();

            return;
        }

        if ($this->historyExists()) {
            throw new RuntimeException('Assessment session history prevents rollback.');
        }

        Schema::dropIfExists('assessment_autosave_mutations');
        Schema::dropIfExists('answers');
        Schema::dropIfExists('test_sessions');

    }

    private function downPostgres(): void
    {
        DB::transaction(function (): void {
            DB::statement(<<<'SQL'
                LOCK TABLE test_sessions, answers, assessment_autosave_mutations
                    IN ACCESS EXCLUSIVE MODE
                SQL);
            DB::statement("SELECT set_config('app.role', 'service', true)");
            if ($this->historyExists()) {
                throw new RuntimeException('Assessment session history prevents rollback.');
            }

            Schema::dropIfExists('assessment_autosave_mutations');
            Schema::dropIfExists('answers');
            Schema::dropIfExists('test_sessions');
            DB::statement('DROP FUNCTION IF EXISTS guard_assessment_answers_ledger()');
            DB::statement('DROP FUNCTION IF EXISTS guard_assessment_autosave_mutation_parent()');
            DB::statement('DROP FUNCTION IF EXISTS guard_assessment_autosave_mutations_append_only()');
            DB::statement('DROP FUNCTION IF EXISTS guard_answers_identity_revision()');
            DB::statement('DROP FUNCTION IF EXISTS guard_test_sessions_identity_revision()');
        });
    }

    private function historyExists(): bool
    {
        foreach (['assessment_autosave_mutations', 'answers', 'test_sessions'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                return true;
            }
        }

        return false;
    }

    private function addPostgresContract(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE test_sessions
                ADD CONSTRAINT test_sessions_contract_check CHECK (
                    public_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND test_type IN ('ist','papi','rmib','kraepelin')
                    AND status IN ('created','in_progress','submitted','scored','expired','void')
                    AND attempt_no > 0
                    AND duration_seconds > 0
                    AND answers_revision >= 0
                    AND length(btrim(authorization_id)) > 0
                    AND length(btrim(allocation_intent_id)) > 0
                ),
                ADD CONSTRAINT test_sessions_lifecycle_check CHECK (
                    (status = 'created' AND started_at IS NULL AND ends_at IS NULL
                        AND submitted_at IS NULL AND scored_at IS NULL AND expired_at IS NULL
                        AND voided_at IS NULL AND void_reason IS NULL)
                    OR (status = 'in_progress' AND started_at IS NOT NULL AND ends_at > started_at
                        AND submitted_at IS NULL AND scored_at IS NULL AND expired_at IS NULL
                        AND voided_at IS NULL AND void_reason IS NULL)
                    OR (status = 'submitted' AND started_at IS NOT NULL AND ends_at > started_at
                        AND submitted_at IS NOT NULL
                        AND submitted_at BETWEEN started_at AND ends_at AND scored_at IS NULL
                        AND expired_at IS NULL AND voided_at IS NULL AND void_reason IS NULL)
                    OR (status = 'scored' AND started_at IS NOT NULL AND ends_at > started_at
                        AND submitted_at IS NOT NULL AND scored_at IS NOT NULL
                        AND submitted_at BETWEEN started_at AND ends_at AND scored_at >= submitted_at
                        AND expired_at IS NULL AND voided_at IS NULL AND void_reason IS NULL)
                    OR (status = 'expired' AND started_at IS NOT NULL AND ends_at > started_at
                        AND expired_at IS NOT NULL
                        AND submitted_at IS NULL AND scored_at IS NULL AND expired_at > ends_at
                        AND voided_at IS NULL AND void_reason IS NULL)
                    OR (status = 'void' AND scored_at IS NULL AND voided_at IS NOT NULL
                        AND void_reason IS NOT NULL AND length(btrim(void_reason)) > 0
                        AND (
                            (started_at IS NULL AND ends_at IS NULL
                                AND submitted_at IS NULL AND expired_at IS NULL)
                            OR (started_at IS NOT NULL AND ends_at > started_at AND (
                                (submitted_at IS NULL AND expired_at IS NULL)
                                OR (submitted_at IS NOT NULL
                                    AND submitted_at BETWEEN started_at AND ends_at AND expired_at IS NULL)
                                OR (submitted_at IS NULL AND expired_at IS NOT NULL AND expired_at > ends_at)
                            ))
                        ))
                )
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE answers
                ADD CONSTRAINT answers_contract_check CHECK (item_no > 0 AND revision > 0)
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE assessment_autosave_mutations
                ADD CONSTRAINT assessment_autosave_mutations_contract_check CHECK (
                    mutation_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND revision > 0
                    AND request_hash ~ '^[0-9a-f]{64}$'
                    AND jsonb_typeof(accepted_item_numbers::jsonb) = 'array'
                    AND jsonb_array_length(accepted_item_numbers::jsonb) > 0
                )
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION guard_test_sessions_identity_revision() RETURNS trigger AS $$
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
            CREATE TRIGGER test_sessions_identity_revision_guard
                BEFORE UPDATE ON test_sessions FOR EACH ROW
                EXECUTE FUNCTION guard_test_sessions_identity_revision();
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION guard_answers_identity_revision() RETURNS trigger AS $$
            DECLARE
                parent_status text;
                parent_revision integer;
                parent_ends_at timestamptz;
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    IF NEW.session_id IS DISTINCT FROM OLD.session_id
                        OR NEW.item_no IS DISTINCT FROM OLD.item_no
                        OR NEW.revision <= OLD.revision THEN
                        RAISE EXCEPTION 'answer identity or revision contract violation';
                    END IF;
                END IF;
                SELECT status, answers_revision, ends_at
                    INTO parent_status, parent_revision, parent_ends_at
                    FROM test_sessions WHERE id = NEW.session_id;
                IF parent_status IS DISTINCT FROM 'in_progress'
                    OR NEW.revision <> parent_revision + 1
                    OR NEW.answered_at > parent_ends_at THEN
                    RAISE EXCEPTION 'answer parent state, deadline, or revision contract violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER answers_identity_revision_guard
                BEFORE INSERT OR UPDATE ON answers FOR EACH ROW
                EXECUTE FUNCTION guard_answers_identity_revision();
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION guard_assessment_answers_ledger() RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM assessment_autosave_mutations mutation
                    WHERE mutation.session_id = NEW.session_id AND mutation.revision = NEW.revision
                ) OR NOT EXISTS (
                    SELECT 1 FROM test_sessions session
                    WHERE session.id = NEW.session_id AND session.answers_revision >= NEW.revision
                ) THEN
                    RAISE EXCEPTION 'answer revision requires a committed mutation receipt';
                END IF;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
            CREATE CONSTRAINT TRIGGER answers_ledger_guard
                AFTER INSERT OR UPDATE ON answers DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION guard_assessment_answers_ledger();
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION guard_assessment_autosave_mutation_parent() RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM test_sessions session
                    WHERE session.id = NEW.session_id AND session.status = 'in_progress'
                        AND NEW.revision = session.answers_revision + 1
                        AND NEW.received_at <= session.ends_at
                ) THEN
                    RAISE EXCEPTION 'autosave mutation parent state, deadline, or revision contract violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER assessment_autosave_mutations_parent_guard
                BEFORE INSERT ON assessment_autosave_mutations FOR EACH ROW
                EXECUTE FUNCTION guard_assessment_autosave_mutation_parent();
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION guard_assessment_autosave_mutations_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'assessment autosave mutations are append only';
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER assessment_autosave_mutations_append_only
                BEFORE UPDATE OR DELETE ON assessment_autosave_mutations FOR EACH ROW
                EXECUTE FUNCTION guard_assessment_autosave_mutations_append_only();
            SQL);
    }

    private function addPostgresSecurity(): void
    {
        DB::unprepared(<<<'SQL'
            REVOKE ALL PRIVILEGES ON test_sessions, answers, assessment_autosave_mutations
                FROM psikotes_runtime;
            REVOKE ALL PRIVILEGES ON SEQUENCE test_sessions_id_seq, answers_id_seq,
                assessment_autosave_mutations_id_seq FROM psikotes_runtime;
            GRANT SELECT, INSERT, UPDATE, DELETE ON test_sessions TO psikotes_runtime;
            GRANT SELECT, INSERT, UPDATE, DELETE ON answers TO psikotes_runtime;
            GRANT SELECT, INSERT ON assessment_autosave_mutations TO psikotes_runtime;
            GRANT USAGE, SELECT ON SEQUENCE test_sessions_id_seq, answers_id_seq,
                assessment_autosave_mutations_id_seq TO psikotes_runtime;

            ALTER TABLE test_sessions ENABLE ROW LEVEL SECURITY;
            ALTER TABLE test_sessions FORCE ROW LEVEL SECURITY;
            ALTER TABLE answers ENABLE ROW LEVEL SECURITY;
            ALTER TABLE answers FORCE ROW LEVEL SECURITY;
            ALTER TABLE assessment_autosave_mutations ENABLE ROW LEVEL SECURITY;
            ALTER TABLE assessment_autosave_mutations FORCE ROW LEVEL SECURITY;

            CREATE POLICY test_sessions_read ON test_sessions FOR SELECT TO psikotes_runtime USING (
                app_private.app_role() IN ('service','psychologist','super_admin')
                OR (app_private.app_role() = 'participant'
                    AND participant_id = app_private.app_participant_id())
                OR (app_private.app_role() IN ('branch_admin','staff') AND EXISTS (
                    SELECT 1 FROM participants participant
                    WHERE participant.id = test_sessions.participant_id
                        AND participant.branch_id = app_private.app_branch_id()
                ))
            );
            CREATE POLICY test_sessions_service_insert ON test_sessions FOR INSERT TO psikotes_runtime
                WITH CHECK (app_private.app_role() = 'service');
            CREATE POLICY test_sessions_service_update ON test_sessions FOR UPDATE TO psikotes_runtime
                USING (app_private.app_role() = 'service')
                WITH CHECK (app_private.app_role() = 'service');
            CREATE POLICY test_sessions_participant_update ON test_sessions FOR UPDATE TO psikotes_runtime
                USING (app_private.app_role() = 'participant'
                    AND participant_id = app_private.app_participant_id()
                    AND status IN ('created','in_progress'))
                WITH CHECK (app_private.app_role() = 'participant'
                    AND participant_id = app_private.app_participant_id()
                    AND status IN ('in_progress','submitted'));
            CREATE POLICY test_sessions_service_delete ON test_sessions FOR DELETE TO psikotes_runtime
                USING (app_private.app_role() = 'service');

            CREATE POLICY answers_read ON answers FOR SELECT TO psikotes_runtime USING (
                app_private.app_role() IN ('service','psychologist') OR EXISTS (
                    SELECT 1 FROM test_sessions session WHERE session.id = answers.session_id
                        AND app_private.app_role() = 'participant'
                        AND session.participant_id = app_private.app_participant_id()
                )
            );
            CREATE POLICY answers_service_insert ON answers FOR INSERT TO psikotes_runtime
                WITH CHECK (app_private.app_role() = 'service');
            CREATE POLICY answers_service_update ON answers FOR UPDATE TO psikotes_runtime
                USING (app_private.app_role() = 'service')
                WITH CHECK (app_private.app_role() = 'service');
            CREATE POLICY answers_service_delete ON answers FOR DELETE TO psikotes_runtime
                USING (app_private.app_role() = 'service');

            CREATE POLICY assessment_autosave_mutations_read ON assessment_autosave_mutations
                FOR SELECT TO psikotes_runtime USING (
                    app_private.app_role() IN ('service','psychologist') OR EXISTS (
                        SELECT 1 FROM test_sessions session
                        WHERE session.id = assessment_autosave_mutations.session_id
                            AND app_private.app_role() = 'participant'
                            AND session.participant_id = app_private.app_participant_id()
                    )
                );
            CREATE POLICY assessment_autosave_mutations_service_insert ON assessment_autosave_mutations
                FOR INSERT TO psikotes_runtime WITH CHECK (app_private.app_role() = 'service');
            SQL);
    }

    private function addSqliteContract(): void
    {
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

        $answer = 'NEW.item_no > 0 AND NEW.revision > 0 AND json_valid(NEW.value)';
        foreach (['INSERT' => 'insert', 'UPDATE' => 'update'] as $operation => $suffix) {
            DB::unprepared("CREATE TRIGGER answers_contract_{$suffix}
                BEFORE {$operation} ON answers WHEN COALESCE(({$answer}), 0) = 0
                BEGIN SELECT RAISE(ABORT, 'answer contract violation'); END");
        }
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER answers_identity_revision_guard
            BEFORE UPDATE ON answers
            WHEN NEW.session_id IS NOT OLD.session_id
                OR NEW.item_no IS NOT OLD.item_no
                OR NEW.revision <= OLD.revision
            BEGIN SELECT RAISE(ABORT, 'answer identity or revision contract violation'); END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER answers_parent_contract_insert
            BEFORE INSERT ON answers
            WHEN NOT EXISTS (
                SELECT 1 FROM test_sessions session
                WHERE session.id = NEW.session_id AND session.status = 'in_progress'
                    AND NEW.revision = session.answers_revision + 1
                    AND NEW.answered_at <= session.ends_at
            )
            BEGIN SELECT RAISE(ABORT, 'answer parent contract violation'); END;
            CREATE TRIGGER answers_parent_contract_update
            BEFORE UPDATE ON answers
            WHEN NOT EXISTS (
                SELECT 1 FROM test_sessions session
                WHERE session.id = NEW.session_id AND session.status = 'in_progress'
                    AND NEW.revision = session.answers_revision + 1
                    AND NEW.answered_at <= session.ends_at
            )
            BEGIN SELECT RAISE(ABORT, 'answer parent contract violation'); END
            SQL);

        $mutation = <<<'SQL'
            length(NEW.mutation_id) = 26
            AND substr(NEW.mutation_id, 1, 1) GLOB '[0-7]'
            AND NEW.mutation_id NOT GLOB '*[^0-9A-HJKMNP-TV-Z]*'
            AND NEW.revision > 0
            AND length(NEW.request_hash) = 64
            AND NEW.request_hash NOT GLOB '*[^0-9a-f]*'
            AND json_valid(NEW.accepted_item_numbers)
            AND json_type(NEW.accepted_item_numbers) = 'array'
            AND json_array_length(NEW.accepted_item_numbers) > 0
            SQL;
        DB::unprepared("CREATE TRIGGER assessment_autosave_mutations_contract_insert
            BEFORE INSERT ON assessment_autosave_mutations
            WHEN COALESCE(({$mutation}), 0) = 0
            BEGIN SELECT RAISE(ABORT, 'assessment autosave mutation contract violation'); END");
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER assessment_autosave_mutations_parent_guard
            BEFORE INSERT ON assessment_autosave_mutations
            WHEN NOT EXISTS (
                SELECT 1 FROM test_sessions session
                WHERE session.id = NEW.session_id AND session.status = 'in_progress'
                    AND NEW.revision = session.answers_revision + 1
                    AND NEW.received_at <= session.ends_at
            )
            BEGIN SELECT RAISE(ABORT, 'assessment autosave mutation parent contract violation'); END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER assessment_autosave_mutations_append_only
            BEFORE UPDATE ON assessment_autosave_mutations
            BEGIN SELECT RAISE(ABORT, 'assessment autosave mutations are append only'); END;
            CREATE TRIGGER assessment_autosave_mutations_no_delete
            BEFORE DELETE ON assessment_autosave_mutations
            BEGIN SELECT RAISE(ABORT, 'assessment autosave mutations are append only'); END
            SQL);
    }
};
