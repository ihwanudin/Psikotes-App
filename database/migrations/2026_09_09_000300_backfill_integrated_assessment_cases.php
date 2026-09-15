<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SCOPE_UNIQUE = 'assessment_cases_integrated_scope_unique';

    private const SCOPE_FOREIGN = 'assessment_participants_case_scope_fk';

    public function up(): void
    {
        try {
            $this->transactional(function (): void {
                $driver = DB::getDriverName();
                if ($driver === 'pgsql') {
                    DB::statement('LOCK TABLE assessment_cases IN ACCESS EXCLUSIVE MODE');
                    DB::statement('LOCK TABLE assessment_participants IN ACCESS EXCLUSIVE MODE');
                    DB::statement('ALTER TABLE assessment_cases NO FORCE ROW LEVEL SECURITY');
                    DB::statement('ALTER TABLE assessment_participants NO FORCE ROW LEVEL SECURITY');
                }

                $this->preflight();
                $this->backfill($driver);
                $this->assertComplete();
                $this->enforce($driver);

                if ($driver === 'pgsql') {
                    DB::statement('ALTER TABLE assessment_participants FORCE ROW LEVEL SECURITY');
                    DB::statement('ALTER TABLE assessment_cases FORCE ROW LEVEL SECURITY');
                }
            });
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException
                && str_starts_with($exception->getMessage(), 'Integrated assessment case backfill aborted:')) {
                throw $exception;
            }

            throw new RuntimeException(
                'Integrated assessment case backfill aborted: '.$exception->getMessage(),
                (int) $exception->getCode(),
                $exception,
            );
        }
    }

    public function down(): void
    {
        $this->transactional(function (): void {
            $driver = DB::getDriverName();
            if ($driver === 'pgsql') {
                DB::statement('LOCK TABLE assessment_cases IN ACCESS EXCLUSIVE MODE');
                DB::statement('LOCK TABLE assessment_participants IN ACCESS EXCLUSIVE MODE');
                DB::statement('ALTER TABLE assessment_participants NO FORCE ROW LEVEL SECURITY');
            }

            if (DB::table('assessment_participants')->exists()) {
                throw new RuntimeException('Integrated assessment case history prevents rollback.');
            }

            $this->removeEnforcement($driver);

            if ($driver === 'pgsql') {
                DB::statement('ALTER TABLE assessment_participants FORCE ROW LEVEL SECURITY');
            }
        });
    }

    private function preflight(): void
    {
        $missingTimestamps = DB::table('assessment_participants')
            ->whereNull('created_at')->orWhereNull('updated_at')->exists();
        if ($missingTimestamps) {
            $this->abort('assessment participant timestamps are required');
        }

        $distinct = DB::getDriverName() === 'pgsql' ? 'IS DISTINCT FROM' : 'IS NOT';
        $invalidBound = DB::selectOne(<<<SQL
            SELECT EXISTS (
                SELECT 1
                FROM assessment_participants ap
                LEFT JOIN assessment_cases ac ON ac.id = ap.assessment_case_id
                WHERE ap.assessment_case_id IS NOT NULL
                  AND (
                    ac.id IS NULL OR ac.public_id {$distinct} ap.assessment_attempt_id
                    OR ac.participant_id {$distinct} ap.participant_id
                    OR ac.organization_id {$distinct} ap.organization_id
                    OR ac.package_id {$distinct} ap.package_id
                    OR ac.origin {$distinct} 'INTEGRATED'
                  )
            ) AS invalid
            SQL)->invalid;
        if ($invalidBound) {
            $this->abort('an existing binding does not match its integrated case');
        }

        $publicIdCollision = DB::table('assessment_participants as ap')
            ->join('assessment_cases as ac', 'ac.public_id', '=', 'ap.assessment_attempt_id')
            ->whereNull('ap.assessment_case_id')->exists();
        if ($publicIdCollision) {
            $this->abort('an unbound attempt public id already exists');
        }
    }

    private function backfill(string $driver): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO assessment_cases (
                public_id, participant_id, organization_id, package_id, origin,
                intended_field_snapshot, created_at, updated_at
            )
            SELECT
                assessment_attempt_id, participant_id, organization_id, package_id, 'INTEGRATED',
                NULL, created_at, created_at
            FROM assessment_participants
            WHERE assessment_case_id IS NULL
            ORDER BY id
            SQL);

        if ($driver === 'pgsql') {
            DB::statement(<<<'SQL'
                UPDATE assessment_participants AS ap
                SET assessment_case_id = ac.id
                FROM assessment_cases AS ac
                WHERE ap.assessment_case_id IS NULL
                  AND ac.public_id = ap.assessment_attempt_id
                SQL);

            return;
        }

        DB::statement(<<<'SQL'
            UPDATE assessment_participants
            SET assessment_case_id = (
                SELECT id FROM assessment_cases
                WHERE public_id = assessment_participants.assessment_attempt_id
            )
            WHERE assessment_case_id IS NULL
            SQL);
    }

    private function assertComplete(): void
    {
        if (DB::table('assessment_participants')->whereNull('assessment_case_id')->exists()) {
            $this->abort('not every integrated attempt was bound');
        }

        $this->preflight();
    }

    private function enforce(string $driver): void
    {
        if ($driver === 'pgsql') {
            $state = $this->postgresEnforcementState();
            if ($state['exact']) {
                return;
            }
            if ($state['present']) {
                $this->abort('partial PostgreSQL enforcement already exists');
            }

            DB::statement('ALTER TABLE assessment_cases ADD CONSTRAINT '.self::SCOPE_UNIQUE.' UNIQUE (id, public_id, participant_id, organization_id, package_id)');
            DB::statement('ALTER TABLE assessment_participants ADD CONSTRAINT '.self::SCOPE_FOREIGN.' FOREIGN KEY (assessment_case_id, assessment_attempt_id, participant_id, organization_id, package_id) REFERENCES assessment_cases (id, public_id, participant_id, organization_id, package_id) ON DELETE RESTRICT');
            DB::statement('ALTER TABLE assessment_participants ALTER COLUMN assessment_case_id SET NOT NULL');
            $this->addPostgresGuard();

            return;
        }

        $sqliteState = $this->sqliteEnforcementState();
        if ($sqliteState['exact']) {
            return;
        }
        if ($sqliteState['present']) {
            $this->abort('partial SQLite enforcement already exists');
        }

        $triggers = $this->sqliteParticipantTriggers();
        Schema::table('assessment_cases', function (Blueprint $table): void {
            $table->unique(
                ['id', 'public_id', 'participant_id', 'organization_id', 'package_id'],
                self::SCOPE_UNIQUE,
            );
        });
        $this->withoutSqliteReferencingTriggers($driver, function (): void {
            Schema::table('assessment_participants', function (Blueprint $table): void {
                $table->foreign(
                    ['assessment_case_id', 'assessment_attempt_id', 'participant_id', 'organization_id', 'package_id'],
                )->references(['id', 'public_id', 'participant_id', 'organization_id', 'package_id'])
                    ->on('assessment_cases')->restrictOnDelete();
                $table->unsignedBigInteger('assessment_case_id')->nullable(false)->change();
            });
        });
        $this->restoreSqliteTriggers($triggers);
        $this->addSqliteGuards();
    }

    private function removeEnforcement(string $driver): void
    {
        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS assessment_participants_case_identity_guard ON assessment_participants');
            DB::unprepared('DROP FUNCTION IF EXISTS app_private.guard_assessment_participant_case_identity()');
            DB::statement('ALTER TABLE assessment_participants ALTER COLUMN assessment_case_id DROP NOT NULL');
            DB::statement('ALTER TABLE assessment_participants DROP CONSTRAINT IF EXISTS '.self::SCOPE_FOREIGN);
            DB::statement('ALTER TABLE assessment_cases DROP CONSTRAINT IF EXISTS '.self::SCOPE_UNIQUE);

            return;
        }

        $triggers = $this->sqliteParticipantTriggers([
            'assessment_participants_case_insert_guard',
            'assessment_participants_case_update_guard',
        ]);
        DB::unprepared('DROP TRIGGER IF EXISTS assessment_participants_case_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS assessment_participants_case_update_guard');
        $indexes = $this->sqliteParticipantIndexes(['assessment_participants_case_unique']);
        $this->withoutSqliteParticipantIndexes($indexes, function () use ($driver): void {
            $this->withoutSqliteReferencingTriggers($driver, function (): void {
                Schema::table('assessment_participants', function (Blueprint $table): void {
                    $table->dropForeign([
                        'assessment_case_id', 'assessment_attempt_id', 'participant_id', 'organization_id', 'package_id',
                    ]);
                    $table->dropForeign(['assessment_case_id']);
                    $table->dropUnique('assessment_participants_case_unique');
                    $table->dropColumn('assessment_case_id');
                });
            });
        });
        DB::statement('ALTER TABLE assessment_participants ADD COLUMN assessment_case_id INTEGER NULL REFERENCES assessment_cases(id) ON DELETE RESTRICT');
        DB::statement('CREATE UNIQUE INDEX assessment_participants_case_unique ON assessment_participants (assessment_case_id)');
        $this->restoreSqliteIndexes($indexes);
        $this->restoreSqliteTriggers($triggers);
        Schema::table('assessment_cases', function (Blueprint $table): void {
            $table->dropUnique(self::SCOPE_UNIQUE);
        });
    }

    private function addPostgresGuard(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_private.guard_assessment_participant_case_identity()
            RETURNS trigger LANGUAGE plpgsql SET search_path = pg_catalog, public AS $guard$
            BEGIN
                IF TG_OP = 'UPDATE' AND (
                    NEW.assessment_case_id IS DISTINCT FROM OLD.assessment_case_id OR
                    NEW.assessment_attempt_id IS DISTINCT FROM OLD.assessment_attempt_id OR
                    NEW.participant_id IS DISTINCT FROM OLD.participant_id OR
                    NEW.organization_id IS DISTINCT FROM OLD.organization_id OR
                    NEW.package_id IS DISTINCT FROM OLD.package_id
                ) THEN
                    RAISE EXCEPTION 'assessment participant case identity is immutable' USING ERRCODE = 'P0001';
                END IF;

                IF TG_OP = 'UPDATE' THEN
                    RETURN NEW;
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM public.assessment_cases ac
                    WHERE ac.id = NEW.assessment_case_id
                      AND ac.public_id = NEW.assessment_attempt_id
                      AND ac.participant_id = NEW.participant_id
                      AND ac.organization_id = NEW.organization_id
                      AND ac.package_id = NEW.package_id
                      AND ac.origin = 'INTEGRATED'
                ) THEN
                    RAISE EXCEPTION 'assessment participant requires an exact integrated case' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $guard$;
            CREATE TRIGGER assessment_participants_case_identity_guard
            BEFORE INSERT OR UPDATE ON assessment_participants
            FOR EACH ROW EXECUTE FUNCTION app_private.guard_assessment_participant_case_identity();
            SQL);
    }

    private function addSqliteGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER assessment_participants_case_insert_guard
            BEFORE INSERT ON assessment_participants
            FOR EACH ROW
            WHEN NOT EXISTS (
                SELECT 1 FROM assessment_cases ac
                WHERE ac.id = NEW.assessment_case_id
                  AND ac.public_id = NEW.assessment_attempt_id
                  AND ac.participant_id = NEW.participant_id
                  AND ac.organization_id = NEW.organization_id
                  AND ac.package_id = NEW.package_id
                  AND ac.origin = 'INTEGRATED'
            )
            BEGIN
                SELECT RAISE(ABORT, 'assessment participant requires an exact integrated case');
            END;
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER assessment_participants_case_update_guard
            BEFORE UPDATE ON assessment_participants
            FOR EACH ROW
            WHEN NEW.assessment_case_id IS NOT OLD.assessment_case_id
              OR NEW.assessment_attempt_id IS NOT OLD.assessment_attempt_id
              OR NEW.participant_id IS NOT OLD.participant_id
              OR NEW.organization_id IS NOT OLD.organization_id
              OR NEW.package_id IS NOT OLD.package_id
            BEGIN
                SELECT RAISE(ABORT, 'assessment participant case identity is immutable');
            END;
            SQL);
    }

    private function abort(string $reason): never
    {
        throw new RuntimeException('Integrated assessment case backfill aborted: '.$reason.'.');
    }

    private function transactional(Closure $operation): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::transaction(function () use ($operation): null {
                $operation();

                return null;
            });

            return;
        }

        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('SQLite integrated case migration requires no surrounding transaction.');
        }

        $foreignKeys = (bool) DB::scalar('PRAGMA foreign_keys');
        if ($foreignKeys) {
            Schema::disableForeignKeyConstraints();
        }
        try {
            DB::transaction(function () use ($operation): void {
                $operation();
                if (DB::select('PRAGMA foreign_key_check') !== []) {
                    throw new RuntimeException('Integrated case migration would violate existing foreign keys.');
                }
            });
        } finally {
            if ($foreignKeys) {
                Schema::enableForeignKeyConstraints();
            }
        }
    }

    /** @param list<string> $excluded
     * @return list<string>
     */
    private function sqliteParticipantTriggers(array $excluded = []): array
    {
        return array_values(collect(DB::select(<<<'SQL'
            SELECT name, sql FROM sqlite_master
            WHERE type = 'trigger' AND tbl_name = 'assessment_participants' AND sql IS NOT NULL
            ORDER BY name
        SQL))->reject(function (object $trigger) use ($excluded): bool {
            $row = (array) $trigger;

            return in_array($row['name'], $excluded, true);
        })->map(function (object $trigger): string {
            $row = (array) $trigger;

            return $row['sql'];
        })->values()->all());
    }

    /** @param list<string> $triggers */
    private function restoreSqliteTriggers(array $triggers): void
    {
        $existing = collect(DB::select(<<<'SQL'
            SELECT name FROM sqlite_master
            WHERE type = 'trigger' AND tbl_name = 'assessment_participants'
        SQL))->map(fn (object $trigger): string => ((array) $trigger)['name'])->all();
        foreach ($triggers as $sql) {
            if (preg_match('/CREATE\s+TRIGGER\s+["`\[]?([^"`\]\s]+)/i', $sql, $match) !== 1
                || in_array($match[1], $existing, true)) {
                continue;
            }
            if (DB::connection()->getPdo()->exec($sql) === false) {
                throw new RuntimeException('Failed to restore an assessment participant trigger.');
            }
        }
    }

    /** @param list<string> $excluded
     * @return list<array{name:string,sql:string}>
     */
    private function sqliteParticipantIndexes(array $excluded = []): array
    {
        return array_values(collect(DB::select(<<<'SQL'
            SELECT name, sql FROM sqlite_master
            WHERE type = 'index' AND tbl_name = 'assessment_participants' AND sql IS NOT NULL
            ORDER BY name
        SQL))->map(fn (object $index): array => (array) $index)
            ->reject(fn (array $index): bool => in_array($index['name'], $excluded, true))
            ->map(fn (array $index): array => ['name' => (string) $index['name'], 'sql' => (string) $index['sql']])
            ->all());
    }

    /** @param list<array{name:string,sql:string}> $indexes */
    private function withoutSqliteParticipantIndexes(array $indexes, Closure $operation): void
    {
        foreach ($indexes as $index) {
            DB::statement('DROP INDEX "'.str_replace('"', '""', $index['name']).'"');
        }
        $operation();
    }

    /** @param list<array{name:string,sql:string}> $indexes */
    private function restoreSqliteIndexes(array $indexes): void
    {
        $existing = collect(DB::select("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='assessment_participants'"))
            ->pluck('name')->all();
        foreach ($indexes as $index) {
            if (! in_array($index['name'], $existing, true)
                && DB::connection()->getPdo()->exec($index['sql']) === false) {
                throw new RuntimeException('Failed to restore an assessment participant index.');
            }
        }
    }

    private function withoutSqliteReferencingTriggers(string $driver, Closure $operation): void
    {
        if ($driver !== 'sqlite') {
            $operation();

            return;
        }
        $triggers = collect(DB::select("SELECT name, sql FROM sqlite_master WHERE type='trigger' AND sql LIKE '%assessment_participants%'"))
            ->map(fn (object $trigger): array => (array) $trigger)->all();
        foreach ($triggers as $trigger) {
            DB::statement('DROP TRIGGER "'.str_replace('"', '""', (string) $trigger['name']).'"');
        }
        try {
            $operation();
        } finally {
            foreach ($triggers as $trigger) {
                DB::unprepared((string) $trigger['sql']);
            }
        }
    }

    /** @return array{present:bool,exact:bool} */
    private function postgresEnforcementState(): array
    {
        $unique = DB::selectOne(<<<'SQL'
            SELECT c.contype, c.convalidated, c.condeferrable, c.condeferred,
                (SELECT string_agg(a.attname, ',' ORDER BY k.ordinality)
                 FROM unnest(c.conkey) WITH ORDINALITY AS k(attnum, ordinality)
                 JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.attnum) AS columns
            FROM pg_constraint c
            WHERE c.conname = 'assessment_cases_integrated_scope_unique'
              AND c.conrelid = 'assessment_cases'::regclass
            SQL);
        $foreign = DB::selectOne(<<<'SQL'
            SELECT c.contype, c.convalidated, c.condeferrable, c.condeferred,
                c.confrelid::regclass::text AS referenced_table, c.confdeltype, c.confupdtype, c.confmatchtype,
                (SELECT string_agg(a.attname, ',' ORDER BY k.ordinality)
                 FROM unnest(c.conkey) WITH ORDINALITY AS k(attnum, ordinality)
                 JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.attnum) AS columns,
                (SELECT string_agg(a.attname, ',' ORDER BY k.ordinality)
                 FROM unnest(c.confkey) WITH ORDINALITY AS k(attnum, ordinality)
                 JOIN pg_attribute a ON a.attrelid = c.confrelid AND a.attnum = k.attnum) AS referenced_columns
            FROM pg_constraint c
            WHERE c.conname = 'assessment_participants_case_scope_fk'
              AND c.conrelid = 'assessment_participants'::regclass
            SQL);
        $notNull = (bool) DB::scalar(<<<'SQL'
            SELECT attnotnull FROM pg_attribute
            WHERE attrelid = 'assessment_participants'::regclass
              AND attname = 'assessment_case_id' AND NOT attisdropped
            SQL);
        $trigger = DB::selectOne(<<<'SQL'
            SELECT t.tgtype, t.tgenabled, t.tgqual IS NULL AS unqualified,
                p.prosrc, p.prosecdef, p.prorettype = 'trigger'::regtype AS returns_trigger,
                l.lanname, p.proconfig = ARRAY['search_path=pg_catalog, public'] AS safe_search_path,
                n.nspname || '.' || p.proname AS function_name
            FROM pg_trigger t
            JOIN pg_proc p ON p.oid = t.tgfoid
            JOIN pg_namespace n ON n.oid = p.pronamespace
            JOIN pg_language l ON l.oid = p.prolang
            WHERE t.tgname = 'assessment_participants_case_identity_guard'
              AND t.tgrelid = 'assessment_participants'::regclass
              AND NOT t.tgisinternal
            SQL);

        $caseColumns = 'id,public_id,participant_id,organization_id,package_id';
        $attemptColumns = 'assessment_case_id,assessment_attempt_id,participant_id,organization_id,package_id';
        $uniqueExact = $unique !== null && $unique->contype === 'u' && $unique->convalidated
            && ! $unique->condeferrable && ! $unique->condeferred
            && $unique->columns === $caseColumns;
        $foreignExact = $foreign !== null && $foreign->contype === 'f' && $foreign->convalidated
            && ! $foreign->condeferrable && ! $foreign->condeferred
            && $foreign->columns === $attemptColumns && $foreign->referenced_table === 'assessment_cases'
            && $foreign->referenced_columns === $caseColumns && $foreign->confdeltype === 'r'
            && $foreign->confupdtype === 'a' && $foreign->confmatchtype === 's';
        $triggerExact = $trigger !== null && (int) $trigger->tgtype === 23 && $trigger->tgenabled === 'O'
            && $trigger->unqualified && ! $trigger->prosecdef && $trigger->returns_trigger
            && $trigger->lanname === 'plpgsql' && $trigger->safe_search_path
            && $trigger->function_name === 'app_private.guard_assessment_participant_case_identity'
            && $this->normalizeSql($trigger->prosrc) === $this->normalizeSql($this->postgresGuardBody());

        return [
            'present' => $unique !== null || $foreign !== null || $notNull || $trigger !== null,
            'exact' => $uniqueExact && $foreignExact && $notNull && $triggerExact,
        ];
    }

    /** @return array{present:bool,exact:bool} */
    private function sqliteEnforcementState(): array
    {
        $notNull = (int) collect(DB::select("PRAGMA table_info('assessment_participants')"))
            ->map(fn (object $column): array => (array) $column)
            ->firstWhere('name', 'assessment_case_id')['notnull'];
        $scopeIndex = collect(DB::select("PRAGMA index_list('assessment_cases')"))
            ->map(fn (object $index): array => (array) $index)
            ->firstWhere('name', self::SCOPE_UNIQUE);
        $indexColumns = $scopeIndex === null ? [] : collect(DB::select("PRAGMA index_info('".self::SCOPE_UNIQUE."')"))
            ->sortBy('seqno')->pluck('name')->all();
        $scopeForeign = collect(DB::select("PRAGMA foreign_key_list('assessment_participants')"))
            ->groupBy(fn (object $column): int => (int) ((array) $column)['id'])
            ->first(function ($columns): bool {
                $first = (array) $columns->first();

                return $first['table'] === 'assessment_cases' && $columns->count() === 5;
            });
        $foreignRows = $scopeForeign?->sortBy(fn (object $column): int => (int) ((array) $column)['seq'])->values() ?? collect();
        $triggers = collect(DB::select(<<<'SQL'
            SELECT name, sql FROM sqlite_master
            WHERE type = 'trigger' AND tbl_name = 'assessment_participants'
              AND name IN ('assessment_participants_case_insert_guard', 'assessment_participants_case_update_guard')
            SQL))->mapWithKeys(fn (object $trigger): array => [((array) $trigger)['name'] => ((array) $trigger)['sql']]);
        $indexExact = $scopeIndex !== null && (int) $scopeIndex['unique'] === 1
            && (int) $scopeIndex['partial'] === 0
            && $indexColumns === ['id', 'public_id', 'participant_id', 'organization_id', 'package_id'];
        $foreignExact = $foreignRows->pluck('from')->all()
                === ['assessment_case_id', 'assessment_attempt_id', 'participant_id', 'organization_id', 'package_id']
            && $foreignRows->pluck('to')->all()
                === ['id', 'public_id', 'participant_id', 'organization_id', 'package_id']
            && $foreignRows->every(fn (object $column): bool => strtoupper(((array) $column)['on_delete']) === 'RESTRICT'
                && strtoupper(((array) $column)['on_update']) === 'NO ACTION'
                && strtoupper(((array) $column)['match']) === 'NONE');
        $guardsExact = $triggers->count() === 2
            && $this->normalizeSql($triggers['assessment_participants_case_insert_guard'])
                === $this->normalizeSql($this->sqliteInsertGuardSql())
            && $this->normalizeSql($triggers['assessment_participants_case_update_guard'])
                === $this->normalizeSql($this->sqliteUpdateGuardSql());

        return [
            'present' => $notNull === 1 || $scopeIndex !== null || $scopeForeign !== null || $triggers->isNotEmpty(),
            'exact' => $notNull === 1 && $indexExact && $foreignExact && $guardsExact,
        ];
    }

    private function postgresGuardBody(): string
    {
        return <<<'SQL'
            BEGIN
                IF TG_OP = 'UPDATE' AND (
                    NEW.assessment_case_id IS DISTINCT FROM OLD.assessment_case_id OR
                    NEW.assessment_attempt_id IS DISTINCT FROM OLD.assessment_attempt_id OR
                    NEW.participant_id IS DISTINCT FROM OLD.participant_id OR
                    NEW.organization_id IS DISTINCT FROM OLD.organization_id OR
                    NEW.package_id IS DISTINCT FROM OLD.package_id
                ) THEN
                    RAISE EXCEPTION 'assessment participant case identity is immutable' USING ERRCODE = 'P0001';
                END IF;

                IF TG_OP = 'UPDATE' THEN
                    RETURN NEW;
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM public.assessment_cases ac
                    WHERE ac.id = NEW.assessment_case_id
                      AND ac.public_id = NEW.assessment_attempt_id
                      AND ac.participant_id = NEW.participant_id
                      AND ac.organization_id = NEW.organization_id
                      AND ac.package_id = NEW.package_id
                      AND ac.origin = 'INTEGRATED'
                ) THEN
                    RAISE EXCEPTION 'assessment participant requires an exact integrated case' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            SQL;
    }

    private function sqliteInsertGuardSql(): string
    {
        return <<<'SQL'
            CREATE TRIGGER assessment_participants_case_insert_guard
            BEFORE INSERT ON assessment_participants
            FOR EACH ROW
            WHEN NOT EXISTS (
                SELECT 1 FROM assessment_cases ac
                WHERE ac.id = NEW.assessment_case_id
                  AND ac.public_id = NEW.assessment_attempt_id
                  AND ac.participant_id = NEW.participant_id
                  AND ac.organization_id = NEW.organization_id
                  AND ac.package_id = NEW.package_id
                  AND ac.origin = 'INTEGRATED'
            )
            BEGIN
                SELECT RAISE(ABORT, 'assessment participant requires an exact integrated case');
            END;
            SQL;
    }

    private function sqliteUpdateGuardSql(): string
    {
        return <<<'SQL'
            CREATE TRIGGER assessment_participants_case_update_guard
            BEFORE UPDATE ON assessment_participants
            FOR EACH ROW
            WHEN NEW.assessment_case_id IS NOT OLD.assessment_case_id
              OR NEW.assessment_attempt_id IS NOT OLD.assessment_attempt_id
              OR NEW.participant_id IS NOT OLD.participant_id
              OR NEW.organization_id IS NOT OLD.organization_id
              OR NEW.package_id IS NOT OLD.package_id
            BEGIN
                SELECT RAISE(ABORT, 'assessment participant case identity is immutable');
            END;
            SQL;
    }

    private function normalizeSql(string $sql): string
    {
        return (string) preg_replace('/\s+/', ' ', rtrim(trim($sql), ';'));
    }
};
