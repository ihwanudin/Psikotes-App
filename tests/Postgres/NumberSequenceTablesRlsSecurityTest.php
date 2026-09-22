<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\ReportRendering\ReportNumberIssuer;
use App\Services\TestNumber\MonthlyTestNumberIssuer;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

/**
 * RLS-GAP-05/06 remediation (tasks/handoffs/f2/database-rls-coverage-audit.md,
 * Group B -- Lead sign-off). PostgreSQL proof for
 * database/migrations/2026_09_22_000100_harden_number_sequence_tables_rls.php.
 *
 * Applies the same lessons PR #95 (Group A) learned the hard way: exception
 * assertions wrap the WHOLE run()/runAsService() call (never nest inside
 * its closure, or a failed statement's savepoint can't cleanly RELEASE
 * under this class's own outer transaction isolation), and UPDATE-denial
 * is proven by affected-row-count + unchanged value, not by expecting an
 * exception (PostgreSQL's UPDATE policy is a USING/visibility check on
 * existing rows, not a WITH CHECK on a new one -- a row failing it is
 * silently excluded from the UPDATE's target set).
 */
final class NumberSequenceTablesRlsSecurityTest extends TestCase
{
    private const TABLES = [
        'report_number_sequences',
        'test_number_sequences',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        Date::setTestNow();
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_runtime_has_forced_service_only_rls_and_least_privilege(): void
    {
        foreach (self::TABLES as $table) {
            $identity = DB::selectOne(
                "SELECT relrowsecurity, relforcerowsecurity, pg_get_userbyid(relowner) AS table_owner FROM pg_class WHERE oid = '{$table}'::regclass",
            );
            $this->assertTrue($identity->relrowsecurity, $table);
            $this->assertTrue($identity->relforcerowsecurity, $table);
            $this->assertNotSame('psikotes_runtime', $identity->table_owner, $table);

            foreach (['SELECT', 'INSERT', 'UPDATE'] as $privilege) {
                $this->assertTrue((bool) DB::scalar(
                    "SELECT has_table_privilege('psikotes_runtime', ?, ?)",
                    [$table, $privilege],
                ), "{$table}: {$privilege}");
            }
            foreach (['DELETE', 'TRUNCATE', 'TRIGGER', 'REFERENCES'] as $privilege) {
                $this->assertFalse((bool) DB::scalar(
                    "SELECT has_table_privilege('psikotes_runtime', ?, ?)",
                    [$table, $privilege],
                ), "{$table}: {$privilege}");
            }

            $policies = DB::table('pg_policies')
                ->where('schemaname', 'public')->where('tablename', $table)
                ->orderBy('policyname')->get();
            $this->assertSame([
                "{$table}_service_insert", "{$table}_service_select", "{$table}_service_update",
            ], $policies->pluck('policyname')->all(), $table);
            foreach ($policies as $policy) {
                $this->assertSame('{psikotes_runtime}', $policy->roles, $table);
                $this->assertStringContainsString(
                    "app_private.app_role() = 'service'",
                    ($policy->qual ?? '').($policy->with_check ?? ''),
                    $table,
                );
            }
        }
    }

    public function test_participant_context_cannot_read_or_write_either_table_directly(): void
    {
        foreach (self::TABLES as $table) {
            app(RlsContextRunner::class)->runAsService(fn () => DB::table($table)->insert([
                'period' => '999912', 'last_value' => 5, 'created_at' => now(), 'updated_at' => now(),
            ]));

            app(RlsContextRunner::class)->run(
                new RlsContext('participant', 1, 1),
                fn () => $this->assertSame(0, DB::table($table)->count(), $table),
            );

            $this->assertSqlState('42501', fn () => app(RlsContextRunner::class)->run(
                new RlsContext('participant', 1, 1),
                fn () => DB::table($table)->insert(['period' => '999911', 'last_value' => 1, 'created_at' => now(), 'updated_at' => now()]),
            ));

            // UPDATE does not throw the way INSERT does under RLS -- a row
            // failing the USING check is just excluded from the target set
            // (0 rows affected, no exception), unlike INSERT's WITH CHECK,
            // which has no "hide it instead" option.
            $affected = app(RlsContextRunner::class)->run(
                new RlsContext('participant', 1, 1),
                fn () => DB::table($table)->where('period', '999912')->update(['last_value' => 999]),
            );
            $this->assertSame(0, $affected, $table);
            $value = app(RlsContextRunner::class)->runAsService(
                fn () => DB::table($table)->where('period', '999912')->value('last_value'),
            );
            $this->assertSame(5, $value, $table);
        }
    }

    public function test_the_real_prepare_month_command_writes_under_postgres(): void
    {
        // A synthetic far-future period, not "today" -- using the real
        // current month collided with another Postgres test that writes to
        // test_number_sequences under the real wall-clock date and actually
        // commits, so running the full suite (not just this class in
        // isolation) leaked a nonzero starting last_value into this test.
        Date::setTestNow('2099-08-15 12:00:00+00:00');

        $exitCode = Artisan::call('test-numbers:prepare-month');

        $this->assertSame(Command::SUCCESS, $exitCode);
        $row = app(RlsContextRunner::class)->runAsService(
            fn () => DB::table('test_number_sequences')->where('period', '209908')->first(),
        );
        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row->last_value);

        // Idempotent -- re-running must not reset an already-issued value.
        app(RlsContextRunner::class)->runAsService(
            fn () => DB::table('test_number_sequences')->where('period', '209908')->update(['last_value' => 3]),
        );
        Artisan::call('test-numbers:prepare-month');
        $unchanged = app(RlsContextRunner::class)->runAsService(
            fn () => DB::table('test_number_sequences')->where('period', '209908')->value('last_value'),
        );
        $this->assertSame(3, $unchanged);
    }

