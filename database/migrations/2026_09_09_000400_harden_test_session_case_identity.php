<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PARENT_UNIQUE = 'assessment_cases_session_scope_unique';

    private const CHILD_FOREIGN = 'test_sessions_case_scope_fk';

    /** @var array<string, array{columns:list<string>, predicate:string}> */
    private const INDEXES = [
        'test_sessions_case_attempt_unique' => [
            'columns' => ['assessment_case_id', 'test_type', 'attempt_no'],
            'predicate' => 'assessment_case_id IS NOT NULL',
        ],
        'test_sessions_case_authorization_unique' => [
            'columns' => ['assessment_case_id', 'test_type', 'authorization_id'],
            'predicate' => 'assessment_case_id IS NOT NULL',
        ],
        'test_sessions_case_allocation_intent_unique' => [
            'columns' => ['assessment_case_id', 'test_type', 'allocation_intent_id'],
            'predicate' => 'assessment_case_id IS NOT NULL',
        ],
        'test_sessions_case_one_active_unique' => [
            'columns' => ['assessment_case_id', 'test_type'],
            'predicate' => "assessment_case_id IS NOT NULL AND status IN ('created','in_progress')",
        ],
    ];

    public function up(): void
    {
        try {
            $this->transactional(function (): void {
                $driver = DB::getDriverName();
                if ($driver === 'pgsql') {
                    DB::statement('LOCK TABLE test_sessions IN ACCESS EXCLUSIVE MODE');
                    DB::statement('LOCK TABLE assessment_cases IN ACCESS EXCLUSIVE MODE');
                    DB::statement('ALTER TABLE assessment_cases NO FORCE ROW LEVEL SECURITY');
                    DB::statement('ALTER TABLE test_sessions NO FORCE ROW LEVEL SECURITY');
                }

                $this->preflight();
                $this->backfill($driver);
                $this->preflight();
                $this->enforce($driver);

                if ($driver === 'pgsql') {
                    DB::statement('ALTER TABLE test_sessions FORCE ROW LEVEL SECURITY');
                    DB::statement('ALTER TABLE assessment_cases FORCE ROW LEVEL SECURITY');
                }
            });
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException
                && str_starts_with($exception->getMessage(), 'Test session case backfill aborted:')) {
                throw $exception;
            }

            throw new RuntimeException(
                'Test session case backfill aborted: '.$exception->getMessage(),
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
                DB::statement('LOCK TABLE test_sessions IN ACCESS EXCLUSIVE MODE');
                DB::statement('LOCK TABLE assessment_cases IN ACCESS EXCLUSIVE MODE');
                DB::statement('ALTER TABLE test_sessions NO FORCE ROW LEVEL SECURITY');
            }

            if (DB::table('test_sessions')->exists()) {
                throw new RuntimeException('Test session case history prevents rollback.');
            }

            $this->removeEnforcement($driver);

            if ($driver === 'pgsql') {
                DB::statement('ALTER TABLE test_sessions FORCE ROW LEVEL SECURITY');
            }
        });
    }

    private function preflight(): void
    {
        $distinct = DB::getDriverName() === 'pgsql' ? 'IS DISTINCT FROM' : 'IS NOT';
        $invalidBound = DB::selectOne(<<<SQL
            SELECT EXISTS (
                SELECT 1 FROM test_sessions session
                LEFT JOIN assessment_cases assessment_case
                  ON assessment_case.id = session.assessment_case_id
                WHERE session.assessment_case_id IS NOT NULL
                  AND (assessment_case.id IS NULL
                    OR assessment_case.participant_id {$distinct} session.participant_id)
            ) AS invalid
            SQL)->invalid;
        if ($invalidBound) {
            $this->abort('an existing binding does not match its participant case');
        }

        $ambiguous = DB::selectOne(<<<'SQL'
            SELECT EXISTS (
                SELECT 1 FROM test_sessions session
                JOIN assessment_cases assessment_case
                  ON assessment_case.participant_id = session.participant_id
                WHERE session.assessment_case_id IS NULL
                GROUP BY session.id
                HAVING COUNT(assessment_case.id) > 1
            ) AS invalid
            SQL)->invalid;
        if ($ambiguous) {
            $this->abort('an unbound session has more than one accepted case');
        }
    }

    private function backfill(string $driver): void
    {
        if ($driver === 'pgsql') {
            DB::statement(<<<'SQL'
                UPDATE test_sessions AS session
                SET assessment_case_id = candidate.id
                FROM (
                    SELECT session.id AS session_id, MIN(assessment_case.id) AS id
                    FROM test_sessions session
                    JOIN assessment_cases assessment_case
                      ON assessment_case.participant_id = session.participant_id
                    WHERE session.assessment_case_id IS NULL
                    GROUP BY session.id
                    HAVING COUNT(assessment_case.id) = 1
                ) candidate
                WHERE session.id = candidate.session_id
                  AND session.assessment_case_id IS NULL
                SQL);

            return;
        }

        DB::statement(<<<'SQL'
            UPDATE test_sessions
            SET assessment_case_id = (
                SELECT MIN(assessment_case.id)
                FROM assessment_cases assessment_case
                WHERE assessment_case.participant_id = test_sessions.participant_id
                HAVING COUNT(assessment_case.id) = 1
            )
            WHERE assessment_case_id IS NULL
            SQL);
    }

    private function enforce(string $driver): void
    {
        if ($driver === 'pgsql') {
            $state = $this->postgresState();
            if ($state['exact']) {
                return;
            }
            if ($state['present']) {
                $this->abort('partial PostgreSQL enforcement already exists');
            }

            DB::statement('ALTER TABLE assessment_cases ADD CONSTRAINT '.self::PARENT_UNIQUE.' UNIQUE (id, participant_id)');
            DB::statement('ALTER TABLE test_sessions ADD CONSTRAINT '.self::CHILD_FOREIGN.' FOREIGN KEY (assessment_case_id, participant_id) REFERENCES assessment_cases (id, participant_id) ON DELETE RESTRICT');
            foreach (self::INDEXES as $name => $definition) {
                $columns = implode(', ', $definition['columns']);
                DB::statement("CREATE UNIQUE INDEX {$name} ON test_sessions ({$columns}) WHERE {$definition['predicate']}");
            }
            $this->addPostgresGuard();

            return;
        }

        $state = $this->sqliteState();
        if ($state['exact']) {
            return;
        }
        if ($state['present']) {
            $this->abort('partial SQLite enforcement already exists');
        }

        $triggers = $this->sqliteReferencingTriggers();
        $indexes = $this->sqliteSessionIndexes();
        Schema::table('assessment_cases', function (Blueprint $table): void {
            $table->unique(['id', 'participant_id'], self::PARENT_UNIQUE);
        });
        $this->withoutSqliteTriggers($triggers, function () use ($indexes): void {
            $this->withoutSqliteIndexes($indexes, function (): void {
                Schema::table('test_sessions', function (Blueprint $table): void {
                    $table->foreign(['assessment_case_id', 'participant_id'], self::CHILD_FOREIGN)
                        ->references(['id', 'participant_id'])->on('assessment_cases')->restrictOnDelete();
                });
            });
        });
        $this->restoreSqliteIndexes($indexes);
        foreach (self::INDEXES as $name => $definition) {
            $columns = implode(', ', $definition['columns']);
            DB::statement("CREATE UNIQUE INDEX {$name} ON test_sessions ({$columns}) WHERE {$definition['predicate']}");
        }
        $this->addSqliteGuards();
    }

    private function removeEnforcement(string $driver): void
    {
        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS test_sessions_case_identity_guard ON test_sessions');
            DB::unprepared('DROP FUNCTION IF EXISTS app_private.guard_test_session_case_identity()');
            foreach (array_keys(self::INDEXES) as $name) {
                DB::statement("DROP INDEX IF EXISTS {$name}");
            }
            DB::statement('ALTER TABLE test_sessions DROP CONSTRAINT IF EXISTS '.self::CHILD_FOREIGN);
            DB::statement('ALTER TABLE assessment_cases DROP CONSTRAINT IF EXISTS '.self::PARENT_UNIQUE);

            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS test_sessions_case_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS test_sessions_case_update_guard');
        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement("DROP INDEX IF EXISTS {$name}");
        }
        $triggers = $this->sqliteReferencingTriggers([
            'test_sessions_case_insert_guard', 'test_sessions_case_update_guard',
        ]);
        $indexes = $this->sqliteSessionIndexes();
        $this->withoutSqliteTriggers($triggers, function () use ($indexes): void {
            $this->withoutSqliteIndexes($indexes, function (): void {
                Schema::table('test_sessions', function (Blueprint $table): void {
                    $table->dropForeign(['assessment_case_id', 'participant_id']);
                });
            });
        });
        $this->restoreSqliteIndexes($indexes);
        Schema::table('assessment_cases', function (Blueprint $table): void {
            $table->dropUnique(self::PARENT_UNIQUE);
        });
    }

    private function addPostgresGuard(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION app_private.guard_test_session_case_identity()
            RETURNS trigger LANGUAGE plpgsql SET search_path = pg_catalog, public AS $guard$
            BEGIN
                IF TG_OP = 'UPDATE' AND NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'test session creation timestamp is immutable' USING ERRCODE = 'P0001';
                END IF;
                IF TG_OP = 'UPDATE' AND NEW.assessment_case_id IS DISTINCT FROM OLD.assessment_case_id THEN
                    RAISE EXCEPTION 'test session case identity is immutable' USING ERRCODE = 'P0001';
                END IF;
                IF TG_OP = 'UPDATE' THEN
                    RETURN NEW;
                END IF;
                IF NEW.assessment_case_id IS NULL THEN
                    IF EXISTS (
                        SELECT 1 FROM public.assessment_cases assessment_case
                        WHERE assessment_case.participant_id = NEW.participant_id
                    ) THEN
                        RAISE EXCEPTION 'test session must bind its accepted participant case' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                IF NOT EXISTS (
                    SELECT 1 FROM public.assessment_cases assessment_case
                    WHERE assessment_case.id = NEW.assessment_case_id
                      AND assessment_case.participant_id = NEW.participant_id
                ) THEN
                    RAISE EXCEPTION 'test session requires an exact participant case' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $guard$;
            CREATE TRIGGER test_sessions_case_identity_guard
            BEFORE INSERT OR UPDATE ON test_sessions
            FOR EACH ROW EXECUTE FUNCTION app_private.guard_test_session_case_identity();
            SQL);
    }

    private function addSqliteGuards(): void
    {
        DB::connection()->getPdo()->exec($this->sqliteInsertGuardSql());
        DB::connection()->getPdo()->exec($this->sqliteUpdateGuardSql());
    }

    private function sqliteInsertGuardSql(): string
    {
        return <<<'SQL'
            CREATE TRIGGER test_sessions_case_insert_guard
            BEFORE INSERT ON test_sessions FOR EACH ROW
            WHEN (NEW.assessment_case_id IS NULL AND EXISTS (
                    SELECT 1 FROM assessment_cases assessment_case
                    WHERE assessment_case.participant_id = NEW.participant_id
                )) OR (NEW.assessment_case_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM assessment_cases assessment_case
                    WHERE assessment_case.id = NEW.assessment_case_id
                      AND assessment_case.participant_id = NEW.participant_id
                ))
            BEGIN
                SELECT RAISE(ABORT, 'test session requires an exact participant case');
            END;
            SQL;
    }

    private function sqliteUpdateGuardSql(): string
    {
        return <<<'SQL'
            CREATE TRIGGER test_sessions_case_update_guard
            BEFORE UPDATE ON test_sessions FOR EACH ROW
            WHEN NEW.created_at IS NOT OLD.created_at
              OR NEW.assessment_case_id IS NOT OLD.assessment_case_id
            BEGIN
                SELECT RAISE(ABORT, 'test session case identity is immutable');
            END;
            SQL;
    }

    private function abort(string $reason): never
    {
        throw new RuntimeException('Test session case backfill aborted: '.$reason.'.');
    }

    private function transactional(Closure $operation): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::transaction(fn (): mixed => $operation());

            return;
        }

        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('SQLite test session case migration requires no surrounding transaction.');
        }
        $foreignKeys = (bool) DB::scalar('PRAGMA foreign_keys');
        if ($foreignKeys) {
            Schema::disableForeignKeyConstraints();
        }
        try {
            DB::transaction(function () use ($operation): void {
                $operation();
                if (DB::select('PRAGMA foreign_key_check') !== []) {
                    throw new RuntimeException('Test session case migration would violate existing foreign keys.');
                }
            });
        } finally {
            if ($foreignKeys) {
                Schema::enableForeignKeyConstraints();
            }
        }
    }

    /** @param list<string> $excluded
     * @return list<array{name:string,sql:string}>
     */
    private function sqliteReferencingTriggers(array $excluded = []): array
    {
        return array_values(collect(DB::select(<<<'SQL'
            SELECT name, sql FROM sqlite_master
            WHERE type = 'trigger' AND sql IS NOT NULL
              AND lower(sql) LIKE '%test_sessions%'
            ORDER BY name
            SQL))->map(fn (object $trigger): array => (array) $trigger)
            ->reject(fn (array $trigger): bool => in_array((string) $trigger['name'], $excluded, true))
            ->map(fn (array $trigger): array => ['name' => (string) $trigger['name'], 'sql' => (string) $trigger['sql']])
            ->all());
    }

    /** @param list<array{name:string,sql:string}> $triggers */
    private function withoutSqliteTriggers(array $triggers, Closure $operation): void
    {
        foreach ($triggers as $trigger) {
            DB::connection()->getPdo()->exec('DROP TRIGGER IF EXISTS "'.str_replace('"', '""', $trigger['name']).'"');
        }
        try {
            $operation();
        } finally {
            foreach ($triggers as $trigger) {
                if (DB::connection()->getPdo()->exec($trigger['sql']) === false) {
                    throw new RuntimeException('Failed to restore a test session trigger.');
                }
            }
        }
    }

    /** @return list<array{name:string,sql:string}> */
    private function sqliteSessionIndexes(): array
    {
        return array_values(collect(DB::select(<<<'SQL'
            SELECT name,sql FROM sqlite_master
            WHERE type='index' AND tbl_name='test_sessions' AND sql IS NOT NULL
            ORDER BY name
            SQL))->map(fn (object $index): array => (array) $index)
            ->map(fn (array $index): array => ['name' => (string) $index['name'], 'sql' => (string) $index['sql']])
            ->all());
    }

    /** @param list<array{name:string,sql:string}> $indexes */
    private function restoreSqliteIndexes(array $indexes): void
    {
        $existing = collect(DB::select("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='test_sessions'"))
            ->pluck('name')->all();
        foreach ($indexes as $index) {
            if (! in_array($index['name'], $existing, true)
                && DB::connection()->getPdo()->exec($index['sql']) === false) {
                throw new RuntimeException('Failed to restore a test session index.');
            }
        }
    }

    /** @param list<array{name:string,sql:string}> $indexes */
    private function withoutSqliteIndexes(array $indexes, Closure $operation): void
    {
        foreach ($indexes as $index) {
            DB::statement('DROP INDEX "'.str_replace('"', '""', $index['name']).'"');
        }
        $operation();
    }

    /** @return array{present:bool,exact:bool} */
    private function postgresState(): array
    {
        $unique = DB::selectOne(<<<'SQL'
            SELECT c.contype, c.convalidated, c.condeferrable, c.condeferred,
              (SELECT string_agg(a.attname, ',' ORDER BY k.ordinality)
               FROM unnest(c.conkey) WITH ORDINALITY k(attnum, ordinality)
               JOIN pg_attribute a ON a.attrelid=c.conrelid AND a.attnum=k.attnum) columns
            FROM pg_constraint c WHERE c.conname='assessment_cases_session_scope_unique'
              AND c.conrelid='assessment_cases'::regclass
            SQL);
        $foreign = DB::selectOne(<<<'SQL'
            SELECT c.contype,c.convalidated,c.condeferrable,c.condeferred,
              c.confrelid::regclass::text referenced_table,c.confdeltype,c.confupdtype,c.confmatchtype,
              (SELECT string_agg(a.attname, ',' ORDER BY k.ordinality) FROM unnest(c.conkey) WITH ORDINALITY k(attnum,ordinality) JOIN pg_attribute a ON a.attrelid=c.conrelid AND a.attnum=k.attnum) columns,
              (SELECT string_agg(a.attname, ',' ORDER BY k.ordinality) FROM unnest(c.confkey) WITH ORDINALITY k(attnum,ordinality) JOIN pg_attribute a ON a.attrelid=c.confrelid AND a.attnum=k.attnum) referenced_columns
            FROM pg_constraint c WHERE c.conname='test_sessions_case_scope_fk'
              AND c.conrelid='test_sessions'::regclass
            SQL);
        $indexExact = true;
        $indexPresent = false;
        foreach (self::INDEXES as $name => $definition) {
            $index = DB::selectOne(<<<'SQL'
                SELECT i.indisunique,i.indisvalid,i.indisready,
                  (SELECT string_agg(a.attname, ',' ORDER BY k.ordinality) FROM unnest(i.indkey) WITH ORDINALITY k(attnum,ordinality) JOIN pg_attribute a ON a.attrelid=i.indrelid AND a.attnum=k.attnum WHERE k.attnum > 0) columns,
                  pg_get_expr(i.indpred,i.indrelid) predicate
                FROM pg_class c JOIN pg_index i ON i.indexrelid=c.oid
                WHERE c.relname=? AND i.indrelid='test_sessions'::regclass
                SQL, [$name]);
            $indexPresent = $indexPresent || $index !== null;
            $indexExact = $indexExact && $index !== null && $index->indisunique && $index->indisvalid
                && $index->indisready && $index->columns === implode(',', $definition['columns'])
                && $this->normalizePredicate($index->predicate) === $this->normalizePredicate($definition['predicate']);
        }
        $trigger = DB::selectOne(<<<'SQL'
            SELECT t.tgtype,t.tgenabled,t.tgqual IS NULL unqualified,p.prosrc,p.prosecdef,
              p.prorettype='trigger'::regtype returns_trigger,l.lanname,
              p.proconfig=ARRAY['search_path=pg_catalog, public'] safe_search_path,
              n.nspname||'.'||p.proname function_name
            FROM pg_trigger t JOIN pg_proc p ON p.oid=t.tgfoid
            JOIN pg_namespace n ON n.oid=p.pronamespace JOIN pg_language l ON l.oid=p.prolang
            WHERE t.tgname='test_sessions_case_identity_guard'
              AND t.tgrelid='test_sessions'::regclass AND NOT t.tgisinternal
            SQL);
        $uniqueExact = $unique !== null && $unique->contype === 'u' && $unique->convalidated
            && ! $unique->condeferrable && ! $unique->condeferred && $unique->columns === 'id,participant_id';
        $foreignExact = $foreign !== null && $foreign->contype === 'f' && $foreign->convalidated
            && ! $foreign->condeferrable && ! $foreign->condeferred
            && $foreign->columns === 'assessment_case_id,participant_id'
            && $foreign->referenced_table === 'assessment_cases'
            && $foreign->referenced_columns === 'id,participant_id'
            && $foreign->confdeltype === 'r' && $foreign->confupdtype === 'a' && $foreign->confmatchtype === 's';
        $triggerExact = $trigger !== null && (int) $trigger->tgtype === 23 && $trigger->tgenabled === 'O'
            && $trigger->unqualified && ! $trigger->prosecdef && $trigger->returns_trigger
            && $trigger->lanname === 'plpgsql' && $trigger->safe_search_path
            && $trigger->function_name === 'app_private.guard_test_session_case_identity'
            && $this->normalizeSql($trigger->prosrc) === $this->normalizeSql($this->postgresGuardBody());

        return [
            'present' => $unique !== null || $foreign !== null || $indexPresent || $trigger !== null,
            'exact' => $uniqueExact && $foreignExact && $indexExact && $triggerExact,
        ];
    }

    /** @return array{present:bool,exact:bool} */
    private function sqliteState(): array
    {
        $parent = collect(DB::select("PRAGMA index_list('assessment_cases')"))->firstWhere('name', self::PARENT_UNIQUE);
        $parentColumns = $parent === null ? [] : collect(DB::select("PRAGMA index_info('".self::PARENT_UNIQUE."')"))
            ->sortBy('seqno')->pluck('name')->all();
        $foreign = collect(DB::select("PRAGMA foreign_key_list('test_sessions')"))->groupBy('id')
            ->first(fn ($rows): bool => $rows->count() === 2 && $rows->first()->table === 'assessment_cases');
        $foreign = $foreign?->sortBy('seq')->values() ?? collect();
        $indexExact = true;
        $indexPresent = false;
        foreach (self::INDEXES as $name => $definition) {
            $index = collect(DB::select("PRAGMA index_list('test_sessions')"))->firstWhere('name', $name);
            $indexPresent = $indexPresent || $index !== null;
            $sql = $index === null ? null : DB::table('sqlite_master')->where('type', 'index')->where('name', $name)->value('sql');
            $columns = $index === null ? [] : collect(DB::select("PRAGMA index_info('{$name}')"))->sortBy('seqno')->pluck('name')->all();
            $predicate = is_string($sql) && preg_match('/\sWHERE\s(.+)$/is', $sql, $match) === 1 ? $match[1] : '';
            $indexExact = $indexExact && $index !== null && (int) $index->unique === 1 && (int) $index->partial === 1
                && $columns === $definition['columns']
                && $this->normalizePredicate($predicate) === $this->normalizePredicate($definition['predicate']);
        }
        $triggers = collect(DB::select(<<<'SQL'
            SELECT name,sql FROM sqlite_master WHERE type='trigger' AND tbl_name='test_sessions'
              AND name IN ('test_sessions_case_insert_guard','test_sessions_case_update_guard')
            SQL))->pluck('sql', 'name');
        $parentExact = $parent !== null && (int) $parent->unique === 1 && (int) $parent->partial === 0
            && $parentColumns === ['id', 'participant_id'];
        $foreign = $foreign->map(fn (object $row): array => (array) $row);
        $foreignExact = $foreign->pluck('from')->all() === ['assessment_case_id', 'participant_id']
            && $foreign->pluck('to')->all() === ['id', 'participant_id']
            && $foreign->every(fn (array $row): bool => strtoupper((string) $row['on_delete']) === 'RESTRICT'
                && strtoupper((string) $row['on_update']) === 'NO ACTION'
                && strtoupper((string) $row['match']) === 'NONE');
        $guardsExact = $triggers->count() === 2
            && $this->normalizeSql($triggers['test_sessions_case_insert_guard']) === $this->normalizeSql($this->sqliteInsertGuardSql())
            && $this->normalizeSql($triggers['test_sessions_case_update_guard']) === $this->normalizeSql($this->sqliteUpdateGuardSql());

        return [
            'present' => $parent !== null || $foreign->isNotEmpty() || $indexPresent || $triggers->isNotEmpty(),
            'exact' => $parentExact && $foreignExact && $indexExact && $guardsExact,
        ];
    }

    private function postgresGuardBody(): string
    {
        return <<<'SQL'
            BEGIN
                IF TG_OP = 'UPDATE' AND NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'test session creation timestamp is immutable' USING ERRCODE = 'P0001';
                END IF;
                IF TG_OP = 'UPDATE' AND NEW.assessment_case_id IS DISTINCT FROM OLD.assessment_case_id THEN
                    RAISE EXCEPTION 'test session case identity is immutable' USING ERRCODE = 'P0001';
                END IF;
                IF TG_OP = 'UPDATE' THEN
                    RETURN NEW;
                END IF;
                IF NEW.assessment_case_id IS NULL THEN
                    IF EXISTS (
                        SELECT 1 FROM public.assessment_cases assessment_case
                        WHERE assessment_case.participant_id = NEW.participant_id
                    ) THEN
                        RAISE EXCEPTION 'test session must bind its accepted participant case' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                IF NOT EXISTS (
                    SELECT 1 FROM public.assessment_cases assessment_case
                    WHERE assessment_case.id = NEW.assessment_case_id
                      AND assessment_case.participant_id = NEW.participant_id
                ) THEN
                    RAISE EXCEPTION 'test session requires an exact participant case' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            SQL;
    }

    private function normalizeSql(string $sql): string
    {
        return (string) preg_replace('/\s+/', ' ', rtrim(trim($sql), ';'));
    }

    private function normalizePredicate(string $sql): string
    {
        $normalized = strtolower($sql);
        $normalized = (string) preg_replace('/::(?:character varying|text)(?:\[\])?/', '', $normalized);
        $normalized = str_replace(['= any', 'array[', ']', '(', ')', ' '], [' in', '', '', '', '', ''], $normalized);

        return $normalized;
    }
};
