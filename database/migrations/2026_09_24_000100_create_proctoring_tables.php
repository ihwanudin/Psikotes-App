<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F7 (2026-09-24): proctoring persistence backend. Closes the gap where
 * `useProctoringCamera`/`photo-capture-scheduler.ts` already detect and
 * schedule real signals (camera denied/unavailable/interrupted,
 * reactivation, periodic photo capture) but have nowhere to send them --
 * `resources/js/components/participant/session-runner/proctoring-reporter.ts`
 * is still `noopProctoringReporter`. Per the owner's decision (butir 11,
 * `tasks/handoffs/decisions/owner-decisions-2026-09-21.md`), camera is now
 * mandatory for every participant and this backend is a go-live blocker.
 *
 * Four tables, matching `app/Domain/Proctoring/*` exactly so a future
 * caller can reconstruct `ProctoringEvent`/`ProctoringAdjudicatedFinding`
 * value objects from these rows and feed
 * `ProctoringValidityPolicy::decide(array $events, array $findings = [])`
 * without any shape translation:
 * - `proctor_session_monitors`: mutable cadence state (one row per
 *   session) -- the only mutable table here, everything else is
 *   append-only by GRANT (no UPDATE/DELETE privilege at all, not just RLS)
 *   plus a trigger, same double-enforcement `assessment_autosave_mutations`
 *   already uses.
 * - `proctor_photos`: periodic/start/submit capture metadata. Object
 *   storage key only, never raw bytes in this table.
 * - `proctor_logs`: raw `ProctoringEvent` rows (evidence_id is the
 *   domain identity from `ProctoringEvent::$evidenceId`; client_event_id
 *   is a separate HTTP-transport idempotency key, mirroring
 *   `assessment_autosave_mutations.mutation_id`).
 * - `proctor_adjudications`: `ProctoringAdjudicatedFinding` rows. Kept
 *   separate from `proctor_logs` rather than mutable review columns on it,
 *   because `ProctoringValidityPolicy::decide()` already takes events and
 *   findings as two distinct collections -- this isn't a new design,
 *   it's what the existing domain signature requires.
 *
 * Retention: `RetentionDataClass::ProctorMedia` already resolves to 90
 * days (butir 5a/14, both photo and video treated the same) --
 * `retention_expires_at` on `proctor_photos` is computed at write time via
 * `RetentionPolicy::expiresAt()`, not a fresh constant here.
 *
 * No participant RLS policy on any of the four tables: no product
 * requirement exists for participant-facing read access to their own
 * proctoring evidence.
 *
 * Read-access asymmetry between `proctor_session_monitors` (cadence
 * metadata) and the other three tables (evidentiary content) mirrors an
 * existing, deliberate split already in `2026_09_08_000100_...`:
 * `test_sessions_read` grants super_admin and branch-scoped
 * branch_admin/staff read access, but `answers_read` and
 * `assessment_autosave_mutations_read` grant only `service`/`psychologist`
 * -- operational metadata is one thing, actual evidentiary/psychometric
 * content is another, and only the psychologist (plus service) gets the
 * latter through RLS. `proctor_photos`/`proctor_logs`/
 * `proctor_adjudications` are content, same as `answers`, so they follow
 * that narrower policy; `proctor_session_monitors` is metadata, same as
 * `test_sessions`, so it follows the wider one (plus `central_admin`,
 * which postdates that original migration but is included here since this
 * table is new). Any broader admin-panel visibility into evidence
 * (existence-only, for `super_admin`/`central_admin`) is an
 * application-layer concern for a future Filament page to add via
 * `RlsContextRunner::runAsService()` + its own ability gate -- the same
 * pattern `ScoringFailuresReview` already uses for
 * `assessment_scoring_attempts`/`generic_instrument_results`, which also
 * have no admin-role RLS policy at all -- not by widening RLS here.
 */
