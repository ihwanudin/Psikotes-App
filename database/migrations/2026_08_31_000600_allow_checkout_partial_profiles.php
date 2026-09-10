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
        $dependentTriggers = collect(DB::select(<<<'SQL'
            SELECT name, sql
            FROM sqlite_master
            WHERE type = 'trigger'
              AND tbl_name NOT IN ('participants', 'assessment_participants')
              AND (sql LIKE '%participants%' OR sql LIKE '%assessment_participants%')
              AND sql IS NOT NULL
            ORDER BY name
            SQL));
        foreach ($dependentTriggers as $trigger) {
            $name = str_replace('"', '""', (string) $trigger->name);
            DB::statement('DROP TRIGGER "'.$name.'"');
        }
        if (! $nullable) {
            DB::statement('DROP TRIGGER assessment_participants_checkout_funding_insert');
            DB::statement('DROP TRIGGER assessment_participants_checkout_funding_update');
        }
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
        foreach ($dependentTriggers as $trigger) {
            DB::unprepared((string) $trigger->sql);
        }
    }
};
