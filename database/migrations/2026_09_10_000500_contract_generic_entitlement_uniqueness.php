<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const LEGACY = 'entitlements_participant_id_test_type_unique';

    private const CASE_UNIQUE = 'entitlements_case_test_type_unique';

    private const DASS_UNIQUE = 'entitlements_dass_participant_unique';

    public function up(): void
    {
        $this->wrap(function (): void {
            $driver = DB::getDriverName();
            if ($driver === 'pgsql') {
                DB::statement('LOCK TABLE entitlements IN ACCESS EXCLUSIVE MODE');
            }
            $this->assertExactRequirementState();
            $this->assertExactCaseUnique($driver);
            $legacy = $this->legacyState($driver);
            $dass = $this->dassState($driver);
            if ($legacy === 'absent' && $dass === 'exact') {
                return;
            }
            if ($legacy !== 'exact' || $dass !== 'absent') {
                $this->abort('counterfeit or partial uniqueness state');
            }
            $this->assertCompatibleHistory();
            DB::statement('CREATE UNIQUE INDEX '.self::DASS_UNIQUE." ON entitlements (participant_id) WHERE test_type = 'dass21' AND assessment_case_id IS NULL");
            if ($driver === 'pgsql') {
                DB::statement('ALTER TABLE entitlements DROP CONSTRAINT '.self::LEGACY);
            } else {
                DB::statement('DROP INDEX '.self::LEGACY);
            }
            if ($this->legacyState($driver) !== 'absent' || $this->dassState($driver) !== 'exact') {
                $this->abort('contracted uniqueness was not installed exactly');
            }
        });
    }

    public function down(): void
    {
        $this->wrap(function (): void {
            $driver = DB::getDriverName();
            if ($driver === 'pgsql') {
                DB::statement('LOCK TABLE entitlements IN ACCESS EXCLUSIVE MODE');
            }
            $this->assertExactRequirementState();
            $this->assertExactCaseUnique($driver);
            $legacy = $this->legacyState($driver);
            $dass = $this->dassState($driver);
            if ($legacy === 'exact' && $dass === 'absent') {
                return;
            }
            if ($legacy !== 'absent' || $dass !== 'exact') {
                $this->abort('counterfeit or partial uniqueness state');
            }
            if (DB::table('entitlements')
                ->select(['participant_id', 'test_type'])
                ->groupBy(['participant_id', 'test_type'])
                ->havingRaw('COUNT(*) > 1')->exists()) {
                $this->abort('multi-case entitlement history prevents rollback');
            }
            if ($driver === 'pgsql') {
                DB::statement('ALTER TABLE entitlements ADD CONSTRAINT '.self::LEGACY.' UNIQUE (participant_id, test_type)');
            } else {
                DB::statement('CREATE UNIQUE INDEX '.self::LEGACY.' ON entitlements (participant_id, test_type)');
            }
            DB::statement('DROP INDEX '.self::DASS_UNIQUE);
            if ($this->legacyState($driver) !== 'exact' || $this->dassState($driver) !== 'absent') {
                $this->abort('legacy uniqueness was not restored exactly');
            }
        });
    }

    private function assertExactRequirementState(): void
    {
        $migration = require __DIR__.'/2026_09_10_000400_enforce_generic_entitlement_case_identity.php';
        if (! $migration instanceof Migration) {
            $this->abort('requirement migration is unavailable');
        }
        $driver = DB::getDriverName();
        $requirement = (new ReflectionMethod($migration, 'requirementState'))->invoke($migration, $driver);
        $guard = (new ReflectionMethod($migration, 'guardState'))->invoke($migration, $driver);
        if ($requirement !== 'exact' || $guard !== 'upgraded') {
            $this->abort('000400 requirement and graph guard state is not exact');
        }
    }

    private function assertCompatibleHistory(): void
    {
        if (DB::table('entitlements')->where('test_type', 'dass21')
            ->select('participant_id')->groupBy('participant_id')->havingRaw('COUNT(*) > 1')->exists()) {
            $this->abort('duplicate DASS entitlement history');
        }
    }

    private function assertExactCaseUnique(string $driver): void
    {
        if ($driver === 'pgsql') {
            $definition = DB::scalar("SELECT indexdef FROM pg_indexes WHERE schemaname='public' AND tablename='entitlements' AND indexname=?", [self::CASE_UNIQUE]);
            $expected = 'CREATE UNIQUE INDEX '.self::CASE_UNIQUE.' ON public.entitlements USING btree (assessment_case_id, test_type) WHERE (assessment_case_id IS NOT NULL)';
            if (! is_string($definition) || $this->normalize($definition) !== $this->normalize($expected)) {
                $this->abort('case-scoped uniqueness is not exact');
            }

            return;
        }
        if ($this->sqliteIndexState(self::CASE_UNIQUE, ['assessment_case_id', 'test_type'], true,
            'assessment_case_id is not null') !== 'exact') {
            $this->abort('case-scoped uniqueness is not exact');
        }
    }

    /** @return 'absent'|'exact'|'counterfeit' */
    private function legacyState(string $driver): string
    {
        if ($driver === 'pgsql') {
            $rows = DB::select("SELECT contype,condeferrable,condeferred,convalidated,pg_get_constraintdef(oid,false) definition FROM pg_constraint WHERE conrelid='entitlements'::regclass AND conname=?", [self::LEGACY]);
            if ($rows === []) {
                return DB::scalar('SELECT to_regclass(?)', ['public.'.self::LEGACY]) === null
                    ? 'absent' : 'counterfeit';
            }
            if (count($rows) !== 1) {
                return 'counterfeit';
            }
            $row = (array) $rows[0];

            return $row['contype'] === 'u' && ! $row['condeferrable'] && ! $row['condeferred']
                && $row['convalidated'] && (string) $row['definition'] === 'UNIQUE (participant_id, test_type)'
                ? 'exact' : 'counterfeit';
        }

        return $this->sqliteIndexState(self::LEGACY, ['participant_id', 'test_type'], true, null);
    }

    /** @return 'absent'|'exact'|'counterfeit' */
    private function dassState(string $driver): string
    {
        if ($driver === 'pgsql') {
            $rows = DB::select("SELECT indexdef FROM pg_indexes WHERE schemaname='public' AND tablename='entitlements' AND indexname=?", [self::DASS_UNIQUE]);
            if ($rows === []) {
                return 'absent';
            }
            $expected = 'CREATE UNIQUE INDEX '.self::DASS_UNIQUE." ON public.entitlements USING btree (participant_id) WHERE (((test_type)::text = 'dass21'::text) AND (assessment_case_id IS NULL))";

            return count($rows) === 1 && $this->normalize((string) $rows[0]->indexdef) === $this->normalize($expected)
                ? 'exact' : 'counterfeit';
        }

        return $this->sqliteIndexState(self::DASS_UNIQUE, ['participant_id'], true,
            "test_type = 'dass21' and assessment_case_id is null");
    }

    /** @param list<string> $columns
     * @return 'absent'|'exact'|'counterfeit'
     */
    private function sqliteIndexState(string $name, array $columns, bool $unique, ?string $predicate): string
    {
        $list = collect(DB::select("PRAGMA index_list('entitlements')"))->first(
            fn (object $row): bool => (string) ((array) $row)['name'] === $name,
        );
        if ($list === null) {
            return 'absent';
        }
        $actualColumns = collect(DB::select('PRAGMA index_info("'.str_replace('"', '""', $name).'")'))
            ->sortBy('seqno')->pluck('name')->map(static fn (mixed $value): string => (string) $value)->all();
        $sql = DB::scalar("SELECT sql FROM sqlite_master WHERE type='index' AND name=?", [$name]);
        if (! is_string($sql)) {
            return 'counterfeit';
        }
        $actualPredicate = null;
        if (preg_match('/\bwhere\b(.+)$/i', $sql, $matches) === 1) {
            $actualPredicate = $this->normalize($matches[1]);
        }

        return (bool) ((array) $list)['unique'] === $unique && $actualColumns === $columns
            && $actualPredicate === ($predicate === null ? null : $this->normalize($predicate))
            ? 'exact' : 'counterfeit';
    }

    private function normalize(string $sql): string
    {
        $sql = str_replace(['::text', '::character varying'], '', $sql);

        return strtolower(trim((string) preg_replace('/[\s"()]+/', ' ', $sql)));
    }

    private function wrap(Closure $operation): void
    {
        try {
            DB::transaction(function () use ($operation): void {
                $operation();
                if (DB::getDriverName() === 'sqlite' && DB::select('PRAGMA foreign_key_check') !== []) {
                    $this->abort('SQLite foreign key check failed');
                }
            });
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException
                && str_starts_with($exception->getMessage(), 'Generic entitlement uniqueness migration aborted:')) {
                throw $exception;
            }
            throw new RuntimeException('Generic entitlement uniqueness migration aborted: '.$exception->getMessage(),
                (int) $exception->getCode(), $exception);
        }
    }

    private function abort(string $reason): never
    {
        throw new RuntimeException('Generic entitlement uniqueness migration aborted: '.$reason);
    }
};
