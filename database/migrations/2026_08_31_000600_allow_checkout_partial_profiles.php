<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const array PROFILE_COLUMNS = ['full_name', 'gender', 'birth_date', 'education_level', 'intended_field', 'phone'];

    public function up(): void
    {
        $this->changeNullability(true);
    }

    public function down(): void
    {
        $this->changeNullability(false);
    }

    private function changeNullability(bool $nullable): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('Checkout partial profiles require PostgreSQL or the SQLite test database.');
        }
        $foreignKeys = false;
        if ($driver === 'sqlite') {
            // Laravel rebuilds SQLite tables. PRAGMA foreign_keys must change outside a transaction.
            if (DB::transactionLevel() !== 0) {
                throw new RuntimeException('SQLite profile migration requires no surrounding transaction.');
            }
            $foreignKeys = (bool) DB::scalar('PRAGMA foreign_keys');
            Schema::disableForeignKeyConstraints();
        }
        try {
            DB::transaction(function () use ($nullable, $driver): void {
                if ($driver === 'pgsql') {
                    // Prevent writes between rollback preflight and NOT NULL restoration.
                    DB::statement('LOCK TABLE participants, assessment_participants IN ACCESS EXCLUSIVE MODE');
                }
                if (! $nullable) {
                    if ($driver === 'pgsql') {
                        // Fail rather than treating an RLS-filtered scan as a complete preflight.
                        // This does not bypass RLS: PostgreSQL errors when a policy would apply.
                        DB::statement('SET LOCAL row_security = off');
                    }
                    $this->assertRollbackCompatible();
                }
                if ($driver === 'pgsql') {
                    $operation = $nullable ? 'DROP' : 'SET';
                    $columns = array_map(static fn (string $column): string => "ALTER COLUMN {$column} {$operation} NOT NULL", self::PROFILE_COLUMNS);
                    DB::statement('ALTER TABLE participants '.implode(', ', $columns));
                    DB::statement("ALTER TABLE assessment_participants ALTER COLUMN funding_mode {$operation} NOT NULL");
                    DB::statement($nullable
                        ? "ALTER TABLE assessment_participants ADD CONSTRAINT assessment_participants_checkout_funding_check CHECK (
                            funding_mode IS NOT NULL OR COALESCE(metadata->>'checkout_contract_version' = 'checkout-v2'
                            AND assessment_status IN ('PROVISIONED', 'REVOKED', 'VOID'), FALSE))"
                        : 'ALTER TABLE assessment_participants DROP CONSTRAINT assessment_participants_checkout_funding_check');
                } else {
                    $this->changeSqlite($nullable);
                    if (DB::select('PRAGMA foreign_key_check') !== []) {
                        throw new RuntimeException('Profile migration would violate existing foreign keys.');
                    }
                }
            });
        } finally {
            if ($foreignKeys) {
                Schema::enableForeignKeyConstraints();
            }
        }
    }

    private function assertRollbackCompatible(): void
    {
        $partial = DB::table('participants')->where(function ($query): void {
            foreach (self::PROFILE_COLUMNS as $column) {
                $query->orWhereNull($column);
            }
        })->exists();
        if ($partial || DB::table('assessment_participants')->whereNull('funding_mode')->exists()) {
            throw new RuntimeException('Checkout partial profiles prevent rollback; complete or resolve nullable data explicitly.');
        }
    }

    private function changeSqlite(bool $nullable): void
    {
        // Later SQLite-only contracts (for example the direct-order case guard)
        // read participants while Laravel temporarily renames this table during
        // a column change. Preserve those cross-table objects around both
        // rebuilds; PostgreSQL never enters this path.
        $triggers = $this->sqliteReferencingTriggers();
        $indexes = $this->sqliteProfileIndexes();
        $this->dropSqliteObjects($triggers, $indexes);

        if (! $nullable) {
            DB::statement('DROP TRIGGER assessment_participants_checkout_funding_insert');
            DB::statement('DROP TRIGGER assessment_participants_checkout_funding_update');
        }
        try {
            Schema::table('participants', function (Blueprint $table) use ($nullable): void {
                $table->string('full_name', 200)->nullable($nullable)->change();
                $table->string('gender', 16)->nullable($nullable)->change();
                $table->date('birth_date')->nullable($nullable)->change();
                $table->string('education_level', 64)->nullable($nullable)->change();
                $table->string('intended_field', 24)->nullable($nullable)->change();
                $table->string('phone', 32)->nullable($nullable)->change();
            });
            Schema::table('assessment_participants', function (Blueprint $table) use ($nullable): void {
                $table->string('funding_mode', 40)->nullable($nullable)->change();
            });
            if ($nullable) {
                // SQLite cannot ADD CHECK; enforce the same predicate for INSERT and every UPDATE.
                foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $name => $event) {
                    DB::statement("CREATE TRIGGER assessment_participants_checkout_funding_{$name}
                        BEFORE {$event} ON assessment_participants WHEN NEW.funding_mode IS NULL AND
                        CASE WHEN json_valid(NEW.metadata) THEN COALESCE(
                            json_extract(NEW.metadata, '$.checkout_contract_version') = 'checkout-v2'
                            AND NEW.assessment_status IN ('PROVISIONED', 'REVOKED', 'VOID'), 0) ELSE 0 END = 0
                        BEGIN SELECT RAISE(ABORT, 'assessment_participants_checkout_funding_check'); END");
                }
            }
        } finally {
            $this->restoreSqliteObjects($triggers, $indexes);
        }
    }

    /** @return list<object{name:string,sql:string}> */
    private function sqliteReferencingTriggers(): array
    {
        /** @var list<object{name:string,sql:string}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT name, sql FROM sqlite_master
            WHERE type = 'trigger' AND sql IS NOT NULL
              AND lower(sql) LIKE '%participants%'
              AND name NOT IN (
                'assessment_participants_checkout_funding_insert',
                'assessment_participants_checkout_funding_update'
              )
            SQL);

        return $rows;
    }

    /** @return list<object{name:string,sql:string}> */
    private function sqliteProfileIndexes(): array
    {
        /** @var list<object{name:string,sql:string}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT name, sql FROM sqlite_master
            WHERE type = 'index' AND sql IS NOT NULL
              AND tbl_name IN ('participants', 'assessment_participants')
            SQL);

        return $rows;
    }

    /**
     * @param list<object{name:string,sql:string}> $triggers
     * @param list<object{name:string,sql:string}> $indexes
     */
    private function dropSqliteObjects(array $triggers, array $indexes): void
    {
        foreach ($triggers as $trigger) {
            DB::unprepared('DROP TRIGGER "'.str_replace('"', '""', $trigger->name).'"'); // @phpstan-ignore argument.type (trigger name is read back verbatim from sqlite_master, not user input)
        }
        foreach ($indexes as $index) {
            DB::unprepared('DROP INDEX "'.str_replace('"', '""', $index->name).'"'); // @phpstan-ignore argument.type (index name is read back verbatim from sqlite_master, not user input)
        }
    }

    /**
     * @param list<object{name:string,sql:string}> $triggers
     * @param list<object{name:string,sql:string}> $indexes
     */
    private function restoreSqliteObjects(array $triggers, array $indexes): void
    {
        foreach ($indexes as $index) {
            DB::unprepared($index->sql); // @phpstan-ignore argument.type (DDL text is read back verbatim from sqlite_master, not user input)
        }
        foreach ($triggers as $trigger) {
            DB::unprepared($trigger->sql); // @phpstan-ignore argument.type (DDL text is read back verbatim from sqlite_master, not user input)
        }
    }
};
