<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const string FOREIGN = 'selection_participants_case_scope_fk';

    private const string UNIQUE = 'selection_participants_case_unique';

    public function up(): void
    {
        try {
            DB::transaction(function (): void {
                $driver = DB::getDriverName();
                if ($driver === 'pgsql') {
                    DB::statement('LOCK TABLE selection_participants, participants, assessment_cases IN ACCESS EXCLUSIVE MODE');
                    foreach (['selection_participants', 'participants', 'assessment_cases'] as $table) {
                        DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
                    }
                }

                Schema::table('selection_participants', function (Blueprint $table): void {
                    $table->unsignedBigInteger('assessment_case_id')->nullable();
                });

                $this->backfill();
                $this->assertComplete();
                $this->enforce($driver);

                if ($driver === 'pgsql') {
                    foreach (['participants', 'assessment_cases', 'selection_participants'] as $table) {
                        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
                    }
                }
            });
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Legacy Selection case backfill aborted: '.$exception->getMessage(),
                (int) $exception->getCode(),
                $exception,
            );
        }
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $driver = DB::getDriverName();
            if ($driver === 'pgsql') {
                DB::statement('LOCK TABLE selection_participants, assessment_cases IN ACCESS EXCLUSIVE MODE');
                DB::statement('ALTER TABLE selection_participants NO FORCE ROW LEVEL SECURITY');
                DB::statement('ALTER TABLE assessment_cases NO FORCE ROW LEVEL SECURITY');
            }

            if (DB::table('selection_participants')->exists()
                || DB::table('assessment_cases')->where('origin', 'LEGACY_SELECTION')->exists()) {
                throw new RuntimeException('Legacy Selection case history prevents rollback.');
            }

            $indexes = $this->sqliteSelectionIndexes([self::UNIQUE]);
            $this->withoutSqliteSelectionIndexes($driver, $indexes, function () use ($driver): void {
                $this->withoutSqliteReferencingTriggers($driver, [
                    'selection_participants_case_insert_guard',
                    'selection_participants_case_update_guard',
                    'selection_participants_case_delete_guard',
                ], function () use ($driver): void {
                    $this->removeEnforcement($driver);
                    Schema::table('selection_participants', function (Blueprint $table): void {
                        $table->dropColumn('assessment_case_id');
                    });
                });
            });
            $this->restoreSqliteSelectionIndexes($driver, $indexes);

            if ($driver === 'pgsql') {
                DB::statement('ALTER TABLE assessment_cases FORCE ROW LEVEL SECURITY');
                DB::statement('ALTER TABLE selection_participants FORCE ROW LEVEL SECURITY');
            }
        });
    }

    private function backfill(): void
    {
        $rows = DB::table('selection_participants as selection')
            ->join('participants as participant', 'participant.id', '=', 'selection.participant_id')
            ->orderBy('selection.id')
            ->get([
                'selection.id', 'selection.participant_id', 'selection.created_at',
                'participant.branch_id', 'participant.source_system',
            ]);

        foreach ($rows as $row) {
            if ($row->created_at === null || $row->source_system !== 'SELEKSI_BEASISWA_JEPANG') {
                throw new RuntimeException('historical Selection identity is incomplete');
            }

            $caseId = DB::table('assessment_cases')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'participant_id' => $row->participant_id,
                'organization_id' => $row->branch_id,
                'package_id' => null,
                'origin' => 'LEGACY_SELECTION',
                'intended_field_snapshot' => null,
                'created_at' => $row->created_at,
                'updated_at' => $row->created_at,
            ]);

            DB::table('selection_participants')->where('id', $row->id)
                ->update(['assessment_case_id' => $caseId]);
        }
    }

    private function assertComplete(): void
    {
        if (DB::table('selection_participants')->whereNull('assessment_case_id')->exists()) {
            throw new RuntimeException('not every Selection mapping was bound');
        }

        $distinct = DB::getDriverName() === 'pgsql' ? 'IS DISTINCT FROM' : 'IS NOT';
        $invalid = DB::selectOne(<<<SQL
            SELECT EXISTS (
                SELECT 1 FROM selection_participants selection
                LEFT JOIN assessment_cases assessment_case
                  ON assessment_case.id = selection.assessment_case_id
                LEFT JOIN participants participant ON participant.id = selection.participant_id
                WHERE assessment_case.id IS NULL
                   OR assessment_case.participant_id {$distinct} selection.participant_id
                   OR assessment_case.organization_id {$distinct} participant.branch_id
                   OR assessment_case.origin {$distinct} 'LEGACY_SELECTION'
            ) AS invalid
            SQL)->invalid;
        if ($invalid) {
            throw new RuntimeException('a Selection mapping does not match its case');
        }
    }

    private function enforce(string $driver): void
    {
        $indexes = $this->sqliteSelectionIndexes();
        $this->withoutSqliteSelectionIndexes($driver, $indexes, function () use ($driver): void {
            $this->withoutSqliteReferencingTriggers($driver, [], function (): void {
                Schema::table('selection_participants', function (Blueprint $table): void {
                    $table->unique('assessment_case_id', self::UNIQUE);
                    $table->foreign(['assessment_case_id', 'participant_id'], self::FOREIGN)
                        ->references(['id', 'participant_id'])->on('assessment_cases')->restrictOnDelete();
                    $table->unsignedBigInteger('assessment_case_id')->nullable(false)->change();
                });
            });
        });
        $this->restoreSqliteSelectionIndexes($driver, $indexes);

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION app_private.guard_selection_participant_case_identity() RETURNS trigger
                LANGUAGE plpgsql SET search_path = pg_catalog, public AS $guard$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Selection case identity is immutable' USING ERRCODE = 'P0001';
                    END IF;
                    IF TG_OP = 'UPDATE' AND (
                        NEW.client_id IS DISTINCT FROM OLD.client_id
                        OR NEW.external_candidate_id IS DISTINCT FROM OLD.external_candidate_id
                        OR NEW.selection_round_id IS DISTINCT FROM OLD.selection_round_id
                        OR NEW.registration_id IS DISTINCT FROM OLD.registration_id
                        OR NEW.participant_id IS DISTINCT FROM OLD.participant_id
                        OR NEW.assessment_case_id IS DISTINCT FROM OLD.assessment_case_id
                        OR NEW.idempotency_key IS DISTINCT FROM OLD.idempotency_key
                        OR NEW.request_hash IS DISTINCT FROM OLD.request_hash
                        OR NEW.created_at IS DISTINCT FROM OLD.created_at
                    ) THEN
                        RAISE EXCEPTION 'Selection case identity is immutable' USING ERRCODE = 'P0001';
                    END IF;
                    IF NOT EXISTS (
                        SELECT 1 FROM public.assessment_cases assessment_case
                        WHERE assessment_case.id = NEW.assessment_case_id
                          AND assessment_case.participant_id = NEW.participant_id
                          AND assessment_case.origin = 'LEGACY_SELECTION'
                    ) THEN
                        RAISE EXCEPTION 'Selection mapping requires an exact legacy case' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END;
                $guard$;
                CREATE TRIGGER selection_participants_case_identity_guard
                BEFORE INSERT OR UPDATE OR DELETE ON selection_participants
                FOR EACH ROW EXECUTE FUNCTION app_private.guard_selection_participant_case_identity();
                SQL);

            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER selection_participants_case_insert_guard
            BEFORE INSERT ON selection_participants FOR EACH ROW
            WHEN NOT EXISTS (
                SELECT 1 FROM assessment_cases assessment_case
                WHERE assessment_case.id = NEW.assessment_case_id
                  AND assessment_case.participant_id = NEW.participant_id
                  AND assessment_case.origin = 'LEGACY_SELECTION'
            )
            BEGIN SELECT RAISE(ABORT, 'Selection mapping requires an exact legacy case'); END;
            CREATE TRIGGER selection_participants_case_update_guard
            BEFORE UPDATE ON selection_participants FOR EACH ROW
            WHEN NEW.client_id IS NOT OLD.client_id
              OR NEW.external_candidate_id IS NOT OLD.external_candidate_id
              OR NEW.selection_round_id IS NOT OLD.selection_round_id
              OR NEW.registration_id IS NOT OLD.registration_id
              OR NEW.participant_id IS NOT OLD.participant_id
              OR NEW.assessment_case_id IS NOT OLD.assessment_case_id
              OR NEW.idempotency_key IS NOT OLD.idempotency_key
              OR NEW.request_hash IS NOT OLD.request_hash
              OR NEW.created_at IS NOT OLD.created_at
            BEGIN SELECT RAISE(ABORT, 'Selection case identity is immutable'); END;
            CREATE TRIGGER selection_participants_case_delete_guard
            BEFORE DELETE ON selection_participants FOR EACH ROW
            BEGIN SELECT RAISE(ABORT, 'Selection case identity is immutable'); END;
            SQL);
    }

    private function removeEnforcement(string $driver): void
    {
        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS selection_participants_case_identity_guard ON selection_participants');
            DB::unprepared('DROP FUNCTION IF EXISTS app_private.guard_selection_participant_case_identity()');
        } else {
            DB::unprepared('DROP TRIGGER IF EXISTS selection_participants_case_insert_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS selection_participants_case_update_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS selection_participants_case_delete_guard');
        }

        Schema::table('selection_participants', function (Blueprint $table): void {
            $table->dropForeign(DB::getDriverName() === 'sqlite'
                ? ['assessment_case_id', 'participant_id']
                : self::FOREIGN);
            $table->dropUnique(self::UNIQUE);
        });
    }

    /** @param list<string> $excluded
     * @return list<array{name:string,sql:string}>
     */
    private function sqliteSelectionIndexes(array $excluded = []): array
    {
        if (DB::getDriverName() !== 'sqlite') {
            return [];
        }

        return array_values(collect(DB::select(<<<'SQL'
            SELECT name, sql FROM sqlite_master
            WHERE type = 'index' AND tbl_name = 'selection_participants' AND sql IS NOT NULL
            ORDER BY name
        SQL))->map(fn (object $index): array => (array) $index)
            ->reject(fn (array $index): bool => in_array((string) $index['name'], $excluded, true))
            ->map(fn (array $index): array => ['name' => (string) $index['name'], 'sql' => (string) $index['sql']])
            ->all());
    }

    /** @param list<array{name:string,sql:string}> $indexes */
    private function withoutSqliteSelectionIndexes(string $driver, array $indexes, callable $operation): void
    {
        if ($driver !== 'sqlite') {
            $operation();

            return;
        }
        foreach ($indexes as $index) {
            DB::statement('DROP INDEX "'.str_replace('"', '""', $index['name']).'"');
        }
        $operation();
    }

    /** @param list<array{name:string,sql:string}> $indexes */
    private function restoreSqliteSelectionIndexes(string $driver, array $indexes): void
    {
        if ($driver !== 'sqlite') {
            return;
        }
        $existing = collect(DB::select("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='selection_participants'"))
            ->pluck('name')->all();
        foreach ($indexes as $index) {
            if (! in_array($index['name'], $existing, true)
                && DB::connection()->getPdo()->exec($index['sql']) === false) {
                throw new RuntimeException('Failed to restore a selection participant index.');
            }
        }
    }

    /** @param list<string> $excluded */
    private function withoutSqliteReferencingTriggers(string $driver, array $excluded, callable $operation): void
    {
        if ($driver !== 'sqlite') {
            $operation();

            return;
        }
        $triggers = collect(DB::select(<<<'SQL'
            SELECT name, sql FROM sqlite_master
            WHERE type = 'trigger' AND sql IS NOT NULL
              AND lower(sql) LIKE '%selection_participants%'
            ORDER BY name
        SQL))->map(fn (object $trigger): array => (array) $trigger)
            ->reject(fn (array $trigger): bool => in_array((string) $trigger['name'], $excluded, true))
            ->all();
        foreach ($triggers as $trigger) {
            DB::statement('DROP TRIGGER "'.str_replace('"', '""', $trigger['name']).'"');
        }
        try {
            $operation();
        } finally {
            foreach ($triggers as $trigger) {
                if (DB::connection()->getPdo()->exec($trigger['sql']) === false) {
                    throw new RuntimeException('Failed to restore a selection-participant-referencing SQLite trigger.');
                }
            }
        }
    }
};
