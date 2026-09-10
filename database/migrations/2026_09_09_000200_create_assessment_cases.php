<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string ROLLBACK_ERROR = 'Assessment case history or bindings prevent rollback.';

    public function up(): void
    {
        Schema::create('assessment_cases', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id');
            $table->unsignedBigInteger('participant_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('package_id')->nullable();
            $table->string('origin', 24);
            $table->string('intended_field_snapshot', 24)->nullable();
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);

            $table->unique('public_id', 'assessment_cases_public_id_unique');
            $table->index('participant_id', 'assessment_cases_participant_idx');
            $table->index('organization_id', 'assessment_cases_organization_idx');
            $table->index('package_id', 'assessment_cases_package_idx');
            $table->foreign(['participant_id', 'organization_id'], 'assessment_cases_participant_scope_fk')
                ->references(['id', 'branch_id'])->on('participants')->restrictOnDelete();
            $table->foreign('organization_id', 'assessment_cases_organization_fk')
                ->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('package_id', 'assessment_cases_package_fk')
                ->references('id')->on('packages')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            $this->addPostgresLinks();
            $this->addPostgresContract();
        } elseif (DB::getDriverName() === 'sqlite') {
            $this->addSqliteLinks();
            $this->addSqliteContract();
        } else {
            throw new RuntimeException('Assessment cases require PostgreSQL or the SQLite test database.');
        }
    }

    private function addPostgresLinks(): void
    {
        Schema::table('assessment_participants', function (Blueprint $table): void {
            $table->unsignedBigInteger('assessment_case_id')->nullable();
            $table->unique('assessment_case_id', 'assessment_participants_case_unique');
            $table->foreign('assessment_case_id', 'assessment_participants_case_fk')
                ->references('id')->on('assessment_cases')->restrictOnDelete();
        });
        Schema::table('test_sessions', function (Blueprint $table): void {
            $table->unsignedBigInteger('assessment_case_id')->nullable();
            $table->index('assessment_case_id', 'test_sessions_assessment_case_idx');
            $table->foreign('assessment_case_id', 'test_sessions_assessment_case_fk')
                ->references('id')->on('assessment_cases')->restrictOnDelete();
        });
    }

    private function addSqliteLinks(): void
    {
        // Native ADD COLUMN avoids Laravel's SQLite table rebuild, which would
        // temporarily invalidate the existing cross-table session triggers.
        DB::statement('ALTER TABLE assessment_participants ADD COLUMN assessment_case_id INTEGER NULL REFERENCES assessment_cases(id) ON DELETE RESTRICT');
        DB::statement('CREATE UNIQUE INDEX assessment_participants_case_unique ON assessment_participants (assessment_case_id)');
        DB::statement('ALTER TABLE test_sessions ADD COLUMN assessment_case_id INTEGER NULL REFERENCES assessment_cases(id) ON DELETE RESTRICT');
        DB::statement('CREATE INDEX test_sessions_assessment_case_idx ON test_sessions (assessment_case_id)');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->downPostgres();

            return;
        }

        if ($this->historyOrBindingsExist()) {
            throw new RuntimeException(self::ROLLBACK_ERROR);
        }

        $this->dropPhaseOneSchema();
    }

    private function addPostgresContract(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE assessment_cases
                ADD CONSTRAINT assessment_cases_public_id_check CHECK (
                    public_id ~ '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'
                ),
                ADD CONSTRAINT assessment_cases_origin_check CHECK (
                    origin IN ('DIRECT_PUBLIC','LEGACY_SELECTION','INTEGRATED')
                ),
                ADD CONSTRAINT assessment_cases_intended_field_check CHECK (
                    intended_field_snapshot IS NULL OR intended_field_snapshot IN (
                        'KAIGO','KENSETSU','NOUGYOU','SEIZOU','GAISHOKU','UMUM'
                    )
                )
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION guard_assessment_case_history() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'assessment case history is immutable';
                END IF;

                IF NEW.public_id IS DISTINCT FROM OLD.public_id
                    OR NEW.participant_id IS DISTINCT FROM OLD.participant_id
                    OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
                    OR NEW.origin IS DISTINCT FROM OLD.origin
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                    OR NEW.updated_at IS NULL
                    OR OLD.updated_at IS NULL
                    OR NEW.updated_at <= OLD.updated_at
                    OR NOT (
                        (NEW.package_id IS NOT DISTINCT FROM OLD.package_id)
                        OR (OLD.package_id IS NULL AND NEW.package_id IS NOT NULL)
                    )
                    OR NOT (
                        (NEW.intended_field_snapshot IS NOT DISTINCT FROM OLD.intended_field_snapshot)
                        OR (OLD.intended_field_snapshot IS NULL AND NEW.intended_field_snapshot IS NOT NULL)
                    )
                    OR (
                        NEW.package_id IS NOT DISTINCT FROM OLD.package_id
                        AND NEW.intended_field_snapshot IS NOT DISTINCT FROM OLD.intended_field_snapshot
                    )
                THEN
                    RAISE EXCEPTION 'assessment case history is immutable';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER assessment_cases_history_guard
                BEFORE UPDATE OR DELETE ON assessment_cases
                FOR EACH ROW EXECUTE FUNCTION guard_assessment_case_history()
            SQL);

        DB::statement('REVOKE ALL PRIVILEGES ON assessment_cases FROM psikotes_runtime');
        DB::statement('REVOKE ALL PRIVILEGES ON SEQUENCE assessment_cases_id_seq FROM psikotes_runtime');
        DB::statement('GRANT SELECT, INSERT, UPDATE ON assessment_cases TO psikotes_runtime');
        DB::statement('GRANT USAGE, SELECT ON SEQUENCE assessment_cases_id_seq TO psikotes_runtime');
        DB::statement('ALTER TABLE assessment_cases ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE assessment_cases FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY assessment_cases_service_read ON assessment_cases
                FOR SELECT TO psikotes_runtime
                USING (app_private.app_role() = 'service')
            SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY assessment_cases_service_insert ON assessment_cases
                FOR INSERT TO psikotes_runtime
                WITH CHECK (app_private.app_role() = 'service')
            SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY assessment_cases_service_update ON assessment_cases
                FOR UPDATE TO psikotes_runtime
                USING (app_private.app_role() = 'service')
                WITH CHECK (app_private.app_role() = 'service')
            SQL);
    }

    private function addSqliteContract(): void
    {
        $rowIsValid = <<<'SQL'
            length(NEW.public_id) = 26
            AND substr(NEW.public_id, 1, 1) GLOB '[0-7]'
            AND NEW.public_id NOT GLOB '*[^0-9A-HJKMNP-TV-Z]*'
            AND NEW.origin IN ('DIRECT_PUBLIC','LEGACY_SELECTION','INTEGRATED')
            AND (
                NEW.intended_field_snapshot IS NULL
                OR NEW.intended_field_snapshot IN ('KAIGO','KENSETSU','NOUGYOU','SEIZOU','GAISHOKU','UMUM')
            )
            AND NEW.created_at IS NOT NULL
            AND NEW.updated_at IS NOT NULL
            SQL;
        DB::unprepared("CREATE TRIGGER assessment_cases_contract_insert
            BEFORE INSERT ON assessment_cases WHEN COALESCE(({$rowIsValid}), 0) = 0
            BEGIN SELECT RAISE(ABORT, 'assessment case contract violation'); END");
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER assessment_cases_history_update
            BEFORE UPDATE ON assessment_cases WHEN
                NEW.public_id IS NOT OLD.public_id
                OR NEW.participant_id IS NOT OLD.participant_id
                OR NEW.organization_id IS NOT OLD.organization_id
                OR NEW.origin IS NOT OLD.origin
                OR NEW.created_at IS NOT OLD.created_at
                OR NEW.updated_at IS NULL
                OR OLD.updated_at IS NULL
                OR NEW.updated_at <= OLD.updated_at
                OR NOT (
                    NEW.package_id IS OLD.package_id
                    OR (OLD.package_id IS NULL AND NEW.package_id IS NOT NULL)
                )
                OR NOT (
                    NEW.intended_field_snapshot IS OLD.intended_field_snapshot
                    OR (OLD.intended_field_snapshot IS NULL AND NEW.intended_field_snapshot IS NOT NULL)
                )
                OR (
                    NEW.package_id IS OLD.package_id
                    AND NEW.intended_field_snapshot IS OLD.intended_field_snapshot
                )
            BEGIN SELECT RAISE(ABORT, 'assessment case history is immutable'); END;
            CREATE TRIGGER assessment_cases_history_delete
            BEFORE DELETE ON assessment_cases
            BEGIN SELECT RAISE(ABORT, 'assessment case history is immutable'); END
            SQL);
    }

    private function downPostgres(): void
    {
        DB::transaction(function (): void {
            DB::statement(<<<'SQL'
                LOCK TABLE assessment_cases, assessment_participants, test_sessions
                    IN ACCESS EXCLUSIVE MODE
                SQL);

            // The migration owner temporarily observes complete tables. An exception rolls
            // these changes back, preserving FORCE RLS and every pre-existing policy.
            foreach (['assessment_cases', 'assessment_participants', 'test_sessions'] as $table) {
                DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
            }

            if ($this->historyOrBindingsExist()) {
                throw new RuntimeException(self::ROLLBACK_ERROR);
            }

            DB::unprepared('DROP FUNCTION IF EXISTS guard_assessment_case_history() CASCADE');
            $this->dropPhaseOneSchema();

            foreach (['assessment_participants', 'test_sessions'] as $table) {
                DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            }
        });
    }

    private function historyOrBindingsExist(): bool
    {
        return DB::table('assessment_cases')->exists()
            || DB::table('assessment_participants')->whereNotNull('assessment_case_id')->exists()
            || DB::table('test_sessions')->whereNotNull('assessment_case_id')->exists();
    }

    private function dropPhaseOneSchema(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->dropSqlitePhaseOneSchema();

            return;
        }

        Schema::table('test_sessions', function (Blueprint $table): void {
            $table->dropForeign('test_sessions_assessment_case_fk');
            $table->dropIndex('test_sessions_assessment_case_idx');
            $table->dropColumn('assessment_case_id');
        });
        Schema::table('assessment_participants', function (Blueprint $table): void {
            $table->dropForeign('assessment_participants_case_fk');
            $table->dropUnique('assessment_participants_case_unique');
            $table->dropColumn('assessment_case_id');
        });
        Schema::drop('assessment_cases');
    }

    private function dropSqlitePhaseOneSchema(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('SQLite assessment case rollback requires no surrounding transaction.');
        }

        $foreignKeys = (bool) DB::scalar('PRAGMA foreign_keys');
        Schema::disableForeignKeyConstraints();
        try {
            DB::transaction(function (): void {
                $dependentTriggers = collect(DB::select(<<<'SQL'
                    SELECT name, sql FROM sqlite_master
                    WHERE type = 'trigger' AND sql IS NOT NULL
                      AND (lower(sql) LIKE '%assessment_participants%'
                        OR lower(sql) LIKE '%test_sessions%')
                    ORDER BY name
                    SQL));
                foreach ($dependentTriggers as $trigger) {
                    $name = str_replace('"', '""', (string) $trigger->name);
                    DB::statement('DROP TRIGGER "'.$name.'"');
                }

                $this->rebuildSqliteTableWithoutAssessmentCase('test_sessions');
                $this->rebuildSqliteTableWithoutAssessmentCase('assessment_participants');
                Schema::drop('assessment_cases');

                foreach ($dependentTriggers as $trigger) {
                    $this->executeSqliteSchemaSql((string) $trigger->sql);
                }
                if (DB::select('PRAGMA foreign_key_check') !== []) {
                    throw new RuntimeException('Assessment case rollback would violate existing foreign keys.');
                }
            });
        } finally {
            if ($foreignKeys) {
                Schema::enableForeignKeyConstraints();
            }
        }
    }

    private function rebuildSqliteTableWithoutAssessmentCase(string $table): void
    {
        $createSql = DB::table('sqlite_master')
            ->where('type', 'table')
            ->where('name', $table)
            ->value('sql');
        if (! is_string($createSql)) {
            throw new RuntimeException("SQLite table {$table} is missing during assessment case rollback.");
        }

        $column = ', assessment_case_id INTEGER NULL REFERENCES assessment_cases(id) ON DELETE RESTRICT';
        $withoutColumn = str_replace($column, '', $createSql, $replacements);
        if ($replacements === 0) {
            $columnReplacements = 0;
            $withoutColumn = preg_replace(
                '/,\s*foreign key\("assessment_case_id"\) references assessment_cases\("id"\) on delete restrict on update no action/i',
                '',
                $createSql,
                1,
                $foreignReplacements,
            );
            $withoutColumn = is_string($withoutColumn) ? preg_replace(
                '/,\s*"assessment_case_id"\s+integer/i',
                '',
                $withoutColumn,
                1,
                $columnReplacements,
            ) : null;
            if (! is_string($withoutColumn) || $foreignReplacements !== 1 || $columnReplacements !== 1) {
                throw new RuntimeException("SQLite table {$table} has an unexpected assessment case definition.");
            }
        } elseif ($replacements !== 1) {
            throw new RuntimeException("SQLite table {$table} has an unexpected assessment case definition.");
        }

        $temporary = $table.'_without_assessment_case';
        $quotedTable = '"'.str_replace('"', '""', $table).'"';
        $quotedTemporary = '"'.str_replace('"', '""', $temporary).'"';
        $temporarySql = preg_replace(
            '/^CREATE TABLE\s+(?:"'.preg_quote($table, '/').'"|`'.preg_quote($table, '/').'`|\['.preg_quote($table, '/').'\]|'.preg_quote($table, '/').')/i',
            'CREATE TABLE '.$quotedTemporary,
            $withoutColumn,
            1,
            $renamed,
        );
        if (! is_string($temporarySql) || $renamed !== 1) {
            throw new RuntimeException("SQLite table {$table} could not be prepared for assessment case rollback.");
        }

        $indexes = DB::select(<<<'SQL'
            SELECT sql FROM sqlite_master
            WHERE type = 'index' AND tbl_name = ? AND sql IS NOT NULL
              AND lower(sql) NOT LIKE '%assessment_case_id%'
            ORDER BY name
            SQL, [$table]);
        $columns = collect(DB::select("PRAGMA table_info({$quotedTable})"))
            ->pluck('name')
            ->reject(fn (string $name): bool => $name === 'assessment_case_id')
            ->map(fn (string $name): string => '"'.str_replace('"', '""', $name).'"')
            ->implode(', ');

        $this->executeSqliteSchemaSql($temporarySql);
        DB::statement("INSERT INTO {$quotedTemporary} ({$columns}) SELECT {$columns} FROM {$quotedTable}");
        DB::statement("DROP TABLE {$quotedTable}");
        DB::statement("ALTER TABLE {$quotedTemporary} RENAME TO {$quotedTable}");
        foreach ($indexes as $index) {
            $this->executeSqliteSchemaSql((string) $index->sql);
        }
    }

    private function executeSqliteSchemaSql(string $sql): void
    {
        if (DB::connection()->getPdo()->exec($sql) === false) {
            throw new RuntimeException('SQLite assessment case schema restoration failed.');
        }
    }
};
