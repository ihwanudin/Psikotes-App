<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CONSTRAINT = 'entitlements_case_requirement_check';

    private const EXPRESSION = "(test_type = 'dass21' AND assessment_case_id IS NULL) OR (test_type <> 'dass21' AND assessment_case_id IS NOT NULL)";

    private const POSTGRES_BASE_GUARD_SHA256 = 'e3fd1d0b20d1cc07adee61e531bc85f63062e896b1fe14f7a783b7329c067d64';

    public function up(): void
    {
        $driver = DB::getDriverName();
        $requirement = $this->requirementState($driver);
        $guard = $this->guardState($driver);
        if ($requirement === 'exact' && $guard === 'upgraded') {
            return;
        }
        if ($requirement !== 'absent' || $guard !== 'base') {
            $this->abort('counterfeit or partial requirement and guard state');
        }
        $this->assertBaseState();
        $this->wrap(function (): void {
            $driver = DB::getDriverName();
            $state = $this->requirementState($driver);
            if ($state === 'counterfeit') {
                $this->abort('counterfeit or partial requirement constraint');
            }
            if ($state === 'exact') {
                return;
            }
            $this->assertHistoryCompatible();
            if ($driver === 'pgsql') {
                $force = $this->postgresForceRls('entitlements');
                DB::statement('LOCK TABLE entitlements IN ACCESS EXCLUSIVE MODE');
                try {
                    DB::statement('ALTER TABLE entitlements NO FORCE ROW LEVEL SECURITY');
                    $this->assertHistoryCompatible();
                    $this->upgradeGuard($driver);
                    DB::statement('ALTER TABLE entitlements ADD CONSTRAINT '.self::CONSTRAINT.' CHECK ('.self::EXPRESSION.') NOT VALID');
                    DB::statement('ALTER TABLE entitlements VALIDATE CONSTRAINT '.self::CONSTRAINT);
                } finally {
                    DB::statement('ALTER TABLE entitlements '.($force ? 'FORCE' : 'NO FORCE').' ROW LEVEL SECURITY');
                }
            } else {
                $this->upgradeGuard($driver);
                $this->rebuildSqlite(true);
            }
            if ($this->requirementState($driver) !== 'exact' || $this->guardState($driver) !== 'upgraded') {
                $this->abort('requirement constraint was not installed exactly');
            }
        });
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        $requirement = $this->requirementState($driver);
        $guard = $this->guardState($driver);
        if ($requirement === 'absent' && $guard === 'base') {
            $this->assertBaseState();

            return;
        }
        if ($requirement !== 'exact' || $guard !== 'upgraded') {
            $this->abort('counterfeit or partial requirement and guard state');
        }
        $this->wrap(function (): void {
            $driver = DB::getDriverName();
            $state = $this->requirementState($driver);
            if ($state === 'counterfeit') {
                $this->abort('counterfeit or partial requirement constraint');
            }
            if ($state === 'absent') {
                return;
            }
            if ($driver === 'pgsql') {
                $caseForce = $this->postgresForceRls('assessment_cases');
                $force = $this->postgresForceRls('entitlements');
                DB::statement('LOCK TABLE assessment_cases IN ACCESS EXCLUSIVE MODE');
                DB::statement('LOCK TABLE entitlements IN ACCESS EXCLUSIVE MODE');
                try {
                    DB::statement('ALTER TABLE assessment_cases NO FORCE ROW LEVEL SECURITY');
                    DB::statement('ALTER TABLE entitlements NO FORCE ROW LEVEL SECURITY');
                    $this->assertNoIntegratedDependents();
                    DB::statement('ALTER TABLE entitlements DROP CONSTRAINT '.self::CONSTRAINT);
                    $this->restoreBaseGuard($driver);
                } finally {
                    DB::statement('ALTER TABLE entitlements '.($force ? 'FORCE' : 'NO FORCE').' ROW LEVEL SECURITY');
                    DB::statement('ALTER TABLE assessment_cases '.($caseForce ? 'FORCE' : 'NO FORCE').' ROW LEVEL SECURITY');
                }
            } else {
                $this->assertNoIntegratedDependents();
                $this->rebuildSqlite(false);
                $this->restoreBaseGuard($driver);
            }
            if ($this->requirementState($driver) !== 'absent' || $this->guardState($driver) !== 'base') {
                $this->abort('requirement constraint rollback was incomplete');
            }
        });
        $this->assertBaseState();
    }

    private function assertHistoryCompatible(): void
    {
        if (DB::table('entitlements')->where('test_type', 'dass21')->whereNotNull('assessment_case_id')->exists()
            || DB::table('entitlements')->where('test_type', '<>', 'dass21')->whereNull('assessment_case_id')->exists()) {
            $this->abort('historical entitlement case requirements are violated');
        }
    }

    private function assertNoIntegratedDependents(): void
    {
        if (DB::table('entitlements as entitlement')
            ->join('assessment_cases as assessment_case', 'assessment_case.id', '=', 'entitlement.assessment_case_id')
            ->where('assessment_case.origin', 'INTEGRATED')
            ->where('entitlement.test_type', '<>', 'dass21')
            ->exists()) {
            $this->abort('integrated entitlement history prevents guard rollback');
        }
    }

    private function assertBaseState(): void
    {
        $migration = require __DIR__.'/2026_09_10_000300_expand_generic_entitlement_case_identity.php';
        if (! $migration instanceof Migration) {
            $this->abort('base migration is unavailable');
        }
        (new ReflectionMethod($migration, 'up'))->invoke($migration);
    }

    /** @return 'absent'|'exact'|'counterfeit' */
    private function requirementState(string $driver): string
    {
        if ($driver === 'pgsql') {
            $rows = DB::select(<<<'SQL'
                SELECT conname, contype, convalidated, condeferrable, condeferred, connoinherit,
                       pg_get_constraintdef(oid, false) AS definition
                FROM pg_constraint
                WHERE conrelid = 'entitlements'::regclass
                  AND conname = ?
                SQL, [self::CONSTRAINT]);
            if ($rows === []) {
                return 'absent';
            }
            if (count($rows) !== 1) {
                return 'counterfeit';
            }
            $row = (array) $rows[0];

            return $row['contype'] === 'c' && (bool) $row['convalidated']
                && ! (bool) $row['condeferrable'] && ! (bool) $row['condeferred']
                && ! (bool) $row['connoinherit']
                && $this->normalizeCheck((string) $row['definition'])
                    === $this->normalizeCheck('CHECK ('.self::EXPRESSION.')')
                ? 'exact' : 'counterfeit';
        }

        $sql = DB::scalar("SELECT sql FROM sqlite_master WHERE type='table' AND name='entitlements'");
        if (! is_string($sql)) {
            return 'counterfeit';
        }
        $nameCount = substr_count(strtolower($sql), self::CONSTRAINT);
        if ($nameCount === 0) {
            return 'absent';
        }
        $exact = 'CONSTRAINT '.self::CONSTRAINT.' CHECK ('.self::EXPRESSION.')';

        return $nameCount === 1 && str_contains($this->normalizeSql($sql), $this->normalizeSql($exact))
            ? 'exact' : 'counterfeit';
    }

    /** @return 'base'|'upgraded'|'counterfeit' */
    private function guardState(string $driver): string
    {
        if ($driver === 'pgsql') {
            $rows = DB::select(<<<'SQL'
                SELECT trigger.tgtype,trigger.tgenabled,namespace.nspname,function.proname,function.prosecdef,
                       function.proconfig,language.lanname,function.prosrc,
                       pg_get_triggerdef(trigger.oid,false) trigger_definition
                FROM pg_trigger trigger JOIN pg_proc function ON function.oid=trigger.tgfoid
                JOIN pg_namespace namespace ON namespace.oid=function.pronamespace
                JOIN pg_language language ON language.oid=function.prolang
                WHERE trigger.tgrelid='entitlements'::regclass AND NOT trigger.tgisinternal
                  AND trigger.tgname='entitlements_case_identity_guard'
                SQL);
            if (count($rows) !== 1) {
                return 'counterfeit';
            }
            $guard = (array) $rows[0];
            if ((int) $guard['tgtype'] !== 23 || (string) $guard['tgenabled'] !== 'O'
                || (string) $guard['nspname'] !== 'app_private'
                || (string) $guard['proname'] !== 'guard_generic_entitlement_case_identity'
                || ! (bool) $guard['prosecdef'] || (string) $guard['lanname'] !== 'plpgsql'
                || (string) $guard['proconfig'] !== '{"search_path=pg_catalog, public"}'
                || $this->normalizeSql((string) $guard['trigger_definition']) !== $this->normalizeSql(
                    'CREATE TRIGGER entitlements_case_identity_guard BEFORE INSERT OR UPDATE ON public.entitlements FOR EACH ROW EXECUTE FUNCTION app_private.guard_generic_entitlement_case_identity()',
                )) {
                return 'counterfeit';
            }
            $body = (string) $guard['prosrc'];
            if (hash('sha256', $this->normalizeGuard($body)) === self::POSTGRES_BASE_GUARD_SHA256) {
                return 'base';
            }

            try {
                $base = $this->removePostgresIntegratedClause($body);
            } catch (RuntimeException) {
                return 'counterfeit';
            }

            return hash('sha256', $this->normalizeGuard($base)) === self::POSTGRES_BASE_GUARD_SHA256
                ? 'upgraded' : 'counterfeit';
        }

        $rows = collect(DB::select(<<<'SQL'
            SELECT name,sql FROM sqlite_master WHERE type='trigger'
              AND name IN ('entitlements_case_insert_guard','entitlements_case_update_guard')
            ORDER BY name
            SQL))->mapWithKeys(function (object $row): array {
            $data = (array) $row;

            return [(string) $data['name'] => (string) $data['sql']];
        })->all();
        if (count($rows) !== 2) {
            return 'counterfeit';
        }
        $baseInsert = $this->baseSqliteInsertGuardSql();
        $baseUpdate = $this->baseSqliteUpdateGuardSql();
        if ($this->normalizeSql($rows['entitlements_case_insert_guard'] ?? '') === $this->normalizeSql($baseInsert)
            && $this->normalizeSql($rows['entitlements_case_update_guard'] ?? '') === $this->normalizeSql($baseUpdate)) {
            return 'base';
        }

        return $this->normalizeSql($rows['entitlements_case_insert_guard'] ?? '') === $this->normalizeSql($this->addSqliteIntegratedClause($baseInsert))
            && $this->normalizeSql($rows['entitlements_case_update_guard'] ?? '') === $this->normalizeSql($this->addSqliteIntegratedClause($baseUpdate))
            ? 'upgraded' : 'counterfeit';
    }

    private function upgradeGuard(string $driver): void
    {
        if ($this->guardState($driver) !== 'base') {
            $this->abort('base entitlement case guard is unavailable');
        }
        if ($driver === 'pgsql') {
            $body = (string) DB::scalar(<<<'SQL'
                SELECT function.prosrc FROM pg_proc function
                JOIN pg_namespace namespace ON namespace.oid=function.pronamespace
                WHERE namespace.nspname='app_private'
                  AND function.proname='guard_generic_entitlement_case_identity'
            SQL);
            $upgraded = $this->addPostgresIntegratedClause($body);
            $this->executeSql(
                'CREATE OR REPLACE FUNCTION app_private.guard_generic_entitlement_case_identity() RETURNS trigger '
                .'LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $guard$'
                .$upgraded.'$guard$;',
            );

            return;
        }
        DB::unprepared('DROP TRIGGER entitlements_case_insert_guard');
        DB::unprepared('DROP TRIGGER entitlements_case_update_guard');
        $this->executeSql($this->addSqliteIntegratedClause($this->baseSqliteInsertGuardSql()));
        $this->executeSql($this->addSqliteIntegratedClause($this->baseSqliteUpdateGuardSql()));
    }

    private function restoreBaseGuard(string $driver): void
    {
        if ($this->guardState($driver) !== 'upgraded') {
            $this->abort('upgraded entitlement case guard is unavailable');
        }
        if ($driver === 'pgsql') {
            $body = (string) DB::scalar(<<<'SQL'
                SELECT function.prosrc FROM pg_proc function
                JOIN pg_namespace namespace ON namespace.oid=function.pronamespace
                WHERE namespace.nspname='app_private'
                  AND function.proname='guard_generic_entitlement_case_identity'
            SQL);
            $base = $this->removePostgresIntegratedClause($body);
            $this->executeSql(
                'CREATE OR REPLACE FUNCTION app_private.guard_generic_entitlement_case_identity() RETURNS trigger '
                .'LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $guard$'
                .$base.'$guard$;',
            );

            return;
        }
        DB::unprepared('DROP TRIGGER entitlements_case_insert_guard');
        DB::unprepared('DROP TRIGGER entitlements_case_update_guard');
        $this->executeSql($this->baseSqliteInsertGuardSql());
        $this->executeSql($this->baseSqliteUpdateGuardSql());
    }

    private function addPostgresIntegratedClause(string $body): string
    {
        $needle = "    ) THEN\n        RAISE EXCEPTION 'Generic entitlement requires an exact case source graph'";
        $replacement = '    ) AND NOT EXISTS ('."\n".$this->postgresIntegratedGraphSql()."\n".$needle;

        return $this->replaceExactlyOnce($body, $needle, $replacement);
    }

    private function removePostgresIntegratedClause(string $body): string
    {
        $needle = '    ) AND NOT EXISTS ('."\n".$this->postgresIntegratedGraphSql()."\n";

        return $this->replaceExactlyOnce($body, $needle, '');
    }

    private function addSqliteIntegratedClause(string $sql): string
    {
        $needle = "  ))\nBEGIN SELECT RAISE(ABORT, 'generic entitlement requires an exact case source graph'); END";
        $replacement = '  ) AND NOT EXISTS ('."\n".$this->sqliteIntegratedGraphSql()."\n".$needle;

        return $this->replaceExactlyOnce($sql, $needle, $replacement);
    }

    private function postgresIntegratedGraphSql(): string
    {
        return <<<'SQL'
                        SELECT 1 FROM public.assessment_cases assessment_case
                        JOIN public.participants participant ON participant.id = NEW.participant_id
                        JOIN public.package_items package_item ON package_item.package_id = assessment_case.package_id
                          AND package_item.test_type = NEW.test_type
                        WHERE NEW.order_id IS NULL AND assessment_case.id = NEW.assessment_case_id
                          AND assessment_case.participant_id = NEW.participant_id
                          AND participant.branch_id = assessment_case.organization_id
                          AND participant.package_id = assessment_case.package_id
                          AND assessment_case.origin = 'INTEGRATED'
                          AND (SELECT COUNT(*) FROM public.assessment_participants mapping
                            WHERE mapping.assessment_case_id = assessment_case.id
                              AND mapping.participant_id = NEW.participant_id
                              AND mapping.organization_id = assessment_case.organization_id
                              AND mapping.package_id = assessment_case.package_id
                              AND mapping.source_system = participant.source_system) = 1
            SQL;
    }

    private function sqliteIntegratedGraphSql(): string
    {
        return str_replace('public.', '', $this->postgresIntegratedGraphSql());
    }

    private function baseSqliteInsertGuardSql(): string
    {
        $migration = require __DIR__.'/2026_09_10_000300_expand_generic_entitlement_case_identity.php';
        $method = new ReflectionMethod($migration, 'sqliteInsertGuardSql');

        return (string) $method->invoke($migration);
    }

    private function baseSqliteUpdateGuardSql(): string
    {
        $migration = require __DIR__.'/2026_09_10_000300_expand_generic_entitlement_case_identity.php';
        $method = new ReflectionMethod($migration, 'sqliteUpdateGuardSql');

        return (string) $method->invoke($migration);
    }

    private function replaceExactlyOnce(string $subject, string $search, string $replacement): string
    {
        $result = str_replace($search, $replacement, $subject, $count);
        if ($count !== 1) {
            $this->abort('entitlement case guard shape is counterfeit');
        }

        return $result;
    }

    private function rebuildSqlite(bool $withRequirement): void
    {
        $objects = DB::select(<<<'SQL'
            SELECT type,name,sql FROM sqlite_master
            WHERE sql IS NOT NULL AND (
                (type = 'index' AND tbl_name = 'entitlements')
                OR (type = 'trigger' AND (tbl_name = 'entitlements'
                    OR (tbl_name <> 'entitlements' AND lower(sql) LIKE '%entitlements%')))
            )
            ORDER BY type,name
            SQL);
        foreach ($objects as $object) {
            $data = (array) $object;
            if ($data['type'] === 'trigger') {
                DB::statement('DROP TRIGGER "'.str_replace('"', '""', (string) $data['name']).'"');
            }
        }
        $requirement = $withRequirement
            ? ', CONSTRAINT '.self::CONSTRAINT.' CHECK ('.self::EXPRESSION.')'
            : '';
        DB::statement(<<<SQL
            CREATE TABLE entitlements_requirement_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                participant_id INTEGER NOT NULL,
                order_id INTEGER,
                test_type VARCHAR NOT NULL,
                status VARCHAR NOT NULL DEFAULT ('locked'),
                ready_at DATETIME,
                started_at DATETIME,
                completed_at DATETIME,
                created_at DATETIME,
                updated_at DATETIME,
                assessment_case_id INTEGER,
                FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE SET NULL ON UPDATE NO ACTION,
                FOREIGN KEY (participant_id) REFERENCES participants (id) ON DELETE CASCADE ON UPDATE NO ACTION,
                FOREIGN KEY (assessment_case_id, participant_id) REFERENCES assessment_cases (id, participant_id) ON DELETE RESTRICT
                {$requirement}
            )
            SQL);
        DB::statement(<<<'SQL'
            INSERT INTO entitlements_requirement_new
                (id,participant_id,order_id,test_type,status,ready_at,started_at,completed_at,created_at,updated_at,assessment_case_id)
            SELECT id,participant_id,order_id,test_type,status,ready_at,started_at,completed_at,created_at,updated_at,assessment_case_id
            FROM entitlements
            SQL);
        DB::statement('DROP TABLE entitlements');
        DB::statement('ALTER TABLE entitlements_requirement_new RENAME TO entitlements');
        foreach ($objects as $object) {
            $sql = data_get($object, 'sql');
            if (! is_string($sql) || DB::connection()->getPdo()->exec($sql) === false) {
                $this->abort('SQLite schema object restoration failed');
            }
        }
    }

    private function postgresForceRls(string $table): bool
    {
        if (! in_array($table, ['assessment_cases', 'entitlements'], true)) {
            $this->abort('PostgreSQL RLS table is unsupported');
        }
        $row = DB::selectOne("SELECT relforcerowsecurity FROM pg_class WHERE oid='{$table}'::regclass");
        if ($row === null) {
            $this->abort('PostgreSQL entitlement RLS state is unavailable');
        }

        return (bool) $row->relforcerowsecurity;
    }

    private function normalizeCheck(string $sql): string
    {
        $sql = strtolower(str_replace(['::text', '::character varying', '(', ')'], '', $sql));

        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }

    private function normalizeSql(string $sql): string
    {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', $sql)));
    }

    private function normalizeGuard(string $sql): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }

    private function executeSql(string $sql): void
    {
        if (DB::connection()->getPdo()->exec($sql) === false) {
            $this->abort('entitlement case guard statement failed');
        }
    }

    private function wrap(Closure $operation): void
    {
        try {
            if (DB::getDriverName() !== 'sqlite') {
                DB::transaction(fn (): mixed => $operation());

                return;
            }
            if (DB::transactionLevel() !== 0) {
                $this->abort('SQLite migration requires no surrounding transaction');
            }
            $foreignKeys = (bool) DB::scalar('PRAGMA foreign_keys');
            if ($foreignKeys) {
                Schema::disableForeignKeyConstraints();
            }
            try {
                DB::transaction(function () use ($operation): void {
                    $operation();
                    if (DB::select('PRAGMA foreign_key_check') !== []) {
                        $this->abort('SQLite foreign key check failed');
                    }
                });
            } finally {
                if ($foreignKeys) {
                    Schema::enableForeignKeyConstraints();
                }
            }
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException
                && str_starts_with($exception->getMessage(), 'Generic entitlement case requirement migration aborted:')) {
                throw $exception;
            }
            throw new RuntimeException(
                'Generic entitlement case requirement migration aborted: '.$exception->getMessage(),
                (int) $exception->getCode(),
                $exception,
            );
        }
    }

    private function abort(string $reason): never
    {
        throw new RuntimeException('Generic entitlement case requirement migration aborted: '.$reason);
    }
};
