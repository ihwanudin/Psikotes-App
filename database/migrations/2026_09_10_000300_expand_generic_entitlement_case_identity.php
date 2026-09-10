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

    public function up(): void
    {
        try {
            DB::transaction(function (): void {
                $driver = DB::getDriverName();
                if ($driver === 'pgsql') {
                    $this->lockPostgresTables();
                    $this->forcePostgresRls(false);
                }

                if (Schema::hasColumn('entitlements', 'assessment_case_id')) {
                    if ($driver === 'pgsql') {
                        $this->forcePostgresRls(true);
                    }
                    $this->assertExactState($driver);

                    return;
                }
                $this->assertNoPartialState($driver);
                $this->assertExactHistoricalSources();

                Schema::table('entitlements', function (Blueprint $table): void {
                    $table->unsignedBigInteger('assessment_case_id')->nullable();
                });
                $this->backfill();
                $this->assertBackfillComplete();
                $this->enforce($driver);

                if ($driver === 'pgsql') {
                    $this->forcePostgresRls(true);
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
        DB::transaction(function (): void {
            $driver = DB::getDriverName();
            if (! Schema::hasColumn('entitlements', 'assessment_case_id')) {
                $this->assertNoPartialState($driver);

                return;
            }
            if ($driver === 'pgsql') {
                $this->lockPostgresTables();
                $this->forcePostgresRls(false);
            }
            if (DB::table('entitlements')->whereNotNull('assessment_case_id')->exists()) {
                throw new RuntimeException('Generic entitlement case history prevents rollback.');
            }

            $this->removeEnforcement($driver);
            $this->withoutSqliteReferencingTriggers($driver, function (): void {
                Schema::table('entitlements', function (Blueprint $table): void {
                    $table->dropColumn('assessment_case_id');
                });
            });
            if ($driver === 'pgsql') {
                $this->forcePostgresRls(true);
            }
        });
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
        $indexes = $driver === 'pgsql'
            ? collect(DB::select("SELECT indexname AS name,indexdef AS sql FROM pg_indexes WHERE schemaname='public' AND tablename='entitlements'"))
            : collect(DB::select("SELECT name,sql FROM sqlite_master WHERE type='index' AND tbl_name='entitlements'"));
        foreach ([self::CASE_UNIQUE, self::GRANT_SCOPE_UNIQUE] as $name) {
            if (! $indexes->contains(fn (object $row): bool => data_get($row, 'name') === $name)) {
                $this->abort('partial enforcement already exists');
            }
        }
        if ($driver === 'pgsql') {
            $foreign = DB::selectOne("SELECT pg_get_constraintdef(oid,false) definition FROM pg_constraint WHERE conrelid='entitlements'::regclass AND conname=?", [self::CASE_FOREIGN]);
            $trigger = DB::selectOne("SELECT 1 present FROM pg_trigger WHERE tgrelid='entitlements'::regclass AND tgname='entitlements_case_identity_guard' AND NOT tgisinternal");
            $security = DB::selectOne("SELECT relrowsecurity,relforcerowsecurity FROM pg_class WHERE oid='entitlements'::regclass");
            if ($foreign === null || $foreign->definition !== 'FOREIGN KEY (assessment_case_id, participant_id) REFERENCES assessment_cases(id, participant_id) ON DELETE RESTRICT'
                || $trigger === null || ! $security->relrowsecurity || ! $security->relforcerowsecurity) {
                $this->abort('partial PostgreSQL enforcement already exists');
            }
        } else {
            $foreign = collect(DB::select("PRAGMA foreign_key_list('entitlements')"))
                ->filter(fn (object $row): bool => data_get($row, 'table') === 'assessment_cases')->sortBy('seq')->values();
            $triggers = collect(DB::select("SELECT name FROM sqlite_master WHERE type='trigger' AND tbl_name='entitlements'"))->pluck('name');
            if ($foreign->pluck('from')->all() !== ['assessment_case_id', 'participant_id']
                || $foreign->pluck('to')->all() !== ['id', 'participant_id']
                || ! $triggers->contains('entitlements_case_insert_guard')
                || ! $triggers->contains('entitlements_case_update_guard')) {
                $this->abort('partial SQLite enforcement already exists');
            }
        }
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

    private function executeSql(string $sql): void
    {
        if (DB::connection()->getPdo()->exec($sql) === false) {
            throw new RuntimeException('Failed to install generic entitlement case enforcement.');
        }
    }

    private function abort(string $reason): never
    {
        throw new RuntimeException('Generic entitlement case migration aborted: '.$reason);
    }
};