    public function test_the_real_issuers_write_sequentially_under_a_service_context(): void
    {
        // Same synthetic far-future period as the test above, for the same
        // real-wall-clock-collision reason.
        Date::setTestNow('2099-08-15 12:00:00+00:00');

        [$testFirst, $testSecond] = app(RlsContextRunner::class)->runAsService(fn (): array => [
            app(MonthlyTestNumberIssuer::class)->issue(),
            app(MonthlyTestNumberIssuer::class)->issue(),
        ]);
        $this->assertMatchesRegularExpression('/^LSI-209908-000001-[A-Z0-9]{6}$/', $testFirst);
        $this->assertMatchesRegularExpression('/^LSI-209908-000002-[A-Z0-9]{6}$/', $testSecond);

        [$reportFirst, $reportSecond] = app(RlsContextRunner::class)->runAsService(fn (): array => [
            app(ReportNumberIssuer::class)->issue(),
            app(ReportNumberIssuer::class)->issue(),
        ]);
        $this->assertSame('HPP/2099/08/0001', $reportFirst);
        $this->assertSame('HPP/2099/08/0002', $reportSecond);
    }

    public function test_migration_up_down_up_cycle_round_trips_cleanly(): void
    {
        $this->asOwner(function (): void {
            $migration = require database_path('migrations/2026_09_22_000100_harden_number_sequence_tables_rls.php');

            DB::beginTransaction();
            try {
                $migration->down();
                foreach (self::TABLES as $table) {
                    $this->assertFalse(DB::selectOne(
                        "SELECT relrowsecurity FROM pg_class WHERE oid = '{$table}'::regclass",
                    )->relrowsecurity, $table);
                }
                $migration->up();
                foreach (self::TABLES as $table) {
                    $this->assertTrue(DB::selectOne(
                        "SELECT relforcerowsecurity FROM pg_class WHERE oid = '{$table}'::regclass",
                    )->relforcerowsecurity, $table);
                }
            } finally {
                DB::rollBack();
            }
        });
    }

    private function assertSqlState(string $state, callable $operation): void
    {
        try {
            $operation();
            $this->fail("Expected SQLSTATE {$state}.");
        } catch (QueryException $exception) {
            $this->assertSame($state, $exception->errorInfo[0] ?? null, $exception->getMessage());
        }
    }

    private function asOwner(callable $callback): void
    {
        $runtime = DB::getDefaultConnection();
        $config = config('database.connections.'.$runtime);
        config()->set('database.connections.number_sequence_owner', [
            ...$config,
            'username' => 'org_test_owner',
        ]);
        DB::setDefaultConnection('number_sequence_owner');
        Schema::clearResolvedInstance('db.schema');

        try {
            $this->assertSame('org_test_owner', DB::selectOne('SELECT current_user AS name')->name);
            $callback();
        } finally {
            DB::setDefaultConnection($runtime);
            Schema::clearResolvedInstance('db.schema');
            DB::purge('number_sequence_owner');
            config()->set('database.connections.number_sequence_owner', null);
        }
    }
}
