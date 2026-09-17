<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CASE_FOREIGN = 'entitlements_case_scope_fk';

    private const CASE_UNIQUE = 'entitlements_case_test_type_unique';

    private const GRANT_SCOPE_UNIQUE = 'entitlements_case_grant_scope_unique';

    private const POSTGRES_GUARD_BODY_SHA256 = 'e3fd1d0b20d1cc07adee61e531bc85f63062e896b1fe14f7a783b7329c067d64';

    private const POSTGRES_POLICIES_SHA256 = 'e4d46ccf4216d19c07ab8a91c29640d9936a2817ac1297d961f5e98a6ffdeffa';

    public function up(): void
    {
        try {
            $this->transactional(function (): void {
                $driver = DB::getDriverName();
                $forceRls = [];
                if ($driver === 'pgsql') {
                    $this->lockPostgresTables();
                    $forceRls = $this->postgresForceRlsState();
                    $this->forcePostgresRls(false);
                }
                try {
                    if (! Schema::hasColumn('entitlements', 'assessment_case_id')) {
                        $this->assertNoPartialState($driver);
                        $this->assertExactDirectCompositions();
                        $this->assertExactHistoricalSources();

                        Schema::table('entitlements', function (Blueprint $table): void {
                            $table->unsignedBigInteger('assessment_case_id')->nullable();
                        });
                        $this->backfill();
                        $this->assertBackfillComplete();
                        $this->enforce($driver);
                    }
                } finally {
                    if ($driver === 'pgsql') {
                        $this->restorePostgresForceRls($forceRls);
                    }
                }
                $this->assertExactState($driver);
            });
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException
                && str_starts_with($exception->getMessage(), 'Generic entitlement case migration aborted:')) {
                throw $exception;
            }

            throw new RuntimeException(
                'Generic entitlement case migration aborted: '.$exception->getMessage(),
                (int) $exception->getCode(),
                $exception,
            );
        }
    }

    public function down(): void
    {
        $this->transactional(function (): void {
            $driver = DB::getDriverName();
            $forceRls = [];
            if (! Schema::hasColumn('entitlements', 'assessment_case_id')) {
                $this->assertNoPartialState($driver);

                return;
            }
            if ($driver === 'pgsql') {
                $this->lockPostgresTables();
                $forceRls = $this->postgresForceRlsState();
                $this->forcePostgresRls(false);
            }
            try {
                if (DB::table('entitlements')->whereNotNull('assessment_case_id')->exists()) {
                    throw new RuntimeException('Generic entitlement case history prevents rollback.');
                }

                $this->removeEnforcement($driver);
                $this->withoutSqliteReferencingTriggers($driver, function (): void {
                    Schema::table('entitlements', function (Blueprint $table): void {
                        $table->dropColumn('assessment_case_id');
                    });
                });
            } finally {
                if ($driver === 'pgsql') {
                    $this->restorePostgresForceRls($forceRls);
                }
            }
        });
    }

    private function assertExactDirectCompositions(): void
    {
        $invalid = DB::selectOne(<<<'SQL'
            SELECT EXISTS (
                SELECT 1
                FROM orders source_order
                JOIN participants participant ON participant.id = source_order.participant_id
                JOIN assessment_cases assessment_case ON assessment_case.id = source_order.assessment_case_id
                WHERE assessment_case.origin = 'DIRECT_PUBLIC'
                  AND (
                    participant.source_system <> 'DIRECT_PUBLIC'
                    OR participant.branch_id <> assessment_case.organization_id
                    OR participant.package_id IS NULL
                    OR participant.package_id <> assessment_case.package_id
                    OR (SELECT COUNT(*) FROM package_items item
                        WHERE item.package_id = assessment_case.package_id) < 2
                    OR (SELECT COUNT(*) FROM package_items item
                        WHERE item.package_id = assessment_case.package_id AND item.test_type = 'dass21') <> 1
                    OR (SELECT COUNT(*) FROM package_items item
                        WHERE item.package_id = assessment_case.package_id
                          AND item.test_type IN ('ist','papi','rmib','kraepelin')) < 1
                    OR EXISTS (SELECT 1 FROM package_items item
                        WHERE item.package_id = assessment_case.package_id
                          AND item.test_type NOT IN ('dass21','ist','papi','rmib','kraepelin'))
                    OR EXISTS (SELECT 1 FROM package_items item
                        WHERE item.package_id = assessment_case.package_id
                          AND NOT EXISTS (SELECT 1 FROM entitlements entitlement
                            WHERE entitlement.order_id = source_order.id
                              AND entitlement.participant_id = source_order.participant_id
                              AND entitlement.test_type = item.test_type))
                    OR EXISTS (SELECT 1 FROM entitlements entitlement
                        WHERE entitlement.order_id = source_order.id
                          AND (entitlement.participant_id <> source_order.participant_id
                            OR NOT EXISTS (SELECT 1 FROM package_items item
                              WHERE item.package_id = assessment_case.package_id
                                AND item.test_type = entitlement.test_type)))
                  )
            ) invalid
            SQL)->invalid;
        if ($invalid) {
            $this->abort('direct package composition and entitlements must be an exact main-battery mirror');
        }
    }

    private function assertExactHistoricalSources(): void
    {
        $invalid = DB::selectOne(<<<'SQL'
            SELECT EXISTS (
                SELECT 1
                FROM entitlements entitlement
                WHERE entitlement.test_type IN ('ist','papi','rmib','kraepelin')
                  AND (
                    (CASE WHEN EXISTS (
                        SELECT 1
                        FROM orders source_order
                        JOIN participants participant ON participant.id = entitlement.participant_id
                        JOIN assessment_cases assessment_case
                          ON assessment_case.id = source_order.assessment_case_id
                        JOIN package_items package_item
                          ON package_item.package_id = assessment_case.package_id
                         AND package_item.test_type = entitlement.test_type
                        WHERE source_order.id = entitlement.order_id
                          AND source_order.participant_id = entitlement.participant_id
                          AND participant.source_system = 'DIRECT_PUBLIC'
                          AND participant.branch_id = assessment_case.organization_id
                          AND participant.package_id = assessment_case.package_id
                          AND assessment_case.origin = 'DIRECT_PUBLIC'
                    ) THEN 1 ELSE 0 END)
                    +
                    (CASE WHEN entitlement.order_id IS NULL AND (
                        SELECT COUNT(*)
                        FROM selection_participants selection_row
                        JOIN participants participant ON participant.id = entitlement.participant_id
                        JOIN assessment_cases assessment_case
                          ON assessment_case.id = selection_row.assessment_case_id
                        WHERE selection_row.participant_id = entitlement.participant_id
                          AND participant.source_system = 'SELEKSI_BEASISWA_JEPANG'
                          AND participant.branch_id = assessment_case.organization_id
                          AND participant.package_id IS NULL
                          AND assessment_case.package_id IS NULL
                          AND assessment_case.origin = 'LEGACY_SELECTION'
                    ) = 1 THEN 1 ELSE 0 END)
                  ) <> 1
            ) AS invalid
            SQL)->invalid;
        if ($invalid) {
            $this->abort('every generic entitlement must have exactly one durable case source');
        }
    }

    private function backfill(): void
    {
        DB::statement(<<<'SQL'
            UPDATE entitlements
            SET assessment_case_id = (
                SELECT source_order.assessment_case_id
                FROM orders source_order
                WHERE source_order.id = entitlements.order_id
                  AND source_order.participant_id = entitlements.participant_id
            )
            WHERE test_type IN ('ist','papi','rmib','kraepelin')
              AND order_id IS NOT NULL
            SQL);
        DB::statement(<<<'SQL'
            UPDATE entitlements
            SET assessment_case_id = (
                SELECT selection_row.assessment_case_id
                FROM selection_participants selection_row
                WHERE selection_row.participant_id = entitlements.participant_id
            )
            WHERE test_type IN ('ist','papi','rmib','kraepelin')
              AND order_id IS NULL
            SQL);
    }

    private function assertBackfillComplete(): void
    {
        if (DB::table('entitlements')->whereIn('test_type', ['ist', 'papi', 'rmib', 'kraepelin'])
            ->whereNull('assessment_case_id')->exists()
            || DB::table('entitlements')->where('test_type', 'dass21')->whereNotNull('assessment_case_id')->exists()) {
            $this->abort('case backfill is incomplete or crossed the DASS boundary');
        }
    }

    private function enforce(string $driver): void
    {
        $this->withoutSqliteReferencingTriggers($driver, function (): void {
            Schema::table('entitlements', function (Blueprint $table): void {
                $table->foreign(['assessment_case_id', 'participant_id'], self::CASE_FOREIGN)
                    ->references(['id', 'participant_id'])->on('assessment_cases')->restrictOnDelete();
            });
        });
        DB::statement('CREATE UNIQUE INDEX '.self::CASE_UNIQUE.' ON entitlements (assessment_case_id, test_type) WHERE assessment_case_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX '.self::GRANT_SCOPE_UNIQUE.' ON entitlements (id, assessment_case_id, participant_id, test_type)');

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION app_private.guard_generic_entitlement_case_identity() RETURNS trigger
                LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $guard$
                BEGIN
                    IF TG_OP = 'UPDATE' AND OLD.assessment_case_id IS NOT NULL
                        AND NEW.assessment_case_id IS DISTINCT FROM OLD.assessment_case_id THEN
                        RAISE EXCEPTION 'Generic entitlement case identity is immutable' USING ERRCODE = 'P0001';
                    END IF;
                    IF NEW.test_type = 'dass21' AND NEW.assessment_case_id IS NOT NULL THEN
                        RAISE EXCEPTION 'DASS entitlement cannot bind a generic assessment case' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.assessment_case_id IS NULL THEN
                        RETURN NEW;
                    END IF;
                    IF NOT EXISTS (
                        SELECT 1 FROM public.orders source_order
                        JOIN public.participants participant ON participant.id = NEW.participant_id
                        JOIN public.assessment_cases assessment_case ON assessment_case.id = source_order.assessment_case_id
                        JOIN public.package_items package_item ON package_item.package_id = assessment_case.package_id
                          AND package_item.test_type = NEW.test_type
                        WHERE source_order.id = NEW.order_id AND source_order.participant_id = NEW.participant_id
                          AND source_order.assessment_case_id = NEW.assessment_case_id
                          AND participant.source_system = 'DIRECT_PUBLIC'
                          AND participant.branch_id = assessment_case.organization_id
                          AND participant.package_id = assessment_case.package_id
                          AND assessment_case.origin = 'DIRECT_PUBLIC'
                    ) AND NOT EXISTS (
                        SELECT 1 FROM public.selection_participants selection_row
                        JOIN public.participants participant ON participant.id = NEW.participant_id
                        JOIN public.assessment_cases assessment_case ON assessment_case.id = selection_row.assessment_case_id
                        WHERE NEW.order_id IS NULL AND selection_row.participant_id = NEW.participant_id
                          AND selection_row.assessment_case_id = NEW.assessment_case_id
                          AND participant.source_system = 'SELEKSI_BEASISWA_JEPANG'
                          AND participant.branch_id = assessment_case.organization_id
                          AND participant.package_id IS NULL AND assessment_case.package_id IS NULL
                          AND assessment_case.origin = 'LEGACY_SELECTION'
                          AND (SELECT COUNT(*) FROM public.selection_participants candidate
                            JOIN public.participants candidate_participant ON candidate_participant.id = NEW.participant_id
                            JOIN public.assessment_cases candidate_case ON candidate_case.id = candidate.assessment_case_id
                            WHERE candidate.participant_id = NEW.participant_id
                              AND candidate_participant.source_system = 'SELEKSI_BEASISWA_JEPANG'
                              AND candidate_participant.branch_id = candidate_case.organization_id
                              AND candidate_participant.package_id IS NULL AND candidate_case.package_id IS NULL
                              AND candidate_case.origin = 'LEGACY_SELECTION') = 1
                    ) THEN
                        RAISE EXCEPTION 'Generic entitlement requires an exact case source graph' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END;
                $guard$;
                REVOKE ALL ON FUNCTION app_private.guard_generic_entitlement_case_identity() FROM PUBLIC, psikotes_runtime;
                CREATE TRIGGER entitlements_case_identity_guard
                BEFORE INSERT OR UPDATE ON entitlements FOR EACH ROW
                EXECUTE FUNCTION app_private.guard_generic_entitlement_case_identity();
                SQL);

            return;
        }

        $this->executeSql($this->sqliteInsertGuardSql());
        $this->executeSql($this->sqliteUpdateGuardSql());
    }

    private function sqliteInsertGuardSql(): string
    {
        return <<<'SQL'
            CREATE TRIGGER entitlements_case_insert_guard BEFORE INSERT ON entitlements FOR EACH ROW
            WHEN (NEW.test_type = 'dass21' AND NEW.assessment_case_id IS NOT NULL)
              OR (NEW.assessment_case_id IS NOT NULL AND NOT EXISTS (
                SELECT 1 FROM orders source_order
                JOIN participants participant ON participant.id = NEW.participant_id
                JOIN assessment_cases assessment_case ON assessment_case.id = source_order.assessment_case_id
                JOIN package_items package_item ON package_item.package_id = assessment_case.package_id AND package_item.test_type = NEW.test_type
                WHERE source_order.id = NEW.order_id AND source_order.participant_id = NEW.participant_id
                  AND source_order.assessment_case_id = NEW.assessment_case_id
                  AND participant.source_system = 'DIRECT_PUBLIC' AND participant.branch_id = assessment_case.organization_id
                  AND participant.package_id = assessment_case.package_id AND assessment_case.origin = 'DIRECT_PUBLIC'
              ) AND NOT EXISTS (
                SELECT 1 FROM selection_participants selection_row
                JOIN participants participant ON participant.id = NEW.participant_id
                JOIN assessment_cases assessment_case ON assessment_case.id = selection_row.assessment_case_id
                WHERE NEW.order_id IS NULL AND selection_row.participant_id = NEW.participant_id
                  AND selection_row.assessment_case_id = NEW.assessment_case_id
                  AND participant.source_system = 'SELEKSI_BEASISWA_JEPANG'
                  AND participant.branch_id = assessment_case.organization_id
                  AND participant.package_id IS NULL AND assessment_case.package_id IS NULL
                  AND assessment_case.origin = 'LEGACY_SELECTION'
                  AND (SELECT COUNT(*) FROM selection_participants candidate
                    JOIN participants candidate_participant ON candidate_participant.id = NEW.participant_id
                    JOIN assessment_cases candidate_case ON candidate_case.id = candidate.assessment_case_id
                    WHERE candidate.participant_id = NEW.participant_id
                      AND candidate_participant.source_system = 'SELEKSI_BEASISWA_JEPANG'
                      AND candidate_participant.branch_id = candidate_case.organization_id
                      AND candidate_participant.package_id IS NULL AND candidate_case.package_id IS NULL
                      AND candidate_case.origin = 'LEGACY_SELECTION') = 1
              ))
            BEGIN SELECT RAISE(ABORT, 'generic entitlement requires an exact case source graph'); END
            SQL;
    }

    private function sqliteUpdateGuardSql(): string
    {
        return str_replace(
            'CREATE TRIGGER entitlements_case_insert_guard BEFORE INSERT',
            'CREATE TRIGGER entitlements_case_update_guard BEFORE UPDATE',
            str_replace(
                'WHEN (NEW.test_type',
                'WHEN (OLD.assessment_case_id IS NOT NULL AND NEW.assessment_case_id IS NOT OLD.assessment_case_id) OR (NEW.test_type',
                $this->sqliteInsertGuardSql(),
            ),
        );
    }

    private function removeEnforcement(string $driver): void
    {
        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS entitlements_case_identity_guard ON entitlements');
            DB::unprepared('DROP FUNCTION IF EXISTS app_private.guard_generic_entitlement_case_identity()');
        } else {
            DB::unprepared('DROP TRIGGER IF EXISTS entitlements_case_insert_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS entitlements_case_update_guard');
        }
        DB::statement('DROP INDEX IF EXISTS '.self::CASE_UNIQUE);
        DB::statement('DROP INDEX IF EXISTS '.self::GRANT_SCOPE_UNIQUE);
        $this->withoutSqliteReferencingTriggers($driver, function () use ($driver): void {
            Schema::table('entitlements', function (Blueprint $table) use ($driver): void {
                $table->dropForeign($driver === 'sqlite'
                    ? ['assessment_case_id', 'participant_id']
                    : self::CASE_FOREIGN);
            });
        });
    }

    private function withoutSqliteReferencingTriggers(string $driver, callable $operation): void
    {
        if ($driver !== 'sqlite') {
            $operation();

            return;
        }

        $triggers = DB::select(<<<'SQL'
            SELECT name, sql FROM sqlite_master
            WHERE type = 'trigger' AND tbl_name <> 'entitlements'
              AND lower(sql) LIKE '%entitlements%'
            ORDER BY name
            SQL);
        foreach ($triggers as $trigger) {
            $name = data_get($trigger, 'name');
            if (! is_string($name)) {
                throw new RuntimeException('Invalid entitlement-referencing trigger metadata.');
            }
            $this->executeSql('DROP TRIGGER "'.str_replace('"', '""', $name).'"');
        }
        try {
            $operation();
        } finally {
            foreach ($triggers as $trigger) {
                $sql = data_get($trigger, 'sql');
                if (! is_string($sql) || DB::connection()->getPdo()->exec($sql) === false) {
                    throw new RuntimeException('Failed to restore an entitlement-referencing trigger.');
                }
            }
        }
    }

    private function assertNoPartialState(string $driver): void
    {
        $present = $driver === 'pgsql'
            ? (bool) DB::scalar("SELECT EXISTS (SELECT 1 FROM pg_class WHERE relname IN ('".self::CASE_UNIQUE."','".self::GRANT_SCOPE_UNIQUE."','entitlements_case_identity_guard'))")
            : (bool) DB::scalar("SELECT EXISTS (SELECT 1 FROM sqlite_master WHERE name IN ('".self::CASE_UNIQUE."','".self::GRANT_SCOPE_UNIQUE."','entitlements_case_insert_guard','entitlements_case_update_guard'))");
        if ($present) {
            $this->abort('partial enforcement already exists');
        }
    }

    private function assertExactState(string $driver): void
    {
        if ($driver === 'pgsql') {
            $this->assertExactPostgresState();

            return;
        }

        $indexes = collect(DB::select("SELECT name,sql FROM sqlite_master WHERE type='index' AND tbl_name='entitlements' AND sql IS NOT NULL"))
            ->mapWithKeys(function (object $row): array {
                $data = (array) $row;

                return [(string) $data['name'] => $this->normalizeSql((string) $data['sql'])];
            });
        $foreign = collect(DB::select("PRAGMA foreign_key_list('entitlements')"))
            ->map(fn (object $row): array => (array) $row)
            ->filter(fn (array $row): bool => $row['table'] === 'assessment_cases')->sortBy('seq')->values();
        $triggers = collect(DB::select("SELECT name,sql FROM sqlite_master WHERE type='trigger' AND tbl_name='entitlements'"))
            ->mapWithKeys(function (object $row): array {
                $data = (array) $row;

                return [(string) $data['name'] => $this->normalizeSql((string) $data['sql'])];
            })->all();
        if (($indexes[self::CASE_UNIQUE] ?? null) !== $this->normalizeSql('CREATE UNIQUE INDEX '.self::CASE_UNIQUE.' ON entitlements (assessment_case_id, test_type) WHERE assessment_case_id IS NOT NULL')
            || ($indexes[self::GRANT_SCOPE_UNIQUE] ?? null) !== $this->normalizeSql('CREATE UNIQUE INDEX '.self::GRANT_SCOPE_UNIQUE.' ON entitlements (id, assessment_case_id, participant_id, test_type)')
            || $foreign->pluck('from')->all() !== ['assessment_case_id', 'participant_id']
            || $foreign->pluck('to')->all() !== ['id', 'participant_id']
            || $foreign->pluck('on_update')->all() !== ['NO ACTION', 'NO ACTION']
            || $foreign->pluck('on_delete')->all() !== ['RESTRICT', 'RESTRICT']
            || $foreign->pluck('match')->all() !== ['NONE', 'NONE']
            || ($triggers['entitlements_case_insert_guard'] ?? null) !== $this->normalizeSql($this->sqliteInsertGuardSql())
            || ($triggers['entitlements_case_update_guard'] ?? null) !== $this->normalizeSql($this->sqliteUpdateGuardSql())
            || count($triggers) !== 2) {
            $this->abort('partial SQLite enforcement already exists');
        }
    }

    private function assertExactPostgresState(): void
    {
        $indexes = collect(DB::select("SELECT indexname,indexdef FROM pg_indexes WHERE schemaname='public' AND tablename='entitlements' AND indexname IN (?,?)", [self::CASE_UNIQUE, self::GRANT_SCOPE_UNIQUE]))
            ->mapWithKeys(function (object $row): array {
                $data = (array) $row;

                return [(string) $data['indexname'] => $this->normalizeSql((string) $data['indexdef'])];
            })->all();
        $expectedIndexes = [
            self::CASE_UNIQUE => $this->normalizeSql('CREATE UNIQUE INDEX '.self::CASE_UNIQUE.' ON public.entitlements USING btree (assessment_case_id, test_type) WHERE (assessment_case_id IS NOT NULL)'),
            self::GRANT_SCOPE_UNIQUE => $this->normalizeSql('CREATE UNIQUE INDEX '.self::GRANT_SCOPE_UNIQUE.' ON public.entitlements USING btree (id, assessment_case_id, participant_id, test_type)'),
        ];
        ksort($indexes);
        ksort($expectedIndexes);
        $foreign = DB::selectOne("SELECT convalidated,condeferrable,condeferred,confupdtype,confdeltype,pg_get_constraintdef(oid,false) definition FROM pg_constraint WHERE conrelid='entitlements'::regclass AND conname=?", [self::CASE_FOREIGN]);
        $security = DB::selectOne("SELECT relrowsecurity,relforcerowsecurity,pg_get_userbyid(relowner) owner,current_user expected_owner FROM pg_class WHERE oid='entitlements'::regclass");
        $triggers = DB::select(<<<'SQL'
            SELECT trigger.tgtype,trigger.tgenabled,namespace.nspname,function.proname,function.prosecdef,
                   function.proconfig,language.lanname,function.prosrc,
                   pg_get_userbyid(function.proowner) function_owner,current_user expected_owner,
                   pg_get_triggerdef(trigger.oid,false) trigger_definition
            FROM pg_trigger trigger JOIN pg_proc function ON function.oid=trigger.tgfoid
            JOIN pg_namespace namespace ON namespace.oid=function.pronamespace
            JOIN pg_language language ON language.oid=function.prolang
            WHERE trigger.tgrelid='entitlements'::regclass AND NOT trigger.tgisinternal
            ORDER BY trigger.tgname
            SQL);
        $trigger = count($triggers) === 1 ? (array) $triggers[0] : [];
        $policies = collect(DB::select(<<<'SQL'
            SELECT schemaname,tablename,policyname,permissive,roles,cmd,qual,with_check
            FROM pg_policies WHERE schemaname='public' AND tablename='entitlements' ORDER BY policyname
            SQL))->map(function (object $row): array {
            $data = (array) $row;
            foreach (['qual', 'with_check'] as $key) {
                $data[$key] = is_string($data[$key]) ? $this->normalizeSql($data[$key]) : null;
            }

            return $data;
        })->all();
        $privileges = collect(DB::select(<<<'SQL'
            SELECT CASE WHEN acl.grantee=0 THEN 'PUBLIC' ELSE pg_get_userbyid(acl.grantee) END grantee,
                   acl.privilege_type,acl.is_grantable
            FROM pg_class class
            CROSS JOIN LATERAL aclexplode(COALESCE(class.relacl,acldefault('r',class.relowner))) acl
            WHERE class.oid='entitlements'::regclass AND acl.grantee <> class.relowner
            ORDER BY grantee,privilege_type
            SQL))->map(fn (object $row): array => array_values((array) $row))->all();
        $functionPrivileges = DB::select(<<<'SQL'
            SELECT acl.privilege_type
            FROM pg_proc function
            JOIN pg_namespace namespace ON namespace.oid=function.pronamespace
            CROSS JOIN LATERAL aclexplode(COALESCE(function.proacl,acldefault('f',function.proowner))) acl
            WHERE namespace.nspname='app_private' AND function.proname='guard_generic_entitlement_case_identity'
              AND acl.grantee <> function.proowner
            SQL);
        $foreignData = $foreign === null ? [] : (array) $foreign;
        $securityData = $security === null ? [] : (array) $security;
        if ($indexes !== $expectedIndexes || $foreign === null || ! $foreignData['convalidated'] || $foreignData['condeferrable'] || $foreignData['condeferred']
            || (string) $foreignData['confupdtype'] !== 'a' || (string) $foreignData['confdeltype'] !== 'r'
            || (string) $foreignData['definition'] !== 'FOREIGN KEY (assessment_case_id, participant_id) REFERENCES assessment_cases(id, participant_id) ON DELETE RESTRICT'
            || $security === null || ! $securityData['relrowsecurity'] || ! $securityData['relforcerowsecurity']
            || (string) $securityData['owner'] !== (string) $securityData['expected_owner']
            || $trigger === [] || (int) $trigger['tgtype'] !== 23 || (string) $trigger['tgenabled'] !== 'O'
            || (string) $trigger['nspname'] !== 'app_private' || (string) $trigger['proname'] !== 'guard_generic_entitlement_case_identity'
            || ! $trigger['prosecdef'] || (string) $trigger['lanname'] !== 'plpgsql'
            || (string) $trigger['proconfig'] !== '{"search_path=pg_catalog, public"}'
            || (string) $trigger['function_owner'] !== (string) $trigger['expected_owner']
            || hash('sha256', $this->normalizeSql((string) $trigger['prosrc'])) !== self::POSTGRES_GUARD_BODY_SHA256
            || $this->normalizeSql((string) $trigger['trigger_definition']) !== $this->normalizeSql('CREATE TRIGGER entitlements_case_identity_guard BEFORE INSERT OR UPDATE ON public.entitlements FOR EACH ROW EXECUTE FUNCTION app_private.guard_generic_entitlement_case_identity()')
            || count($policies) !== 2 || array_column($policies, 'policyname') !== ['entitlements_read', 'entitlements_write']
            || array_column($policies, 'permissive') !== ['PERMISSIVE', 'PERMISSIVE']
            || array_column($policies, 'roles') !== ['{psikotes_runtime}', '{psikotes_runtime}']
            || array_column($policies, 'cmd') !== ['SELECT', 'ALL']
            || hash('sha256', json_encode($policies, JSON_THROW_ON_ERROR)) !== self::POSTGRES_POLICIES_SHA256
            || $privileges !== [
                ['psikotes_runtime', 'DELETE', false], ['psikotes_runtime', 'INSERT', false],
                ['psikotes_runtime', 'SELECT', false], ['psikotes_runtime', 'UPDATE', false],
            ] || $functionPrivileges !== []) {
            $this->abort('partial PostgreSQL enforcement already exists');
        }
    }

    private function normalizeSql(string $sql): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }

    private function lockPostgresTables(): void
    {
        foreach (['participants', 'packages', 'package_items', 'assessment_cases', 'orders', 'selection_participants', 'entitlements'] as $table) {
            DB::statement("LOCK TABLE {$table} IN ACCESS EXCLUSIVE MODE");
        }
    }

    private function forcePostgresRls(bool $force): void
    {
        $mode = $force ? 'FORCE' : 'NO FORCE';
        foreach (['participants', 'packages', 'package_items', 'assessment_cases', 'orders', 'selection_participants', 'entitlements'] as $table) {
            DB::statement("ALTER TABLE {$table} {$mode} ROW LEVEL SECURITY");
        }
    }

    /** @return array<string,bool> */
    private function postgresForceRlsState(): array
    {
        return collect(DB::select(<<<'SQL'
            SELECT relname,relforcerowsecurity
            FROM pg_class
            WHERE oid IN ('participants'::regclass,'packages'::regclass,'package_items'::regclass,
                'assessment_cases'::regclass,'orders'::regclass,'selection_participants'::regclass,
                'entitlements'::regclass)
            ORDER BY relname
            SQL))->mapWithKeys(function (object $row): array {
            $data = (array) $row;

            return [(string) $data['relname'] => (bool) $data['relforcerowsecurity']];
        })->all();
    }

    /** @param array<string,bool> $state */
    private function restorePostgresForceRls(array $state): void
    {
        foreach ($state as $table => $force) {
            $mode = $force ? 'FORCE' : 'NO FORCE';
            DB::statement("ALTER TABLE {$table} {$mode} ROW LEVEL SECURITY");
        }
    }

    private function executeSql(string $sql): void
    {
        if (DB::connection()->getPdo()->exec($sql) === false) {
            throw new RuntimeException('Failed to install generic entitlement case enforcement.');
        }
    }

    private function transactional(Closure $operation): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::transaction(fn (): mixed => $operation());

            return;
        }
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('SQLite generic entitlement case migration requires no surrounding transaction.');
        }
        $foreignKeys = (bool) DB::scalar('PRAGMA foreign_keys');
        if ($foreignKeys) {
            Schema::disableForeignKeyConstraints();
        }
        try {
            DB::transaction(function () use ($operation): void {
                $operation();
                if (DB::select('PRAGMA foreign_key_check') !== []) {
                    throw new RuntimeException('Generic entitlement case migration would violate existing foreign keys.');
                }
            });
        } finally {
            if ($foreignKeys) {
                Schema::enableForeignKeyConstraints();
            }
        }
    }

    private function abort(string $reason): never
    {
        throw new RuntimeException('Generic entitlement case migration aborted: '.$reason);
    }
};
