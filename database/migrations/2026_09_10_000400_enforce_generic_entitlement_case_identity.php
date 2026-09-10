<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CONSTRAINT = 'entitlements_case_requirement_check';

    private const EXPRESSION = "(test_type = 'dass21' AND assessment_case_id IS NULL) OR (test_type <> 'dass21' AND assessment_case_id IS NOT NULL)";

    public function up(): void
    {
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
                $force = $this->postgresForceRls();
                DB::statement('LOCK TABLE entitlements IN ACCESS EXCLUSIVE MODE');
                try {
                    DB::statement('ALTER TABLE entitlements NO FORCE ROW LEVEL SECURITY');
                    $this->assertHistoryCompatible();
                    DB::statement('ALTER TABLE entitlements ADD CONSTRAINT '.self::CONSTRAINT.' CHECK ('.self::EXPRESSION.') NOT VALID');
                    DB::statement('ALTER TABLE entitlements VALIDATE CONSTRAINT '.self::CONSTRAINT);
                } finally {
                    DB::statement('ALTER TABLE entitlements '.($force ? 'FORCE' : 'NO FORCE').' ROW LEVEL SECURITY');
                }
            } else {
                $this->rebuildSqlite(true);
            }
            if ($this->requirementState($driver) !== 'exact') {
                $this->abort('requirement constraint was not installed exactly');
            }
        });
        $this->assertBaseState();
    }

    public function down(): void
    {
        $this->assertBaseState();
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
                $force = $this->postgresForceRls();
                DB::statement('LOCK TABLE entitlements IN ACCESS EXCLUSIVE MODE');
                try {
                    DB::statement('ALTER TABLE entitlements NO FORCE ROW LEVEL SECURITY');
                    DB::statement('ALTER TABLE entitlements DROP CONSTRAINT '.self::CONSTRAINT);
                } finally {
                    DB::statement('ALTER TABLE entitlements '.($force ? 'FORCE' : 'NO FORCE').' ROW LEVEL SECURITY');
                }
            } else {
                $this->rebuildSqlite(false);
            }
            if ($this->requirementState($driver) !== 'absent') {
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

    private function postgresForceRls(): bool
    {
        $row = DB::selectOne("SELECT relforcerowsecurity FROM pg_class WHERE oid='entitlements'::regclass");
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
