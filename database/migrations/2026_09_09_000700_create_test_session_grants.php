<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string,string> */
    private const SUPPORT_INDEXES = [
        'test_sessions_grant_scope_unique' => 'test_sessions (id, assessment_case_id, participant_id, test_type)',
        'assessment_cases_grant_scope_unique' => 'assessment_cases (id, participant_id, organization_id, origin)',
        'assessment_participants_grant_scope_unique' => 'assessment_participants (id, assessment_case_id, participant_id, organization_id)',
        'orders_grant_scope_unique' => 'orders (id, assessment_case_id, participant_id)',
        'selection_participants_grant_scope_unique' => 'selection_participants (id, assessment_case_id, participant_id)',
        'assessment_entitlements_grant_scope_unique' => 'assessment_entitlements (id, assessment_participant_id, organization_id, participant_id, test_type)',
        'entitlements_grant_scope_unique' => 'entitlements (id, participant_id, test_type)',
    ];

    public function up(): void
    {
        try {
            DB::transaction(function (): void {
                $driver = DB::getDriverName();
                if ($driver === 'pgsql') {
                    $this->lockParents();
                }

                if (Schema::hasTable('test_session_grants')) {
                    $this->assertExactState($driver);

                    return;
                }
                if ($this->supportStatePresent($driver)) {
                    $this->abort('partial supporting indexes already exist');
                }

                foreach (self::SUPPORT_INDEXES as $name => $definition) {
                    DB::statement("CREATE UNIQUE INDEX {$name} ON {$definition}");
                }
                DB::statement($this->tableSql($driver));
                DB::statement('CREATE UNIQUE INDEX test_session_grants_assessment_entitlement_unique ON test_session_grants (assessment_entitlement_id) WHERE assessment_entitlement_id IS NOT NULL');
                DB::statement('CREATE UNIQUE INDEX test_session_grants_entitlement_unique ON test_session_grants (entitlement_id) WHERE entitlement_id IS NOT NULL');

                if ($driver === 'pgsql') {
                    $this->addPostgresGuardAndSecurity();
                } else {
                    $this->addSqliteGuards();
                }
                $this->assertExactState($driver);
            });
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException
                && str_starts_with($exception->getMessage(), 'Test session grant migration aborted:')) {
                throw $exception;
            }
            throw new RuntimeException(
                'Test session grant migration aborted: '.$exception->getMessage(),
                (int) $exception->getCode(),
                $exception,
            );
        }
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $driver = DB::getDriverName();
            if (! Schema::hasTable('test_session_grants')) {
                if ($this->supportStatePresent($driver)) {
                    throw new RuntimeException('Partial test session grant schema prevents rollback.');
                }

                return;
            }
            if ($driver === 'pgsql') {
                DB::statement('LOCK TABLE test_session_grants IN ACCESS EXCLUSIVE MODE');
                $this->lockParents();
                DB::statement("SELECT set_config('app.role', 'service', true)");
            }
            if (DB::table('test_session_grants')->exists()) {
                throw new RuntimeException('Test session grant history prevents rollback.');
            }

            Schema::drop('test_session_grants');
            if ($driver === 'pgsql') {
                DB::unprepared('DROP FUNCTION IF EXISTS app_private.guard_test_session_grant_identity()');
            }
            foreach (array_reverse(array_keys(self::SUPPORT_INDEXES)) as $name) {
                DB::statement("DROP INDEX {$name}");
            }
        });
    }

    private function tableSql(string $driver): string
    {
        $bigint = $driver === 'pgsql' ? 'BIGINT' : 'INTEGER';
        $text = $driver === 'pgsql' ? 'VARCHAR' : 'TEXT';
        $timestamp = $driver === 'pgsql' ? 'TIMESTAMPTZ(6)' : 'TEXT';

        return <<<SQL
            CREATE TABLE test_session_grants (
                test_session_id {$bigint} PRIMARY KEY,
                assessment_case_id {$bigint} NOT NULL,
                participant_id {$bigint} NOT NULL,
                organization_id {$bigint} NOT NULL,
                test_type {$text} NOT NULL,
                origin {$text} NOT NULL,
                grant_kind {$text} NOT NULL,
                assessment_participant_id {$bigint} NULL,
                order_id {$bigint} NULL,
                selection_participant_id {$bigint} NULL,
                assessment_entitlement_id {$bigint} NULL,
                entitlement_id {$bigint} NULL,
                created_at {$timestamp} NOT NULL,
                CONSTRAINT test_session_grants_instrument_check CHECK (test_type IN ('ist','papi','rmib','kraepelin')),
                CONSTRAINT test_session_grants_shape_check CHECK (
                    (origin = 'INTEGRATED' AND grant_kind = 'assessment_entitlement'
                        AND assessment_participant_id IS NOT NULL AND assessment_entitlement_id IS NOT NULL
                        AND order_id IS NULL AND selection_participant_id IS NULL AND entitlement_id IS NULL)
                    OR (origin = 'DIRECT_PUBLIC' AND grant_kind = 'entitlement'
                        AND order_id IS NOT NULL AND entitlement_id IS NOT NULL
                        AND assessment_participant_id IS NULL AND selection_participant_id IS NULL
                        AND assessment_entitlement_id IS NULL)
                    OR (origin = 'LEGACY_SELECTION' AND grant_kind = 'entitlement'
                        AND selection_participant_id IS NOT NULL AND entitlement_id IS NOT NULL
                        AND assessment_participant_id IS NULL AND order_id IS NULL
                        AND assessment_entitlement_id IS NULL)
                ),
                CONSTRAINT test_session_grants_session_scope_fk FOREIGN KEY
                    (test_session_id, assessment_case_id, participant_id, test_type)
                    REFERENCES test_sessions (id, assessment_case_id, participant_id, test_type)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT test_session_grants_case_scope_fk FOREIGN KEY
                    (assessment_case_id, participant_id, organization_id, origin)
                    REFERENCES assessment_cases (id, participant_id, organization_id, origin)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT test_session_grants_attempt_scope_fk FOREIGN KEY
                    (assessment_participant_id, assessment_case_id, participant_id, organization_id)
                    REFERENCES assessment_participants (id, assessment_case_id, participant_id, organization_id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT test_session_grants_order_scope_fk FOREIGN KEY
                    (order_id, assessment_case_id, participant_id)
                    REFERENCES orders (id, assessment_case_id, participant_id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT test_session_grants_selection_scope_fk FOREIGN KEY
                    (selection_participant_id, assessment_case_id, participant_id)
                    REFERENCES selection_participants (id, assessment_case_id, participant_id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT test_session_grants_assessment_entitlement_scope_fk FOREIGN KEY
                    (assessment_entitlement_id, assessment_participant_id, organization_id, participant_id, test_type)
                    REFERENCES assessment_entitlements (id, assessment_participant_id, organization_id, participant_id, test_type)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT test_session_grants_entitlement_scope_fk FOREIGN KEY
                    (entitlement_id, participant_id, test_type)
                    REFERENCES entitlements (id, participant_id, test_type)
                    ON UPDATE RESTRICT ON DELETE RESTRICT
            )
            SQL;
    }

    private function addPostgresGuardAndSecurity(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION app_private.guard_test_session_grant_identity() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $guard$
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'Test session grant history is append-only' USING ERRCODE = 'P0001';
                END IF;
                IF NEW.origin = 'INTEGRATED' AND NOT EXISTS (
                    SELECT 1
                    FROM public.assessment_entitlements grant_row
                    JOIN public.assessment_participants attempt ON attempt.id = grant_row.assessment_participant_id
                    JOIN public.assessment_cases case_row ON case_row.id = attempt.assessment_case_id
                    WHERE grant_row.id = NEW.assessment_entitlement_id
                      AND grant_row.assessment_participant_id = NEW.assessment_participant_id
                      AND grant_row.organization_id = NEW.organization_id
                      AND grant_row.participant_id = NEW.participant_id
                      AND grant_row.test_type = NEW.test_type
                      AND grant_row.status = 'ready' AND grant_row.ready_at IS NOT NULL
                      AND grant_row.ready_at <= CURRENT_TIMESTAMP
                      AND grant_row.started_at IS NULL AND grant_row.completed_at IS NULL
                      AND attempt.assessment_case_id = NEW.assessment_case_id
                      AND attempt.participant_id = NEW.participant_id
                      AND attempt.organization_id = NEW.organization_id
                      AND attempt.assessment_status IN ('READY','IN_PROGRESS')
                      AND attempt.revoked_at IS NULL AND attempt.finalized_at IS NULL
                      AND case_row.origin = 'INTEGRATED'
                      AND NOT EXISTS (SELECT 1 FROM public.orders o WHERE o.participant_id = NEW.participant_id)
                      AND NOT EXISTS (SELECT 1 FROM public.selection_participants s WHERE s.participant_id = NEW.participant_id)
                      AND NOT EXISTS (SELECT 1 FROM public.entitlements e WHERE e.participant_id = NEW.participant_id)
                ) THEN
                    RAISE EXCEPTION 'Test session grant requires an exact integrated authorization graph' USING ERRCODE = '23514';
                ELSIF NEW.origin = 'DIRECT_PUBLIC' AND NOT EXISTS (
                    SELECT 1
                    FROM public.entitlements grant_row
                    JOIN public.orders source_order ON source_order.id = grant_row.order_id
                    JOIN public.assessment_cases case_row ON case_row.id = source_order.assessment_case_id
                    JOIN public.participants participant ON participant.id = grant_row.participant_id
                    WHERE grant_row.id = NEW.entitlement_id
                      AND grant_row.participant_id = NEW.participant_id
                      AND grant_row.test_type = NEW.test_type
                      AND grant_row.order_id = NEW.order_id
                      AND grant_row.status = 'ready' AND grant_row.ready_at IS NOT NULL
                      AND grant_row.ready_at <= CURRENT_TIMESTAMP
                      AND grant_row.started_at IS NULL AND grant_row.completed_at IS NULL
                      AND source_order.assessment_case_id = NEW.assessment_case_id
                      AND source_order.participant_id = NEW.participant_id
                      AND source_order.status = 'paid' AND source_order.paid_at IS NOT NULL
                      AND case_row.organization_id = NEW.organization_id
                      AND case_row.origin = 'DIRECT_PUBLIC'
                      AND participant.source_system = 'DIRECT_PUBLIC'
                      AND participant.branch_id = NEW.organization_id
                      AND participant.package_id = case_row.package_id
                      AND NOT EXISTS (SELECT 1 FROM public.selection_participants s WHERE s.participant_id = NEW.participant_id)
                      AND NOT EXISTS (SELECT 1 FROM public.assessment_participants a WHERE a.participant_id = NEW.participant_id)
                ) THEN
                    RAISE EXCEPTION 'Test session grant requires an exact direct authorization graph' USING ERRCODE = '23514';
                ELSIF NEW.origin = 'LEGACY_SELECTION' AND NOT EXISTS (
                    SELECT 1
                    FROM public.entitlements grant_row
                    JOIN public.selection_participants selection_row ON selection_row.participant_id = grant_row.participant_id
                    JOIN public.assessment_cases case_row ON case_row.id = selection_row.assessment_case_id
                    JOIN public.participants participant ON participant.id = grant_row.participant_id
                    WHERE grant_row.id = NEW.entitlement_id
                      AND grant_row.participant_id = NEW.participant_id
                      AND grant_row.test_type = NEW.test_type
                      AND grant_row.order_id IS NULL
                      AND grant_row.status = 'ready' AND grant_row.ready_at IS NOT NULL
                      AND grant_row.ready_at <= CURRENT_TIMESTAMP
                      AND grant_row.started_at IS NULL AND grant_row.completed_at IS NULL
                      AND selection_row.id = NEW.selection_participant_id
                      AND selection_row.assessment_case_id = NEW.assessment_case_id
                      AND case_row.organization_id = NEW.organization_id
                      AND case_row.origin = 'LEGACY_SELECTION'
                      AND participant.source_system = 'SELEKSI_BEASISWA_JEPANG'
                      AND participant.branch_id = NEW.organization_id
                      AND participant.package_id IS NULL
                      AND NOT EXISTS (SELECT 1 FROM public.orders o WHERE o.participant_id = NEW.participant_id)
                      AND NOT EXISTS (SELECT 1 FROM public.assessment_participants a WHERE a.participant_id = NEW.participant_id)
                ) THEN
                    RAISE EXCEPTION 'Test session grant requires an exact legacy authorization graph' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $guard$;
            CREATE TRIGGER test_session_grants_identity_guard
            BEFORE INSERT OR UPDATE OR DELETE ON test_session_grants
            FOR EACH ROW EXECUTE FUNCTION app_private.guard_test_session_grant_identity();

            REVOKE ALL PRIVILEGES ON test_session_grants FROM PUBLIC, psikotes_runtime;
            GRANT SELECT, INSERT ON test_session_grants TO psikotes_runtime;
            ALTER TABLE test_session_grants ENABLE ROW LEVEL SECURITY;
            ALTER TABLE test_session_grants FORCE ROW LEVEL SECURITY;
            CREATE POLICY test_session_grants_service_select ON test_session_grants
                FOR SELECT TO psikotes_runtime USING (app_private.app_role() = 'service');
            CREATE POLICY test_session_grants_service_insert ON test_session_grants
                FOR INSERT TO psikotes_runtime WITH CHECK (app_private.app_role() = 'service');
            SQL);
    }

    private function addSqliteGuards(): void
    {
        foreach (['INSERT', 'UPDATE', 'DELETE'] as $event) {
            $name = strtolower($event);
            if ($event === 'INSERT') {
                DB::unprepared(<<<'SQL'
                    CREATE TRIGGER test_session_grants_insert_guard
                    BEFORE INSERT ON test_session_grants FOR EACH ROW
                    WHEN
                        (NEW.origin = 'INTEGRATED' AND NOT EXISTS (
                            SELECT 1 FROM assessment_entitlements grant_row
                            JOIN assessment_participants attempt ON attempt.id = grant_row.assessment_participant_id
                            JOIN assessment_cases case_row ON case_row.id = attempt.assessment_case_id
                            WHERE grant_row.id = NEW.assessment_entitlement_id
                              AND grant_row.assessment_participant_id = NEW.assessment_participant_id
                              AND grant_row.organization_id = NEW.organization_id
                              AND grant_row.participant_id = NEW.participant_id
                              AND grant_row.test_type = NEW.test_type
                              AND grant_row.status = 'ready' AND grant_row.ready_at IS NOT NULL
                              AND grant_row.ready_at <= CURRENT_TIMESTAMP
                              AND grant_row.started_at IS NULL AND grant_row.completed_at IS NULL
                              AND attempt.assessment_case_id = NEW.assessment_case_id
                              AND attempt.participant_id = NEW.participant_id
                              AND attempt.organization_id = NEW.organization_id
                              AND attempt.assessment_status IN ('READY','IN_PROGRESS')
                              AND attempt.revoked_at IS NULL AND attempt.finalized_at IS NULL
                              AND case_row.origin = 'INTEGRATED'
                              AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.participant_id = NEW.participant_id)
                              AND NOT EXISTS (SELECT 1 FROM selection_participants s WHERE s.participant_id = NEW.participant_id)
                              AND NOT EXISTS (SELECT 1 FROM entitlements e WHERE e.participant_id = NEW.participant_id)
                        ))
                        OR (NEW.origin = 'DIRECT_PUBLIC' AND NOT EXISTS (
                            SELECT 1 FROM entitlements grant_row
                            JOIN orders source_order ON source_order.id = grant_row.order_id
                            JOIN assessment_cases case_row ON case_row.id = source_order.assessment_case_id
                            JOIN participants participant ON participant.id = grant_row.participant_id
                            WHERE grant_row.id = NEW.entitlement_id
                              AND grant_row.participant_id = NEW.participant_id
                              AND grant_row.test_type = NEW.test_type
                              AND grant_row.order_id = NEW.order_id
                              AND grant_row.status = 'ready' AND grant_row.ready_at IS NOT NULL
                              AND grant_row.ready_at <= CURRENT_TIMESTAMP
                              AND grant_row.started_at IS NULL AND grant_row.completed_at IS NULL
                              AND source_order.assessment_case_id = NEW.assessment_case_id
                              AND source_order.participant_id = NEW.participant_id
                              AND source_order.status = 'paid' AND source_order.paid_at IS NOT NULL
                              AND case_row.organization_id = NEW.organization_id
                              AND case_row.origin = 'DIRECT_PUBLIC'
                              AND participant.source_system = 'DIRECT_PUBLIC'
                              AND participant.branch_id = NEW.organization_id
                              AND participant.package_id = case_row.package_id
                              AND NOT EXISTS (SELECT 1 FROM selection_participants s WHERE s.participant_id = NEW.participant_id)
                              AND NOT EXISTS (SELECT 1 FROM assessment_participants a WHERE a.participant_id = NEW.participant_id)
                        ))
                        OR (NEW.origin = 'LEGACY_SELECTION' AND NOT EXISTS (
                            SELECT 1 FROM entitlements grant_row
                            JOIN selection_participants selection_row ON selection_row.participant_id = grant_row.participant_id
                            JOIN assessment_cases case_row ON case_row.id = selection_row.assessment_case_id
                            JOIN participants participant ON participant.id = grant_row.participant_id
                            WHERE grant_row.id = NEW.entitlement_id
                              AND grant_row.participant_id = NEW.participant_id
                              AND grant_row.test_type = NEW.test_type
                              AND grant_row.order_id IS NULL
                              AND grant_row.status = 'ready' AND grant_row.ready_at IS NOT NULL
                              AND grant_row.ready_at <= CURRENT_TIMESTAMP
                              AND grant_row.started_at IS NULL AND grant_row.completed_at IS NULL
                              AND selection_row.id = NEW.selection_participant_id
                              AND selection_row.assessment_case_id = NEW.assessment_case_id
                              AND case_row.organization_id = NEW.organization_id
                              AND case_row.origin = 'LEGACY_SELECTION'
                              AND participant.source_system = 'SELEKSI_BEASISWA_JEPANG'
                              AND participant.branch_id = NEW.organization_id
                              AND participant.package_id IS NULL
                              AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.participant_id = NEW.participant_id)
                              AND NOT EXISTS (SELECT 1 FROM assessment_participants a WHERE a.participant_id = NEW.participant_id)
                        ))
                    BEGIN SELECT RAISE(ABORT, 'Test session grant requires an exact authorization graph'); END;
                    SQL);
            } else {
                DB::unprepared("CREATE TRIGGER test_session_grants_{$name}_guard BEFORE {$event} ON test_session_grants FOR EACH ROW BEGIN SELECT RAISE(ABORT, 'Test session grant history is append-only'); END;");
            }
        }
    }

    private function assertExactState(string $driver): void
    {
        if ($driver === 'sqlite') {
            $columns = collect(DB::select("PRAGMA table_info('test_session_grants')"))->keyBy('name');
            $expected = [
                'test_session_id', 'assessment_case_id', 'participant_id', 'organization_id',
                'test_type', 'origin', 'grant_kind', 'assessment_participant_id', 'order_id',
                'selection_participant_id', 'assessment_entitlement_id', 'entitlement_id', 'created_at',
            ];
            $foreignCount = collect(DB::select("PRAGMA foreign_key_list('test_session_grants')"))->groupBy('id')->count();
            $triggers = collect(DB::select("SELECT name FROM sqlite_master WHERE type='trigger' AND tbl_name='test_session_grants'"))->pluck('name')->sort()->values()->all();
            $indexes = collect(DB::select("SELECT name FROM sqlite_master WHERE type='index'"))->pluck('name')->all();
            if ($columns->keys()->all() !== $expected || $foreignCount !== 7
                || $triggers !== ['test_session_grants_delete_guard', 'test_session_grants_insert_guard', 'test_session_grants_update_guard']
                || ! collect(array_keys(self::SUPPORT_INDEXES))->every(fn (string $name): bool => in_array($name, $indexes, true))
                || ! in_array('test_session_grants_assessment_entitlement_unique', $indexes, true)
                || ! in_array('test_session_grants_entitlement_unique', $indexes, true)) {
                $this->abort('partial SQLite enforcement already exists');
            }

            return;
        }

        $columns = (int) DB::scalar("SELECT count(*) FROM pg_attribute WHERE attrelid='test_session_grants'::regclass AND attnum>0 AND NOT attisdropped");
        $foreigns = (int) DB::scalar("SELECT count(*) FROM pg_constraint WHERE conrelid='test_session_grants'::regclass AND contype='f'");
        $checks = (int) DB::scalar("SELECT count(*) FROM pg_constraint WHERE conrelid='test_session_grants'::regclass AND contype='c'");
        $security = DB::selectOne("SELECT relrowsecurity,relforcerowsecurity FROM pg_class WHERE oid='test_session_grants'::regclass");
        $trigger = (int) DB::scalar("SELECT count(*) FROM pg_trigger WHERE tgrelid='test_session_grants'::regclass AND tgname='test_session_grants_identity_guard' AND NOT tgisinternal AND tgenabled='O'");
        $policies = (int) DB::scalar("SELECT count(*) FROM pg_policy WHERE polrelid='test_session_grants'::regclass");
        if ($columns !== 13 || $foreigns !== 7 || $checks !== 2 || ! $security->relrowsecurity
            || ! $security->relforcerowsecurity || $trigger !== 1 || $policies !== 2) {
            $this->abort('partial PostgreSQL enforcement already exists');
        }
    }

    private function supportStatePresent(string $driver): bool
    {
        foreach (array_keys(self::SUPPORT_INDEXES) as $name) {
            $present = $driver === 'pgsql'
                ? (bool) DB::scalar('SELECT to_regclass(?) IS NOT NULL', [$name])
                : DB::selectOne("SELECT 1 AS present FROM sqlite_master WHERE type='index' AND name=?", [$name]) !== null;
            if ($present) {
                return true;
            }
        }

        return false;
    }

    private function lockParents(): void
    {
        foreach ([
            'test_sessions', 'assessment_cases', 'orders', 'selection_participants',
            'assessment_participants', 'entitlements', 'assessment_entitlements',
        ] as $table) {
            DB::statement("LOCK TABLE {$table} IN ACCESS EXCLUSIVE MODE");
        }
    }

    private function abort(string $reason): never
    {
        throw new RuntimeException('Test session grant migration aborted: '.$reason);
    }
};
