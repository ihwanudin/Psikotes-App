<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Database\Seeders\InstrumentSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InstrumentVersionHistorySecurityTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->asOwner(function (): void {
            DB::statement('TRUNCATE TABLE instrument_versions RESTART IDENTITY');
        });

        parent::tearDown();
    }

    public function test_runtime_has_forced_service_only_rls_and_least_privilege(): void
    {
        $identity = DB::selectOne(<<<'SQL'
            SELECT current_user AS name, rolsuper, rolbypassrls,
                pg_get_userbyid(class.relowner) AS table_owner,
                class.relrowsecurity, class.relforcerowsecurity
            FROM pg_roles role
            CROSS JOIN pg_class class
            WHERE role.rolname = current_user AND class.oid = 'instrument_versions'::regclass
            SQL);

        $this->assertSame('psikotes_runtime', $identity->name);
        $this->assertFalse($identity->rolsuper);
        $this->assertFalse($identity->rolbypassrls);
        $this->assertNotSame('psikotes_runtime', $identity->table_owner);
        $this->assertTrue($identity->relrowsecurity);
        $this->assertTrue($identity->relforcerowsecurity);

        foreach (['SELECT', 'INSERT', 'UPDATE'] as $privilege) {
            $this->assertTrue(DB::selectOne(
                "SELECT has_table_privilege('psikotes_runtime', 'instrument_versions', ?) AS allowed",
                [$privilege],
            )->allowed, $privilege);
        }
        foreach (['DELETE', 'TRUNCATE', 'TRIGGER', 'REFERENCES'] as $privilege) {
            $this->assertFalse(DB::selectOne(
                "SELECT has_table_privilege('psikotes_runtime', 'instrument_versions', ?) AS allowed",
                [$privilege],
            )->allowed, $privilege);
        }

        $policies = DB::table('pg_policies')->where('schemaname', 'public')
            ->where('tablename', 'instrument_versions')->orderBy('policyname')->get();
        $this->assertSame([
            'instrument_versions_service_insert',
            'instrument_versions_service_read',
            'instrument_versions_service_update',
        ], $policies->pluck('policyname')->all());
        foreach ($policies as $policy) {
            $this->assertSame('{psikotes_runtime}', $policy->roles);
            $this->assertStringContainsString("app_private.app_role() = 'service'", ($policy->qual ?? '').($policy->with_check ?? ''));
        }
    }

    public function test_only_service_can_seed_read_insert_and_deactivate_history(): void
    {
        $this->assertSame(0, DB::table('instrument_versions')->count());
        $this->seedDeniedOutsideService();

        (new InstrumentSeeder)->run();

        $this->assertSame(0, DB::table('instrument_versions')->count());
        $this->assertSame(6, app(RlsContextRunner::class)->runAsService(
            fn (): int => DB::table('instrument_versions')->count(),
        ));

        foreach (['super_admin', 'psychologist', 'branch_admin', 'staff', 'participant'] as $role) {
            $context = match ($role) {
                'branch_admin', 'staff' => new RlsContext($role, 1),
                'participant' => new RlsContext($role, 1, 1),
                default => new RlsContext($role),
            };
            app(RlsContextRunner::class)->run($context, function () use ($role): void {
                $this->assertSame(0, DB::table('instrument_versions')->count(), $role);
                $this->assertSqlState('42501', fn () => DB::table('instrument_versions')->insert($this->row($role, 'v1')));
            });
        }

        app(RlsContextRunner::class)->runAsService(function (): void {
            DB::table('instrument_versions')->where('code', 'ist')->update([
                'is_active' => false,
                'updated_at' => now()->addSecond(),
            ]);
            DB::table('instrument_versions')->insert($this->row('ist', 'future-v2'));
            $this->assertSame(1, DB::table('instrument_versions')->where('code', 'ist')->where('is_active', true)->count());
        });
    }

    public function test_history_is_immutable_and_active_version_is_unique(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            DB::table('instrument_versions')->insert($this->row('synthetic', 'v1'));
        });

        foreach (['code', 'version', 'source_file', 'checksum', 'payload', 'created_at'] as $column) {
            $this->assertSqlState('P0001', function () use ($column): void {
                app(RlsContextRunner::class)->runAsService(function () use ($column): void {
                    $value = match ($column) {
                        'payload' => '{"changed":true}',
                        'created_at' => now()->subDay(),
                        'checksum' => str_repeat('f', 64),
                        default => 'changed',
                    };
                    DB::table('instrument_versions')->where('code', 'synthetic')->update([$column => $value]);
                });
            });
        }

        $this->assertSqlState('23505', fn () => app(RlsContextRunner::class)->runAsService(
            fn () => DB::table('instrument_versions')->insert($this->row('synthetic', 'v2')),
        ));
        $this->assertSqlState('42501', fn () => app(RlsContextRunner::class)->runAsService(
            fn () => DB::table('instrument_versions')->where('code', 'synthetic')->delete(),
        ));

        app(RlsContextRunner::class)->runAsService(fn () => DB::table('instrument_versions')
            ->where('code', 'synthetic')->update(['is_active' => false, 'updated_at' => now()->addSecond()]));
        $this->assertSqlState('P0001', fn () => app(RlsContextRunner::class)->runAsService(
            fn () => DB::table('instrument_versions')->where('code', 'synthetic')
                ->update(['is_active' => true, 'updated_at' => now()->addSeconds(2)]),
        ));
    }

    public function test_migration_refuses_dirty_upgrade_and_populated_downgrade_but_empty_rollback_is_reversible(): void
    {
        $this->asOwner(function (): void {
            $migration = require database_path('migrations/2026_09_09_000100_harden_instrument_versions_history.php');
            DB::beginTransaction();
            try {
                $migration->down();
                $this->assertFalse(DB::selectOne("SELECT relrowsecurity FROM pg_class WHERE oid = 'instrument_versions'::regclass")->relrowsecurity);
                $migration->up();
                $this->assertTrue(DB::selectOne("SELECT relforcerowsecurity FROM pg_class WHERE oid = 'instrument_versions'::regclass")->relforcerowsecurity);
            } finally {
                DB::rollBack();
            }

            DB::beginTransaction();
            try {
                $migration->down();
                DB::table('instrument_versions')->insert([$this->row('dirty', 'v1'), $this->row('dirty', 'v2')]);
                try {
                    $migration->up();
                    $this->fail('Dirty duplicate active versions must refuse upgrade.');
                } catch (RuntimeException $exception) {
                    $this->assertStringContainsString('duplicate active', $exception->getMessage());
                }
                $this->assertSame(2, DB::table('instrument_versions')->where('code', 'dirty')->count());
            } finally {
                DB::rollBack();
            }

            DB::beginTransaction();
            try {
                DB::table('instrument_versions')->insert($this->row('history', 'v1'));
                try {
                    $migration->down();
                    $this->fail('Populated history downgrade must be refused.');
                } catch (RuntimeException $exception) {
                    $this->assertStringContainsString('populated', $exception->getMessage());
                }
                $this->assertTrue(DB::selectOne("SELECT relforcerowsecurity FROM pg_class WHERE oid = 'instrument_versions'::regclass")->relforcerowsecurity);
                $this->assertNotNull(DB::selectOne("SELECT to_regclass('instrument_versions_one_active_code_unique') AS name")->name);
            } finally {
                DB::rollBack();
            }
        });
    }

    private function seedDeniedOutsideService(): void
    {
        $this->assertSqlState('42501', fn () => DB::table('instrument_versions')->insert($this->row('outside', 'v1')));
    }

    /** @return array<string, mixed> */
    private function row(string $code, string $version): array
    {
        return [
            'code' => $code,
            'version' => $version,
            'source_file' => $code.'.json',
            'checksum' => hash('sha256', $code.':'.$version),
            'payload' => json_encode(['code' => $code, 'version' => $version], JSON_THROW_ON_ERROR),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];
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
        config()->set('database.connections.instrument_history_owner', [...$config, 'username' => 'org_test_owner']);
        DB::setDefaultConnection('instrument_history_owner');
        Schema::clearResolvedInstance('db.schema');

        try {
            $owner = DB::connection();
            $this->assertSame('org_test_owner', $owner->selectOne('SELECT current_user AS name')->name);
            $callback($owner);
        } finally {
            DB::setDefaultConnection($runtime);
            Schema::clearResolvedInstance('db.schema');
            DB::purge('instrument_history_owner');
            config()->set('database.connections.instrument_history_owner', null);
        }
    }
}
