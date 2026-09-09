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
            $foreignExists = (bool) DB::scalar(<<<'SQL'
                SELECT EXISTS (
                    SELECT 1 FROM pg_constraint
                    WHERE conname = 'assessment_participants_case_scope_fk'
                      AND conrelid = 'assessment_participants'::regclass
                      AND contype = 'f'
                )
                SQL);
            if ($foreignExists
                && (bool) DB::scalar(<<<'SQL'
                    SELECT attnotnull FROM pg_attribute
                    WHERE attrelid = 'assessment_participants'::regclass
                      AND attname = 'assessment_case_id' AND NOT attisdropped
                    SQL)
                && (bool) DB::scalar(<<<'SQL'
                    SELECT EXISTS (
                        SELECT 1 FROM pg_constraint
                        WHERE conname = 'assessment_cases_integrated_scope_unique'
                          AND conrelid = 'assessment_cases'::regclass
                          AND contype = 'u'
                    )
                    SQL)
                && (bool) DB::scalar(<<<'SQL'
                    SELECT EXISTS (
                        SELECT 1 FROM pg_trigger
                        WHERE tgname = 'assessment_participants_case_identity_guard'
                          AND tgrelid = 'assessment_participants'::regclass
                          AND NOT tgisinternal
                    )
                    SQL)) {
                return;
            }
            if ($foreignExists) {
                $this->abort('partial PostgreSQL enforcement already exists');
            }

            DB::statement('ALTER TABLE assessment_cases ADD CONSTRAINT '.self::SCOPE_UNIQUE.' UNIQUE (id, public_id, participant_id, organization_id, package_id)');
            DB::statement('ALTER TABLE assessment_participants ADD CONSTRAINT '.self::SCOPE_FOREIGN.' FOREIGN KEY (assessment_case_id, assessment_attempt_id, participant_id, organization_id, package_id) REFERENCES assessment_cases (id, public_id, participant_id, organization_id, package_id) ON DELETE RESTRICT');
            DB::statement('ALTER TABLE assessment_participants ALTER COLUMN assessment_case_id SET NOT NULL');
            $this->addPostgresGuard();

            return;
        }

        $sqliteState = $this->sqliteEnforcementState();
        if ($sqliteState === 4) {
            return;
        }
        if ($sqliteState !== 0) {
            $this->abort('partial SQLite enforcement already exists');
        }

        $triggers = $this->sqliteParticipantTriggers();
        Schema::table('assessment_cases', function (Blueprint $table): void {
            $table->unique(
                ['id', 'public_id', 'participant_id', 'organization_id', 'package_id'],
                self::SCOPE_UNIQUE,
            );
        });
        Schema::table('assessment_participants', function (Blueprint $table): void {
            $table->foreign(
                ['assessment_case_id', 'assessment_attempt_id', 'participant_id', 'organization_id', 'package_id'],
            )->references(['id', 'public_id', 'participant_id', 'organization_id', 'package_id'])
                ->on('assessment_cases')->restrictOnDelete();
            $table->unsignedBigInteger('assessment_case_id')->nullable(false)->change();
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
        Schema::table('assessment_participants', function (Blueprint $table): void {
            $table->dropForeign([
                'assessment_case_id', 'assessment_attempt_id', 'participant_id', 'organization_id', 'package_id',
            ]);
            $table->dropForeign(['assessment_case_id']);
            $table->dropUnique('assessment_participants_case_unique');
            $table->dropColumn('assessment_case_id');
        });
        DB::statement('ALTER TABLE assessment_participants ADD COLUMN assessment_case_id INTEGER NULL REFERENCES assessment_cases(id) ON DELETE RESTRICT');
        DB::statement('CREATE UNIQUE INDEX assessment_participants_case_unique ON assessment_participants (assessment_case_id)');
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

    private function sqliteEnforcementState(): int
    {
        $notNull = (int) collect(DB::select("PRAGMA table_info('assessment_participants')"))
            ->map(fn (object $column): array => (array) $column)
            ->firstWhere('name', 'assessment_case_id')['notnull'];
        $scopeIndex = collect(DB::select("PRAGMA index_list('assessment_cases')"))
            ->contains(fn (object $index): bool => ((array) $index)['name'] === self::SCOPE_UNIQUE);
        $scopeForeign = collect(DB::select("PRAGMA foreign_key_list('assessment_participants')"))
            ->groupBy(fn (object $column): int => (int) ((array) $column)['id'])
            ->contains(fn ($columns): bool => $columns->count() === 5
                && $columns->every(fn (object $column): bool => ((array) $column)['table'] === 'assessment_cases'));
        $guard = collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'trigger'"))
            ->contains(fn (object $trigger): bool => ((array) $trigger)['name'] === 'assessment_participants_case_insert_guard');

        return $notNull + (int) $scopeIndex + (int) $scopeForeign + (int) $guard;
    }
};
