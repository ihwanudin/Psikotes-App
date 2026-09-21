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
use Tests\Support\GenericResultLedgerMigrationFixture;

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
        $seeded = app(RlsContextRunner::class)->runAsService(
            fn (): array => DB::table('instrument_versions')->orderBy('code')
                ->get(['code', 'version', 'source_file', 'checksum'])
                ->map(fn (object $row): array => (array) $row)->all(),
        );
        $expected = [];
        foreach ([
            'aspect_sources' => ['ASPECT-SOURCES-2026.09', 'aspect_sources.json'],
            'dass21' => ['F0-2026.08', 'dass21.json'],
            'ist' => ['F2-2026.09', 'ist.json'],
            'ist_items' => ['F0-ITEMS-IST-2026.09', 'ist_items.json'],
            'kraepelin' => ['F2-2026.09', 'kraepelin.json'],
            'kraepelin_grid' => ['F0-2026.09', 'kraepelin_grid.json'],
            'papi' => ['F2-2026.09', 'papi.json'],
            'reporting' => ['GA-2026.08', 'reporting.json'],
            'rmib' => ['F2-2026.09', 'rmib.json'],
        ] as $code => [$version, $sourceFile]) {
            $expected[] = [
                'code' => $code,
                'version' => $version,
                'source_file' => $sourceFile,
                'checksum' => hash_file('sha256', database_path("seeders/data/{$sourceFile}")),
            ];
        }
        $this->assertSame($expected, $seeded);

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

        foreach (['code', 'version', 'source_file', 'checksum', 'payload', 'source_text', 'created_at'] as $column) {
            $this->assertSqlState('P0001', function () use ($column): void {
                app(RlsContextRunner::class)->runAsService(function () use ($column): void {
                    $value = match ($column) {
                        'payload' => '{"changed":true}',
                        'source_text' => 'changed-source-text',
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

    public function test_deactivation_rejects_null_old_or_new_updated_at(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            DB::table('instrument_versions')->insert($this->row('null-new', 'v1'));
        });

        $this->assertSqlState('P0001', fn () => app(RlsContextRunner::class)->runAsService(
            fn () => DB::table('instrument_versions')->where('code', 'null-new')->update([
                'is_active' => false,
                'updated_at' => null,
            ]),
        ));

        $this->asOwner(function (): void {
            DB::statement('ALTER TABLE instrument_versions DISABLE TRIGGER instrument_versions_guard_history_trigger');
            DB::table('instrument_versions')->insert($this->row('null-old', 'v1', updatedAt: null));
            DB::statement('ALTER TABLE instrument_versions ENABLE TRIGGER instrument_versions_guard_history_trigger');
        });

        $this->assertSqlState('P0001', fn () => app(RlsContextRunner::class)->runAsService(
            fn () => DB::table('instrument_versions')->where('code', 'null-old')->update([
                'is_active' => false,
                'updated_at' => now()->addSecond(),
            ]),
        ));

        $this->assertSqlState('P0001', fn () => app(RlsContextRunner::class)->runAsService(
            fn () => DB::table('instrument_versions')->insert($this->row('null-insert', 'v1', updatedAt: null)),
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

    public function test_migration_refuses_active_null_timestamp_on_upgrade(): void
    {
        $this->asOwner(function (): void {
            $migration = require database_path('migrations/2026_09_09_000100_harden_instrument_versions_history.php');

            DB::beginTransaction();
            try {
                $migration->down();
                DB::table('instrument_versions')->insert($this->row('null-upgrade', 'v1', updatedAt: null));

                $exception = null;
                try {
                    $migration->up();
                } catch (RuntimeException $runtimeException) {
                    $exception = $runtimeException;
                }

                $this->assertNotNull($exception, 'Active history with a null updated_at must refuse upgrade.');
                $this->assertStringContainsString('null updated_at', $exception->getMessage());
                $this->assertNull(DB::table('instrument_versions')->where('code', 'null-upgrade')->value('updated_at'));
            } finally {
                DB::rollBack();
            }
        });
    }

    public function test_non_bypass_table_owner_can_reverse_empty_migration_but_not_populated_history(): void
    {
        $this->asOwner(function (): void {
            $this->asNonBypassOwner(function (): void {
                $migration = require database_path('migrations/2026_09_09_000100_harden_instrument_versions_history.php');
                $identity = DB::selectOne('SELECT current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');

                $this->assertSame('instrument_history_owner', $identity->name);
                $this->assertFalse($identity->rolsuper);
                $this->assertFalse($identity->rolbypassrls);

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
                    DB::statement('ALTER TABLE instrument_versions NO FORCE ROW LEVEL SECURITY');
                    DB::table('instrument_versions')->insert($this->row('non-bypass-owner', 'v1'));
                    DB::statement('ALTER TABLE instrument_versions FORCE ROW LEVEL SECURITY');

                    try {
                        $migration->down();
                        $this->fail('A non-bypass table owner must refuse populated downgrade.');
                    } catch (RuntimeException $exception) {
                        $this->assertStringContainsString('populated', $exception->getMessage());
                    }

                    $this->assertTrue(DB::selectOne("SELECT relforcerowsecurity FROM pg_class WHERE oid = 'instrument_versions'::regclass")->relforcerowsecurity);
                    $this->assertNotNull(DB::selectOne("SELECT to_regclass('instrument_versions_one_active_code_unique') AS name")->name);
                } finally {
                    DB::rollBack();
                }
            });
        });
    }

    private function seedDeniedOutsideService(): void
    {
        $this->assertSqlState('42501', fn () => DB::table('instrument_versions')->insert($this->row('outside', 'v1')));
    }

    /** @return array<string, mixed> */
    private function row(string $code, string $version, mixed $updatedAt = false): array
    {
        $updatedAt = $updatedAt === false ? now() : $updatedAt;

        return [
            'code' => $code,
            'version' => $version,
            'source_file' => $code.'.json',
            'checksum' => hash('sha256', $code.':'.$version),
            'payload' => json_encode(['code' => $code, 'version' => $version], JSON_THROW_ON_ERROR),
            'source_text' => 'synthetic-source-text:'.$code.':'.$version,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => $updatedAt,
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
        if ($runtime === 'instrument_history_owner') {
            GenericResultLedgerMigrationFixture::withoutLedger(fn (): mixed => $callback(DB::connection()));

            return;
        }
        $config = config('database.connections.'.$runtime);
        config()->set('database.connections.instrument_history_owner', [...$config, 'username' => 'org_test_owner']);
        DB::setDefaultConnection('instrument_history_owner');
        Schema::clearResolvedInstance('db.schema');

        try {
            $owner = DB::connection();
            $this->assertSame('org_test_owner', $owner->selectOne('SELECT current_user AS name')->name);
            GenericResultLedgerMigrationFixture::withoutLedger(fn (): mixed => $callback($owner));
        } finally {
            DB::setDefaultConnection($runtime);
            Schema::clearResolvedInstance('db.schema');
            DB::purge('instrument_history_owner');
            config()->set('database.connections.instrument_history_owner', null);
        }
    }

    private function asNonBypassOwner(callable $callback): void
    {
        $runtime = DB::getDefaultConnection();
        $config = config('database.connections.'.$runtime);

        $this->asOwner(function (): void {
            DB::statement('CREATE ROLE instrument_history_owner LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS');
            DB::statement('GRANT USAGE, CREATE ON SCHEMA public TO instrument_history_owner');
            DB::statement('GRANT USAGE ON SCHEMA app_private TO instrument_history_owner');
            DB::statement('ALTER TABLE instrument_versions OWNER TO instrument_history_owner');
            DB::statement('ALTER SEQUENCE instrument_versions_id_seq OWNER TO instrument_history_owner');
            DB::statement('ALTER FUNCTION instrument_versions_guard_history() OWNER TO instrument_history_owner');
        });

        config()->set('database.connections.instrument_history_nonsuper_owner', [
            ...$config,
            'username' => 'instrument_history_owner',
        ]);
        DB::setDefaultConnection('instrument_history_nonsuper_owner');
        Schema::clearResolvedInstance('db.schema');

        try {
            $callback();
        } finally {
            DB::setDefaultConnection($runtime);
            Schema::clearResolvedInstance('db.schema');
            DB::purge('instrument_history_nonsuper_owner');
            config()->set('database.connections.instrument_history_nonsuper_owner', null);

            $this->asOwner(function (): void {
                DB::statement('ALTER TABLE instrument_versions OWNER TO org_test_owner');
                DB::statement('ALTER SEQUENCE instrument_versions_id_seq OWNER TO org_test_owner');
                DB::statement('ALTER FUNCTION instrument_versions_guard_history() OWNER TO org_test_owner');
                DB::statement('REVOKE USAGE, CREATE ON SCHEMA public FROM instrument_history_owner');
                DB::statement('REVOKE USAGE ON SCHEMA app_private FROM instrument_history_owner');
                DB::statement('DROP ROLE instrument_history_owner');
            });
        }
    }
}
