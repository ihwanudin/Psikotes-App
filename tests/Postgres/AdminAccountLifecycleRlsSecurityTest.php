<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Actions\Admins\BootstrapSuperAdmin;
use App\Enums\AdminRole;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;

/**
 * Admin account lifecycle (2026-09-22, Lead sign-off). Lead's explicit
 * condition #4: real RLS proof for the bootstrap insert, not just an
 * assertion that it should work.
 */
final class AdminAccountLifecycleRlsSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_admin_password_setup_tokens_has_forced_least_privilege_rls(): void
    {
        $identity = DB::selectOne(
            "SELECT relrowsecurity, relforcerowsecurity, pg_get_userbyid(relowner) AS table_owner FROM pg_class WHERE oid = 'admin_password_setup_tokens'::regclass",
        );
        $this->assertTrue($identity->relrowsecurity);
        $this->assertTrue($identity->relforcerowsecurity);
        $this->assertNotSame('psikotes_runtime', $identity->table_owner);

        foreach (['SELECT', 'INSERT', 'UPDATE'] as $privilege) {
            $this->assertTrue((bool) DB::scalar(
                "SELECT has_table_privilege('psikotes_runtime', 'admin_password_setup_tokens', ?)",
                [$privilege],
            ), $privilege);
        }
        foreach (['DELETE', 'TRUNCATE', 'TRIGGER', 'REFERENCES'] as $privilege) {
            $this->assertFalse((bool) DB::scalar(
                "SELECT has_table_privilege('psikotes_runtime', 'admin_password_setup_tokens', ?)",
                [$privilege],
            ), $privilege);
        }

        $policies = DB::table('pg_policies')
            ->where('schemaname', 'public')->where('tablename', 'admin_password_setup_tokens')
            ->orderBy('policyname')->get();
        $this->assertSame([
            'admin_password_setup_tokens_service_insert',
            'admin_password_setup_tokens_service_select',
            'admin_password_setup_tokens_service_update',
        ], $policies->pluck('policyname')->all());
        foreach ($policies as $policy) {
            $this->assertSame('{psikotes_runtime}', $policy->roles);
            $this->assertStringContainsString(
                "app_private.app_role() = 'service'",
                ($policy->qual ?? '').($policy->with_check ?? ''),
            );
        }
    }

    /**
     * admins_write's RLS policy (database/schema/rls_policies.sql, pre-
     * existing, not added by this PR) only ever permits
     * app_role() IN ('service', 'super_admin'). This is what forces
     * BootstrapSuperAdmin to run under RlsContextRunner::runAsService() --
     * proven here directly, not just asserted in a doc comment.
     */
    public function test_a_direct_insert_without_a_service_or_super_admin_context_is_denied(): void
    {
        $this->assertSqlState('42501', fn () => app(RlsContextRunner::class)->run(
            new RlsContext('participant', 1, 1),
            fn () => DB::table('admins')->insert([
                'branch_id' => null, 'name' => 'Injected', 'email' => 'injected@example.test',
                'password' => 'irrelevant', 'role' => AdminRole::SuperAdmin->value,
                'created_at' => now(), 'updated_at' => now(),
            ]),
        ));

        $this->assertSame(0, app(RlsContextRunner::class)->runAsService(
            fn () => DB::table('admins')->count(),
        ));
    }

    public function test_bootstrap_super_admin_succeeds_under_the_service_context_it_establishes_itself(): void
    {
        $this->assertNull(app(RlsContextRunner::class)->current());

        $admin = app(BootstrapSuperAdmin::class)->handle(
            'Kepala Admin',
            'kepala@example.test',
            password_hash('irrelevant-test-only', PASSWORD_BCRYPT),
            'test-operator',
        );

        $this->assertSame(AdminRole::SuperAdmin, $admin->role);
        // The action's own runAsService() wrapping closes cleanly -- no
        // context leaks past handle() returning.
        $this->assertNull(app(RlsContextRunner::class)->current());

        $found = app(RlsContextRunner::class)->runAsService(
            fn () => DB::table('admins')->where('id', $admin->id)->exists(),
        );
        $this->assertTrue($found);
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
}