return new class extends Migration
{
    private const APPEND_ONLY_TABLES = ['proctor_photos', 'proctor_logs', 'proctor_adjudications'];

    private const ALL_TABLES = ['proctor_session_monitors', 'proctor_photos', 'proctor_logs', 'proctor_adjudications'];

    public function up(): void
    {
        Schema::create('proctor_session_monitors', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('test_session_id')->unique()->constrained('test_sessions')->restrictOnDelete();
            $table->string('instrument', 16);
            $table->unsignedInteger('expected_capture_min_seconds');
            $table->unsignedInteger('expected_capture_max_seconds');
            $table->timestampTz('started_at', 6);
            $table->timestampTz('last_photo_received_at', 6)->nullable();
            $table->timestampTz('next_capture_due_at', 6)->nullable();
            $table->timestampTz('closed_at', 6)->nullable();
            $table->string('close_reason', 32)->nullable();
            $table->timestampsTz(6);
        });

        Schema::create('proctor_photos', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('test_session_id')->constrained('test_sessions')->restrictOnDelete();
            $table->string('instrument', 16);
            $table->string('capture_kind', 24);
            $table->unsignedInteger('sequence');
            $table->timestampTz('captured_at', 6)->nullable();
            $table->timestampTz('received_at', 6);
            $table->string('disk', 32);
            $table->string('object_key', 255);
            $table->string('mime_type', 64);
            $table->unsignedInteger('size_bytes');
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->char('checksum_sha256', 64);
            $table->string('face_match_status', 16)->default('not_run');
            $table->decimal('face_match_score', 5, 4)->nullable();
            $table->string('provider_reference', 100)->nullable();
            $table->ulid('client_event_id')->nullable();
            $table->timestampTz('retention_expires_at', 6);
            $table->timestampTz('created_at', 6);

            $table->unique(['test_session_id', 'sequence'], 'proctor_photos_session_sequence_unique');
            $table->unique(['test_session_id', 'client_event_id'], 'proctor_photos_session_client_event_unique');
            $table->index(['retention_expires_at'], 'proctor_photos_retention_idx');
        });

        Schema::create('proctor_logs', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('test_session_id')->constrained('test_sessions')->restrictOnDelete();
            $table->string('instrument', 16);
            $table->string('event_kind', 48);
            $table->string('evidence_source', 24);
            $table->string('evidence_id', 100);
            $table->ulid('client_event_id')->nullable();
            $table->timestampTz('occurred_at', 6);
            $table->timestampTz('received_at', 6);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('photo_id')->nullable()->constrained('proctor_photos')->restrictOnDelete();
            $table->timestampTz('created_at', 6);

            $table->unique(['test_session_id', 'evidence_id'], 'proctor_logs_session_evidence_unique');
            $table->unique(['test_session_id', 'client_event_id'], 'proctor_logs_session_client_event_unique');
            $table->index(['test_session_id', 'event_kind'], 'proctor_logs_session_kind_idx');
        });

        Schema::create('proctor_adjudications', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('test_session_id')->constrained('test_sessions')->restrictOnDelete();
            $table->string('source_evidence_id', 100);
            $table->string('finding_kind', 32);
            $table->foreignId('adjudicator_admin_id')->constrained('admins')->restrictOnDelete();
            $table->string('adjudication_token', 255);
            $table->text('note')->nullable();
            $table->timestampTz('adjudicated_at', 6);
            $table->timestampTz('created_at', 6);

            $table->unique(['test_session_id', 'source_evidence_id'], 'proctor_adjudications_session_evidence_unique');
            $table->foreign(['test_session_id', 'source_evidence_id'])
                ->references(['test_session_id', 'evidence_id'])->on('proctor_logs')
                ->restrictOnDelete();
        });

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
            throw new RuntimeException('Proctoring history prevents rollback.');
        }

        Schema::dropIfExists('proctor_adjudications');
        Schema::dropIfExists('proctor_logs');
        Schema::dropIfExists('proctor_photos');
        Schema::dropIfExists('proctor_session_monitors');
    }

    private function downPostgres(): void
    {
        DB::transaction(function (): void {
            DB::statement('LOCK TABLE '.implode(', ', self::ALL_TABLES).' IN ACCESS EXCLUSIVE MODE');
            DB::statement("SELECT set_config('app.role', 'service', true)");
            if ($this->historyExists()) {
                throw new RuntimeException('Proctoring history prevents rollback.');
            }

            Schema::dropIfExists('proctor_adjudications');
            Schema::dropIfExists('proctor_logs');
            Schema::dropIfExists('proctor_photos');
            Schema::dropIfExists('proctor_session_monitors');
            DB::statement('DROP FUNCTION IF EXISTS guard_proctor_photos_append_only()');
            DB::statement('DROP FUNCTION IF EXISTS guard_proctor_logs_append_only()');
            DB::statement('DROP FUNCTION IF EXISTS guard_proctor_adjudications_append_only()');
        });
    }

    private function historyExists(): bool
    {
        foreach (self::ALL_TABLES as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                return true;
            }
        }

        return false;
    }

    private function addPostgresContract(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE proctor_session_monitors
                ADD CONSTRAINT proctor_session_monitors_contract_check CHECK (
                    public_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND instrument IN ('ist','papi','rmib','kraepelin')
                    AND expected_capture_min_seconds > 0
                    AND expected_capture_max_seconds >= expected_capture_min_seconds
                    AND (close_reason IS NULL OR close_reason IN ('orderly_close','stream_died_without_event'))
                    AND (closed_at IS NULL) = (close_reason IS NULL)
                )
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE proctor_photos
                ADD CONSTRAINT proctor_photos_contract_check CHECK (
                    public_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND instrument IN ('ist','papi','rmib','kraepelin')
                    AND capture_kind IN ('session_start','periodic','session_submit','manual_review_upload')
                    AND sequence > 0
                    AND size_bytes > 0
                    AND width > 0 AND height > 0
                    AND checksum_sha256 ~ '^[0-9a-f]{64}$'
                    AND face_match_status IN ('not_run','match','mismatch','inconclusive')
                    AND (client_event_id IS NULL OR client_event_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$')
                )
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE proctor_logs
                ADD CONSTRAINT proctor_logs_contract_check CHECK (
                    public_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND instrument IN ('ist','papi','rmib','kraepelin')
                    AND event_kind IN (
                        'CAMERA_PERMISSION_DENIED','CAMERA_UNAVAILABLE','CAMERA_INTERRUPTED',
                        'SCREEN_DEPARTURE','FACE_MISMATCH','SECOND_FACE_DETECTED',
                        'AUDIO_ASSISTANCE_DETECTED','NETWORK_INTERRUPTED','UNREASONABLE_TIMING',
                        'IDENTITY_FAILURE','SUBTEST_INCOMPLETE','INVALID_RESPONSE_PATTERN_CONFIRMED'
                    )
                    AND evidence_source IN ('CLIENT_OBSERVATION','SERVER_FINDING')
                    AND evidence_id ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,99}$'
                    AND (client_event_id IS NULL OR client_event_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$')
                )
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE proctor_adjudications
                ADD CONSTRAINT proctor_adjudications_contract_check CHECK (
                    public_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                    AND source_evidence_id ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{0,99}$'
                    AND finding_kind IN ('SIGNAL_DISMISSED','SUBSTITUTION_CONFIRMED','ASSISTANCE_CONFIRMED')
                    AND adjudication_token ~ '^[!-~]{1,255}$'
                )
            SQL);

        foreach (self::APPEND_ONLY_TABLES as $table) {
            DB::unprepared(<<<SQL
                CREATE FUNCTION guard_{$table}_append_only() RETURNS trigger AS \$\$
                BEGIN
                    RAISE EXCEPTION '{$table} rows are append only';
                END;
                \$\$ LANGUAGE plpgsql;
                CREATE TRIGGER {$table}_append_only
                    BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW
                    EXECUTE FUNCTION guard_{$table}_append_only();
                SQL);
        }
    }

    private function addPostgresSecurity(): void
    {
        DB::unprepared(<<<'SQL'
            REVOKE ALL PRIVILEGES ON proctor_session_monitors, proctor_photos, proctor_logs,
                proctor_adjudications FROM psikotes_runtime;
            REVOKE ALL PRIVILEGES ON SEQUENCE proctor_session_monitors_id_seq, proctor_photos_id_seq,
                proctor_logs_id_seq, proctor_adjudications_id_seq FROM psikotes_runtime;

            GRANT SELECT, INSERT, UPDATE ON proctor_session_monitors TO psikotes_runtime;
            GRANT SELECT, INSERT ON proctor_photos TO psikotes_runtime;
            GRANT SELECT, INSERT ON proctor_logs TO psikotes_runtime;
            GRANT SELECT, INSERT ON proctor_adjudications TO psikotes_runtime;
            GRANT USAGE, SELECT ON SEQUENCE proctor_session_monitors_id_seq, proctor_photos_id_seq,
                proctor_logs_id_seq, proctor_adjudications_id_seq TO psikotes_runtime;

            ALTER TABLE proctor_session_monitors ENABLE ROW LEVEL SECURITY;
            ALTER TABLE proctor_session_monitors FORCE ROW LEVEL SECURITY;
            ALTER TABLE proctor_photos ENABLE ROW LEVEL SECURITY;
            ALTER TABLE proctor_photos FORCE ROW LEVEL SECURITY;
            ALTER TABLE proctor_logs ENABLE ROW LEVEL SECURITY;
            ALTER TABLE proctor_logs FORCE ROW LEVEL SECURITY;
            ALTER TABLE proctor_adjudications ENABLE ROW LEVEL SECURITY;
            ALTER TABLE proctor_adjudications FORCE ROW LEVEL SECURITY;

            CREATE POLICY proctor_session_monitors_read ON proctor_session_monitors
                FOR SELECT TO psikotes_runtime USING (
                    app_private.app_role() IN ('service','psychologist','super_admin','central_admin')
                    OR (app_private.app_role() IN ('branch_admin','staff') AND EXISTS (
                        SELECT 1 FROM test_sessions session
                        JOIN participants participant ON participant.id = session.participant_id
                        WHERE session.id = proctor_session_monitors.test_session_id
                            AND participant.branch_id = app_private.app_branch_id()
                    ))
                );
            CREATE POLICY proctor_session_monitors_service_insert ON proctor_session_monitors
                FOR INSERT TO psikotes_runtime WITH CHECK (app_private.app_role() = 'service');
            CREATE POLICY proctor_session_monitors_service_update ON proctor_session_monitors
                FOR UPDATE TO psikotes_runtime
                USING (app_private.app_role() = 'service')
                WITH CHECK (app_private.app_role() = 'service');

            CREATE POLICY proctor_photos_read ON proctor_photos
                FOR SELECT TO psikotes_runtime USING (
                    app_private.app_role() IN ('service','psychologist')
                );
            CREATE POLICY proctor_photos_service_insert ON proctor_photos
                FOR INSERT TO psikotes_runtime WITH CHECK (app_private.app_role() = 'service');

            CREATE POLICY proctor_logs_read ON proctor_logs
                FOR SELECT TO psikotes_runtime USING (
                    app_private.app_role() IN ('service','psychologist')
                );
            CREATE POLICY proctor_logs_service_insert ON proctor_logs
                FOR INSERT TO psikotes_runtime WITH CHECK (app_private.app_role() = 'service');

            CREATE POLICY proctor_adjudications_read ON proctor_adjudications
                FOR SELECT TO psikotes_runtime USING (
                    app_private.app_role() IN ('service','psychologist')
                );
            CREATE POLICY proctor_adjudications_service_insert ON proctor_adjudications
                FOR INSERT TO psikotes_runtime WITH CHECK (app_private.app_role() = 'service');
            SQL);
    }

    private function addSqliteContract(): void
    {
        $monitor = <<<'SQL'
            length(NEW.public_id) = 26
            AND NEW.instrument IN ('ist','papi','rmib','kraepelin')
            AND NEW.expected_capture_min_seconds > 0
            AND NEW.expected_capture_max_seconds >= NEW.expected_capture_min_seconds
            AND (NEW.close_reason IS NULL OR NEW.close_reason IN ('orderly_close','stream_died_without_event'))
            AND (NEW.closed_at IS NULL) = (NEW.close_reason IS NULL)
            SQL;
        foreach (['INSERT' => 'insert', 'UPDATE' => 'update'] as $operation => $suffix) {
            DB::unprepared("CREATE TRIGGER proctor_session_monitors_contract_{$suffix}
                BEFORE {$operation} ON proctor_session_monitors
                WHEN COALESCE(({$monitor}), 0) = 0
                BEGIN SELECT RAISE(ABORT, 'proctor session monitor contract violation'); END");
        }

        $photo = <<<'SQL'
            length(NEW.public_id) = 26
            AND NEW.instrument IN ('ist','papi','rmib','kraepelin')
            AND NEW.capture_kind IN ('session_start','periodic','session_submit','manual_review_upload')
            AND NEW.sequence > 0 AND NEW.size_bytes > 0 AND NEW.width > 0 AND NEW.height > 0
            AND length(NEW.checksum_sha256) = 64
            AND NEW.face_match_status IN ('not_run','match','mismatch','inconclusive')
            SQL;
        DB::unprepared("CREATE TRIGGER proctor_photos_contract_insert
            BEFORE INSERT ON proctor_photos WHEN COALESCE(({$photo}), 0) = 0
            BEGIN SELECT RAISE(ABORT, 'proctor photo contract violation'); END");

        $log = <<<'SQL'
            length(NEW.public_id) = 26
            AND NEW.instrument IN ('ist','papi','rmib','kraepelin')
            AND NEW.event_kind IN (
                'CAMERA_PERMISSION_DENIED','CAMERA_UNAVAILABLE','CAMERA_INTERRUPTED',
                'SCREEN_DEPARTURE','FACE_MISMATCH','SECOND_FACE_DETECTED',
                'AUDIO_ASSISTANCE_DETECTED','NETWORK_INTERRUPTED','UNREASONABLE_TIMING',
                'IDENTITY_FAILURE','SUBTEST_INCOMPLETE','INVALID_RESPONSE_PATTERN_CONFIRMED'
            )
            AND NEW.evidence_source IN ('CLIENT_OBSERVATION','SERVER_FINDING')
            SQL;
        DB::unprepared("CREATE TRIGGER proctor_logs_contract_insert
            BEFORE INSERT ON proctor_logs WHEN COALESCE(({$log}), 0) = 0
            BEGIN SELECT RAISE(ABORT, 'proctor log contract violation'); END");

        $adjudication = <<<'SQL'
            length(NEW.public_id) = 26
            AND NEW.finding_kind IN ('SIGNAL_DISMISSED','SUBSTITUTION_CONFIRMED','ASSISTANCE_CONFIRMED')
            SQL;
        DB::unprepared("CREATE TRIGGER proctor_adjudications_contract_insert
            BEFORE INSERT ON proctor_adjudications WHEN COALESCE(({$adjudication}), 0) = 0
            BEGIN SELECT RAISE(ABORT, 'proctor adjudication contract violation'); END");

        foreach (self::APPEND_ONLY_TABLES as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_append_only_update
                BEFORE UPDATE ON {$table}
                BEGIN SELECT RAISE(ABORT, '{$table} rows are append only'); END;
                CREATE TRIGGER {$table}_append_only_delete
                BEFORE DELETE ON {$table}
                BEGIN SELECT RAISE(ABORT, '{$table} rows are append only'); END");
        }
    }
};
