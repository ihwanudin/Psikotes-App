<?php

declare(strict_types=1);

namespace Tests\Postgres;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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
        $normalizedPolicies = $policies->map(function (object $policy): array {
            $row = (array) $policy;
            foreach (['qual', 'with_check'] as $key) {
                $row[$key] = is_string($row[$key]) ? trim((string) preg_replace('/\s+/', ' ', $row[$key])) : null;
            }

            return $row;
        })->all();
        $this->assertSame('e4d46ccf4216d19c07ab8a91c29640d9936a2817ac1297d961f5e98a6ffdeffa', hash('sha256', json_encode($normalizedPolicies, JSON_THROW_ON_ERROR)));
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

    public function test_owner_idempotent_rerun_preserves_every_temporarily_unforced_table_security_state(): void
    {
        $this->asOwner(function (): void {
            $before = $this->securitySnapshot();
            $this->requirementMigration('down');
            try {
                $this->migrateUp();
            } finally {
                $this->requirementMigration('up');
            }
            $this->assertEquals($before, $this->securitySnapshot());
        });
    }

    #[DataProvider('counterfeitComponents')]
    public function test_owner_rerun_rejects_counterfeit_contract_and_restores_security_state(string $component): void
    {
        $this->asOwner(function () use ($component): void {
            DB::beginTransaction();
            try {
                $this->requirementMigration('down');
                match ($component) {
                    'index' => DB::unprepared('DROP INDEX entitlements_case_test_type_unique; CREATE UNIQUE INDEX entitlements_case_test_type_unique ON entitlements (assessment_case_id, test_type) WHERE false'),
                    'foreign' => DB::unprepared('ALTER TABLE entitlements DROP CONSTRAINT entitlements_case_scope_fk; ALTER TABLE entitlements ADD CONSTRAINT entitlements_case_scope_fk FOREIGN KEY (assessment_case_id,participant_id) REFERENCES assessment_cases(id,participant_id) ON DELETE CASCADE DEFERRABLE'),
                    'trigger' => DB::unprepared('DROP TRIGGER entitlements_case_identity_guard ON entitlements; CREATE TRIGGER entitlements_case_identity_guard BEFORE INSERT ON entitlements FOR EACH ROW EXECUTE FUNCTION app_private.guard_generic_entitlement_case_identity()'),
                    'function_body' => DB::unprepared('CREATE OR REPLACE FUNCTION app_private.guard_generic_entitlement_case_identity() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$ BEGIN RETURN NEW; END; $$'),
                    'function_security' => DB::unprepared('ALTER FUNCTION app_private.guard_generic_entitlement_case_identity() SECURITY INVOKER'),
                    'function_search_path' => DB::unprepared('ALTER FUNCTION app_private.guard_generic_entitlement_case_identity() SET search_path=public'),
                    'function_execute' => DB::unprepared('GRANT EXECUTE ON FUNCTION app_private.guard_generic_entitlement_case_identity() TO psikotes_runtime'),
                    'table_acl' => DB::unprepared('GRANT SELECT ON entitlements TO psikotes_runtime WITH GRANT OPTION'),
                    'rls' => DB::unprepared('ALTER TABLE entitlements NO FORCE ROW LEVEL SECURITY'),
                    'policy' => DB::unprepared('DROP POLICY entitlements_write ON entitlements; CREATE POLICY entitlements_write ON entitlements FOR ALL TO psikotes_runtime USING (false) WITH CHECK (false)'),
                    default => throw new RuntimeException("Unknown counterfeit component {$component}."),
                };
                $before = $this->securitySnapshot();
                try {
                    $this->migrateUp();
                    $this->fail("Counterfeit {$component} was accepted.");
                } catch (RuntimeException $exception) {
                    $this->assertStringContainsString('partial PostgreSQL enforcement', $exception->getMessage());
                }
                $this->assertEquals($before, $this->securitySnapshot());
            } finally {
                DB::rollBack();
            }
        });
    }

    /** @return iterable<string,array{string}> */
    public static function counterfeitComponents(): iterable
    {
        yield 'partial index predicate' => ['index'];
        yield 'foreign action and deferrability' => ['foreign'];
        yield 'trigger event' => ['trigger'];
        yield 'function body' => ['function_body'];
        yield 'function security invoker' => ['function_security'];
        yield 'function search path' => ['function_search_path'];
        yield 'function execute ACL' => ['function_execute'];
        yield 'table grant option' => ['table_acl'];
        yield 'RLS not forced' => ['rls'];
        yield 'policy predicate' => ['policy'];
    }

    /** @return array<string,mixed> */
    private function securitySnapshot(): array
    {
        return [
            'tables' => DB::select(<<<'SQL'
                SELECT relname,relrowsecurity,relforcerowsecurity,pg_get_userbyid(relowner) owner
                FROM pg_class WHERE oid IN ('participants'::regclass,'packages'::regclass,
                    'package_items'::regclass,'assessment_cases'::regclass,'orders'::regclass,
                    'selection_participants'::regclass,'entitlements'::regclass) ORDER BY relname
                SQL),
            'policies' => DB::select(<<<'SQL'
                SELECT tablename,policyname,permissive,roles,cmd,qual,with_check
                FROM pg_policies WHERE schemaname='public' AND tablename IN
                    ('participants','packages','package_items','assessment_cases','orders',
                     'selection_participants','entitlements') ORDER BY tablename,policyname
                SQL),
            'acl' => DB::select(<<<'SQL'
                SELECT class.relname,CASE WHEN acl.grantee=0 THEN 'PUBLIC' ELSE pg_get_userbyid(acl.grantee) END grantee,
                       acl.privilege_type,acl.is_grantable
                FROM pg_class class
                CROSS JOIN LATERAL aclexplode(COALESCE(class.relacl,acldefault('r',class.relowner))) acl
                WHERE class.oid IN ('participants'::regclass,'packages'::regclass,'package_items'::regclass,
                    'assessment_cases'::regclass,'orders'::regclass,'selection_participants'::regclass,
                    'entitlements'::regclass) ORDER BY class.relname,grantee,acl.privilege_type
                SQL),
        ];
    }

    private function migrateUp(): void
    {
        $migration = $this->migration();
        (new \ReflectionMethod($migration, 'up'))->invoke($migration);
    }

    private function requirementMigration(string $operation): void
    {
        $migration = require database_path('migrations/2026_09_10_000400_enforce_generic_entitlement_case_identity.php');
        if (! $migration instanceof Migration || ! is_callable([$migration, $operation])) {
            throw new RuntimeException("Requirement migration operation {$operation} is unavailable.");
        }
        (new \ReflectionMethod($migration, $operation))->invoke($migration);
    }

    private function migration(): Migration
    {
        $migration = require database_path('migrations/2026_09_10_000300_expand_generic_entitlement_case_identity.php');
        if (! $migration instanceof Migration) {
            throw new RuntimeException('Generic entitlement case migration did not return a migration.');
        }

        return $migration;
    }

    private function asOwner(callable $callback): void
    {
        $runtime = DB::getDefaultConnection();
        $config = config('database.connections.'.$runtime);
        config()->set('database.connections.generic_case_owner', [...$config, 'username' => 'org_test_owner']);
        DB::setDefaultConnection('generic_case_owner');
        Schema::clearResolvedInstance('db.schema');
        try {
            $this->assertSame('org_test_owner', DB::selectOne('SELECT current_user AS name')->name);
            $callback();
        } finally {
            DB::setDefaultConnection($runtime);
            Schema::clearResolvedInstance('db.schema');
            DB::purge('generic_case_owner');
            config()->set('database.connections.generic_case_owner', null);
        }
    }
}
