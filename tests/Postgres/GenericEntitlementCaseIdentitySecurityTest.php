<?php

declare(strict_types=1);

namespace Tests\Postgres;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;

final class GenericEntitlementCaseIdentitySecurityTest extends TestCase
{
    public function test_entitlement_rls_acl_and_policies_remain_exact(): void
    {
        $identity = DB::selectOne(<<<'SQL'
            SELECT current_user name,role.rolsuper,role.rolbypassrls,
              pg_get_userbyid(class.relowner) table_owner,class.relrowsecurity,class.relforcerowsecurity
            FROM pg_roles role CROSS JOIN pg_class class
            WHERE role.rolname=current_user AND class.oid='entitlements'::regclass
            SQL);
        $this->assertSame('psikotes_runtime', $identity->name);
        $this->assertFalse($identity->rolsuper);
        $this->assertFalse($identity->rolbypassrls);
        $this->assertNotSame('psikotes_runtime', $identity->table_owner);
        $this->assertTrue($identity->relrowsecurity);
        $this->assertTrue($identity->relforcerowsecurity);

        foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $privilege) {
            $this->assertTrue(DB::selectOne(
                "SELECT has_table_privilege('psikotes_runtime','entitlements',?) allowed",
                [$privilege],
            )->allowed, $privilege);
        }
        foreach (['TRUNCATE', 'TRIGGER', 'REFERENCES'] as $privilege) {
            $this->assertFalse(DB::selectOne(
                "SELECT has_table_privilege('psikotes_runtime','entitlements',?) allowed",
                [$privilege],
            )->allowed, $privilege);
        }

        $policies = DB::table('pg_policies')->where('schemaname', 'public')
            ->where('tablename', 'entitlements')->orderBy('policyname')->get();
        $this->assertSame(['entitlements_read', 'entitlements_write'], $policies->pluck('policyname')->all());
        $this->assertSame('SELECT', $policies[0]->cmd);
        $this->assertSame('ALL', $policies[1]->cmd);
        $this->assertSame('{psikotes_runtime}', $policies[0]->roles);
        $this->assertSame('{psikotes_runtime}', $policies[1]->roles);
        $this->assertStringContainsString("app_private.app_role() = 'participant'", $policies[0]->qual);
        $this->assertStringContainsString('app_private.app_role() = ANY', $policies[1]->qual);
        $this->assertStringContainsString('app_private.app_role() = ANY', $policies[1]->with_check);
    }

    public function test_no_context_cannot_observe_or_mutate_entitlements(): void
    {
        DB::beginTransaction();
        try {
            DB::select("SELECT set_config('app.role','',true),set_config('app.branch_id','',true),set_config('app.participant_id','',true)");
            $this->assertSame(0, DB::table('entitlements')->count());
            $this->assertSame(0, DB::table('entitlements')->update(['status' => 'locked']));
            $this->assertSame(0, DB::table('entitlements')->delete());
        } finally {
            DB::rollBack();
        }
    }
}
