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
                $this->lockParents();
                DB::statement('LOCK TABLE test_session_grants IN ACCESS EXCLUSIVE MODE');
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
                      AND (SELECT COUNT(*) FROM public.assessment_cases c WHERE c.participant_id = NEW.participant_id) = 1
                      AND (SELECT COUNT(*) FROM public.assessment_participants a WHERE a.participant_id = NEW.participant_id) = 1
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
                      AND case_row.public_id = source_order.public_id
                      AND participant.source_system = 'DIRECT_PUBLIC'
                      AND participant.branch_id = NEW.organization_id
                      AND participant.package_id = case_row.package_id
                      AND (SELECT COUNT(*) FROM public.assessment_cases c WHERE c.participant_id = NEW.participant_id) = 1
                      AND (SELECT COUNT(*) FROM public.orders o WHERE o.participant_id = NEW.participant_id) = 1
                      AND NOT EXISTS (
                          SELECT 1 FROM public.package_items item
                          WHERE item.package_id = participant.package_id
                            AND NOT EXISTS (
                                SELECT 1 FROM public.entitlements e
                                WHERE e.participant_id = NEW.participant_id
                                  AND e.order_id = NEW.order_id AND e.test_type = item.test_type
                            )
                      )
                      AND NOT EXISTS (
                          SELECT 1 FROM public.entitlements e
                          WHERE e.participant_id = NEW.participant_id
                            AND (e.order_id IS DISTINCT FROM NEW.order_id OR NOT EXISTS (
                                SELECT 1 FROM public.package_items item
                                WHERE item.package_id = participant.package_id AND item.test_type = e.test_type
                            ))
                      )
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
                      AND case_row.package_id IS NULL
                      AND participant.source_system = 'SELEKSI_BEASISWA_JEPANG'
                      AND participant.branch_id = NEW.organization_id
                      AND participant.package_id IS NULL
                      AND (SELECT COUNT(*) FROM public.assessment_cases c WHERE c.participant_id = NEW.participant_id) = 1
                      AND (SELECT COUNT(*) FROM public.selection_participants s WHERE s.participant_id = NEW.participant_id) = 1
                      AND NOT EXISTS (SELECT 1 FROM public.entitlements e WHERE e.participant_id = NEW.participant_id AND e.order_id IS NOT NULL)
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
                              AND (SELECT COUNT(*) FROM assessment_cases c WHERE c.participant_id = NEW.participant_id) = 1
                              AND (SELECT COUNT(*) FROM assessment_participants a WHERE a.participant_id = NEW.participant_id) = 1
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
                              AND case_row.public_id = source_order.public_id
                              AND participant.source_system = 'DIRECT_PUBLIC'
                              AND participant.branch_id = NEW.organization_id
                              AND participant.package_id = case_row.package_id
                              AND (SELECT COUNT(*) FROM assessment_cases c WHERE c.participant_id = NEW.participant_id) = 1
                              AND (SELECT COUNT(*) FROM orders o WHERE o.participant_id = NEW.participant_id) = 1
                              AND NOT EXISTS (
                                  SELECT 1 FROM package_items item
                                  WHERE item.package_id = participant.package_id
                                    AND NOT EXISTS (
                                        SELECT 1 FROM entitlements e
                                        WHERE e.participant_id = NEW.participant_id
                                          AND e.order_id = NEW.order_id AND e.test_type = item.test_type
                                    )
                              )
                              AND NOT EXISTS (
                                  SELECT 1 FROM entitlements e
                                  WHERE e.participant_id = NEW.participant_id
                                    AND (e.order_id IS NOT NEW.order_id OR NOT EXISTS (
                                        SELECT 1 FROM package_items item
                                        WHERE item.package_id = participant.package_id AND item.test_type = e.test_type
                                    ))
                              )
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
                              AND case_row.package_id IS NULL
                              AND participant.source_system = 'SELEKSI_BEASISWA_JEPANG'
                              AND participant.branch_id = NEW.organization_id
                              AND participant.package_id IS NULL
                              AND (SELECT COUNT(*) FROM assessment_cases c WHERE c.participant_id = NEW.participant_id) = 1
                              AND (SELECT COUNT(*) FROM selection_participants s WHERE s.participant_id = NEW.participant_id) = 1
                              AND NOT EXISTS (SELECT 1 FROM entitlements e WHERE e.participant_id = NEW.participant_id AND e.order_id IS NOT NULL)
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
            $this->assertExactSqliteState();

            return;
        }

        $this->assertExactPostgresState();
    }

    private function assertExactSqliteState(): void
    {
        $table = DB::selectOne("SELECT sql FROM sqlite_master WHERE type='table' AND name='test_session_grants'");
        $expectedIndexes = [];
        foreach (self::SUPPORT_INDEXES as $name => $definition) {
            $expectedIndexes[$name] = $this->normalizeSql("CREATE UNIQUE INDEX {$name} ON {$definition}");
        }
        $expectedIndexes['test_session_grants_assessment_entitlement_unique'] = $this->normalizeSql('CREATE UNIQUE INDEX test_session_grants_assessment_entitlement_unique ON test_session_grants (assessment_entitlement_id) WHERE assessment_entitlement_id IS NOT NULL');
        $expectedIndexes['test_session_grants_entitlement_unique'] = $this->normalizeSql('CREATE UNIQUE INDEX test_session_grants_entitlement_unique ON test_session_grants (entitlement_id) WHERE entitlement_id IS NOT NULL');
        $actualIndexes = collect(DB::select("SELECT name,sql FROM sqlite_master WHERE type='index' AND name IN ('".implode("','", array_keys($expectedIndexes))."')"))
            ->mapWithKeys(function (object $row): array {
                $data = (array) $row;

                return [(string) $data['name'] => $this->normalizeSql((string) $data['sql'])];
            })->all();
        ksort($expectedIndexes);
        ksort($actualIndexes);

        $triggers = collect(DB::select("SELECT name,sql FROM sqlite_master WHERE type='trigger' AND tbl_name='test_session_grants'"))
            ->mapWithKeys(function (object $row): array {
                $data = (array) $row;

                return [(string) $data['name'] => $this->normalizeSql((string) $data['sql'])];
            })->all();
        $insert = $triggers['test_session_grants_insert_guard'] ?? '';
        $update = $triggers['test_session_grants_update_guard'] ?? '';
        $delete = $triggers['test_session_grants_delete_guard'] ?? '';
        $tableData = $table === null ? [] : (array) $table;
        if ($table === null || $this->normalizeSql((string) $tableData['sql']) !== $this->normalizeSql($this->tableSql('sqlite'))
            || $actualIndexes !== $expectedIndexes
            || ! str_starts_with($insert, 'CREATE TRIGGER test_session_grants_insert_guard BEFORE INSERT ON test_session_grants FOR EACH ROW WHEN ')
            || ! str_contains($insert, 'case_row.package_id IS NULL')
            || ! str_contains($insert, '(SELECT COUNT(*) FROM orders o WHERE o.participant_id = NEW.participant_id) = 1')
            || ! str_contains($insert, 'SELECT 1 FROM package_items item')
            || $update !== $this->normalizeSql("CREATE TRIGGER test_session_grants_update_guard BEFORE UPDATE ON test_session_grants FOR EACH ROW BEGIN SELECT RAISE(ABORT, 'Test session grant history is append-only'); END")
            || $delete !== $this->normalizeSql("CREATE TRIGGER test_session_grants_delete_guard BEFORE DELETE ON test_session_grants FOR EACH ROW BEGIN SELECT RAISE(ABORT, 'Test session grant history is append-only'); END")) {
            $this->abort('partial SQLite enforcement already exists');
        }
    }

    private function assertExactPostgresState(): void
    {
        $columns = collect(DB::select(<<<'SQL'
            SELECT attname,format_type(atttypid,atttypmod) type,attnotnull,
                   pg_get_expr(adbin,adrelid) default_value
            FROM pg_attribute LEFT JOIN pg_attrdef ON adrelid=attrelid AND adnum=attnum
            WHERE attrelid='test_session_grants'::regclass AND attnum>0 AND NOT attisdropped
            ORDER BY attnum
            SQL))->map(function (object $row): array {
            $data = (array) $row;

            return [(string) $data['attname'], (string) $data['type'], (bool) $data['attnotnull'], $data['default_value']];
        })->all();
        $expectedColumns = [
            ['test_session_id', 'bigint', true, null], ['assessment_case_id', 'bigint', true, null],
            ['participant_id', 'bigint', true, null], ['organization_id', 'bigint', true, null],
            ['test_type', 'character varying', true, null], ['origin', 'character varying', true, null],
            ['grant_kind', 'character varying', true, null], ['assessment_participant_id', 'bigint', false, null],
            ['order_id', 'bigint', false, null], ['selection_participant_id', 'bigint', false, null],
            ['assessment_entitlement_id', 'bigint', false, null], ['entitlement_id', 'bigint', false, null],
            ['created_at', 'timestamp(6) with time zone', true, null],
        ];
        $constraints = collect(DB::select("SELECT conname,contype,pg_get_constraintdef(oid,false) definition FROM pg_constraint WHERE conrelid='test_session_grants'::regclass"))
            ->mapWithKeys(function (object $row): array {
                $data = (array) $row;

                return [(string) $data['conname'] => [(string) $data['contype'], $this->normalizeSql((string) $data['definition'])]];
            })->all();
        $expectedForeigns = [
            'test_session_grants_session_scope_fk' => 'FOREIGN KEY (test_session_id, assessment_case_id, participant_id, test_type) REFERENCES test_sessions(id, assessment_case_id, participant_id, test_type) ON UPDATE RESTRICT ON DELETE RESTRICT',
            'test_session_grants_case_scope_fk' => 'FOREIGN KEY (assessment_case_id, participant_id, organization_id, origin) REFERENCES assessment_cases(id, participant_id, organization_id, origin) ON UPDATE RESTRICT ON DELETE RESTRICT',
            'test_session_grants_attempt_scope_fk' => 'FOREIGN KEY (assessment_participant_id, assessment_case_id, participant_id, organization_id) REFERENCES assessment_participants(id, assessment_case_id, participant_id, organization_id) ON UPDATE RESTRICT ON DELETE RESTRICT',
            'test_session_grants_order_scope_fk' => 'FOREIGN KEY (order_id, assessment_case_id, participant_id) REFERENCES orders(id, assessment_case_id, participant_id) ON UPDATE RESTRICT ON DELETE RESTRICT',
            'test_session_grants_selection_scope_fk' => 'FOREIGN KEY (selection_participant_id, assessment_case_id, participant_id) REFERENCES selection_participants(id, assessment_case_id, participant_id) ON UPDATE RESTRICT ON DELETE RESTRICT',
            'test_session_grants_assessment_entitlement_scope_fk' => 'FOREIGN KEY (assessment_entitlement_id, assessment_participant_id, organization_id, participant_id, test_type) REFERENCES assessment_entitlements(id, assessment_participant_id, organization_id, participant_id, test_type) ON UPDATE RESTRICT ON DELETE RESTRICT',
            'test_session_grants_entitlement_scope_fk' => 'FOREIGN KEY (entitlement_id, participant_id, test_type) REFERENCES entitlements(id, participant_id, test_type) ON UPDATE RESTRICT ON DELETE RESTRICT',
        ];
        $expectedIndexes = [];
        foreach (self::SUPPORT_INDEXES as $name => $definition) {
            [$indexTable, $indexColumns] = explode(' ', $definition, 2);
            $expectedIndexes[$name] = $this->normalizeSql("CREATE UNIQUE INDEX {$name} ON public.{$indexTable} USING btree {$indexColumns}");
        }
        $expectedIndexes['test_session_grants_assessment_entitlement_unique'] = $this->normalizeSql('CREATE UNIQUE INDEX test_session_grants_assessment_entitlement_unique ON public.test_session_grants USING btree (assessment_entitlement_id) WHERE (assessment_entitlement_id IS NOT NULL)');
        $expectedIndexes['test_session_grants_entitlement_unique'] = $this->normalizeSql('CREATE UNIQUE INDEX test_session_grants_entitlement_unique ON public.test_session_grants USING btree (entitlement_id) WHERE (entitlement_id IS NOT NULL)');
        $actualIndexes = collect(DB::select("SELECT indexname,indexdef FROM pg_indexes WHERE schemaname='public' AND indexname IN ('".implode("','", array_keys($expectedIndexes))."')"))
            ->mapWithKeys(function (object $row): array {
                $data = (array) $row;

                return [(string) $data['indexname'] => $this->normalizeSql((string) $data['indexdef'])];
            })->all();
        ksort($expectedIndexes);
        ksort($actualIndexes);
        $security = DB::selectOne("SELECT relrowsecurity,relforcerowsecurity FROM pg_class WHERE oid='test_session_grants'::regclass");
        $trigger = DB::selectOne(<<<'SQL'
            SELECT trigger.tgtype,trigger.tgenabled,namespace.nspname,function.proname,function.prosecdef,
                   function.proconfig,language.lanname,pg_get_functiondef(function.oid) definition
            FROM pg_trigger trigger JOIN pg_proc function ON function.oid=trigger.tgfoid
            JOIN pg_namespace namespace ON namespace.oid=function.pronamespace
            JOIN pg_language language ON language.oid=function.prolang
            WHERE trigger.tgrelid='test_session_grants'::regclass
              AND trigger.tgname='test_session_grants_identity_guard' AND NOT trigger.tgisinternal
            SQL);
        $policies = collect(DB::select(<<<'SQL'
            SELECT policyname,permissive,roles,cmd,qual,with_check
            FROM pg_policies WHERE schemaname='public' AND tablename='test_session_grants' ORDER BY policyname
            SQL))->map(function (object $row): array {
            $data = (array) $row;

            return [(string) $data['policyname'], (string) $data['permissive'], (string) $data['roles'],
                (string) $data['cmd'], $data['qual'], $data['with_check']];
        })->all();
        $privileges = collect(DB::select(<<<'SQL'
            SELECT grantee,privilege_type FROM information_schema.role_table_grants
            WHERE table_schema='public' AND table_name='test_session_grants'
              AND grantee IN ('psikotes_runtime','PUBLIC') ORDER BY grantee,privilege_type
            SQL))->map(function (object $row): array {
            $data = (array) $row;

            return [(string) $data['grantee'], (string) $data['privilege_type']];
        })->all();
        $securityData = $security === null ? [] : (array) $security;
        $triggerData = $trigger === null ? [] : (array) $trigger;
        $functionDefinition = $trigger === null ? '' : $this->normalizeSql((string) $triggerData['definition']);
        $foreignsExact = collect($expectedForeigns)->every(fn (string $definition, string $name): bool => ($constraints[$name] ?? null) === ['f', $this->normalizeSql($definition)]);
        if ($columns !== $expectedColumns || count($constraints) !== 10
            || ! isset($constraints['test_session_grants_pkey'], $constraints['test_session_grants_instrument_check'], $constraints['test_session_grants_shape_check'])
            || collect($constraints)->filter(fn (array $row): bool => $row[0] === 'f')->count() !== 7
            || ! $foreignsExact || $constraints['test_session_grants_pkey'] !== ['p', 'PRIMARY KEY (test_session_id)']
            || $actualIndexes !== $expectedIndexes
            || $security === null || ! $securityData['relrowsecurity'] || ! $securityData['relforcerowsecurity'] || $trigger === null
            || (int) $triggerData['tgtype'] !== 31 || (string) $triggerData['tgenabled'] !== 'O'
            || (string) $triggerData['nspname'] !== 'app_private' || (string) $triggerData['proname'] !== 'guard_test_session_grant_identity'
            || ! $triggerData['prosecdef'] || (string) $triggerData['lanname'] !== 'plpgsql'
            || (string) $triggerData['proconfig'] !== '{"search_path=pg_catalog, public"}'
            || ! str_contains($functionDefinition, 'Test session grant history is append-only')
            || ! str_contains($functionDefinition, 'case_row.package_id IS NULL')
            || ! str_contains($functionDefinition, 'SELECT 1 FROM public.package_items item')
            || $policies !== [
                ['test_session_grants_service_insert', 'PERMISSIVE', '{psikotes_runtime}', 'INSERT', null, "(app_private.app_role() = 'service'::text)"],
                ['test_session_grants_service_select', 'PERMISSIVE', '{psikotes_runtime}', 'SELECT', "(app_private.app_role() = 'service'::text)", null],
            ] || $privileges !== [['psikotes_runtime', 'INSERT'], ['psikotes_runtime', 'SELECT']]) {
            $this->abort('partial PostgreSQL enforcement already exists');
        }
    }

    private function normalizeSql(string $sql): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $sql));
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
            'participants', 'packages', 'package_items', 'assessment_cases', 'orders',
            'selection_participants', 'assessment_participants', 'entitlements',
            'assessment_entitlements', 'test_sessions',
        ] as $table) {
            DB::statement("LOCK TABLE {$table} IN ACCESS EXCLUSIVE MODE");
        }
    }

    private function abort(string $reason): never
    {
        throw new RuntimeException('Test session grant migration aborted: '.$reason);
    }
};
