<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\AssessmentAccessFixture;

final class TestSessionGrantSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('test_session_grants')) {
            $this->asOwner(
                fn () => (require database_path('migrations/2026_09_09_000700_create_test_session_grants.php'))->up(),
            );
        }
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_runtime_is_nonbypass_and_grant_ledger_is_service_append_only(): void
    {
        $identity = DB::selectOne(<<<'SQL'
            SELECT role.rolsuper, role.rolbypassrls, class.relrowsecurity, class.relforcerowsecurity,
                pg_get_userbyid(class.relowner) AS owner
            FROM pg_roles role CROSS JOIN pg_class class
            WHERE role.rolname=current_user AND class.oid='test_session_grants'::regclass
            SQL);
        $this->assertFalse($identity->rolsuper);
        $this->assertFalse($identity->rolbypassrls);
        $this->assertNotSame('psikotes_runtime', $identity->owner);
        $this->assertTrue($identity->relrowsecurity);
        $this->assertTrue($identity->relforcerowsecurity);
        foreach (['SELECT', 'INSERT'] as $privilege) {
            $this->assertTrue((bool) DB::scalar("SELECT has_table_privilege('psikotes_runtime','test_session_grants',?)", [$privilege]));
        }
        foreach (['UPDATE', 'DELETE', 'TRUNCATE', 'TRIGGER', 'REFERENCES'] as $privilege) {
            $this->assertFalse((bool) DB::scalar("SELECT has_table_privilege('psikotes_runtime','test_session_grants',?)", [$privilege]));
        }

        $fixture = app(RlsContextRunner::class)->runAsService(fn (): array => $this->directFixture());
        DB::select("SELECT set_config('app.role','',true)");
        $this->assertSame(0, DB::table('test_session_grants')->count());
        foreach (['super_admin', 'psychologist', 'branch_admin', 'staff', 'participant'] as $role) {
            $context = in_array($role, ['branch_admin', 'staff'], true)
                ? new RlsContext($role, $fixture['branch'])
                : ($role === 'participant'
                    ? new RlsContext($role, $fixture['branch'], $fixture['participant'])
                    : new RlsContext($role));
            app(RlsContextRunner::class)->run($context, function () use ($fixture): void {
                $this->assertSame(0, DB::table('test_session_grants')->count());
                $this->assertSqlState('42501', fn () => DB::table('test_session_grants')->insert($this->grantRow($fixture)));
            });
        }

        app(RlsContextRunner::class)->runAsService(function () use ($fixture): void {
            DB::table('test_session_grants')->insert($this->grantRow($fixture));
            $this->assertSame(1, DB::table('test_session_grants')->where('test_session_id', $fixture['session'])->count());
            $this->assertSqlState('42501', fn () => DB::table('test_session_grants')
                ->where('test_session_id', $fixture['session'])->update(['created_at' => now()->addSecond()]));
            $this->assertSqlState('42501', fn () => DB::table('test_session_grants')
                ->where('test_session_id', $fixture['session'])->delete());
        });
    }

    public function test_database_rejects_dass_cross_scope_and_reused_source_grants(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            $first = $this->directFixture();
            $second = $this->directFixture();
            DB::table('test_session_grants')->insert($this->grantRow($first));

            $this->assertSqlState('23514', fn () => DB::table('test_session_grants')->insert([
                ...$this->grantRow($second), 'test_type' => 'dass21',
            ]));
            $this->assertSqlState('23514', fn () => DB::table('test_session_grants')->insert([
                ...$this->grantRow($second), 'assessment_case_id' => $first['case'],
            ]));

            DB::table('test_sessions')->where('id', $first['session'])->update([
                'status' => 'in_progress', 'started_at' => now(), 'ends_at' => now()->addHour(),
            ]);
            $this->assertSame('in_progress', DB::table('test_sessions')->where('id', $first['session'])->value('status'));
            DB::table('test_sessions')->where('id', $first['session'])->update([
                'status' => 'void', 'voided_at' => now(), 'void_reason' => 'Synthetic reused source probe.',
            ]);
            $reusedSource = $first;
            $reusedSource['session'] = $this->boundSession($first['participant'], $first['case'], 2);
            $this->assertSqlState('23505', fn () => DB::table('test_session_grants')
                ->insert($this->grantRow($reusedSource)));
        });
    }

    public function test_integrated_grant_is_bound_to_durable_checkout_price_and_settlement_facts(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            $valid = $this->integratedFixture();
            $validSession = $this->boundSession($valid['participant'], (int) $valid['case']);
            DB::table('test_session_grants')->insert($this->integratedGrantRow($valid, $validSession));
            $this->assertSame(1, DB::table('test_session_grants')->where('test_session_id', $validSession)->count());

            $unsettled = $this->integratedFixture();
            $unsettledSession = $this->boundSession($unsettled['participant'], (int) $unsettled['case']);
            DB::table('assessment_bills')->where('id', $unsettled['bill'])->update(['status' => 'pending', 'paid_at' => null]);
            $this->assertSqlState('23514', fn () => DB::table('test_session_grants')
                ->insert($this->integratedGrantRow($unsettled, $unsettledSession)));
        });
    }

    public function test_owner_canonical_rerun_preserves_definitions_security_and_data(): void
    {
        $this->asOwner(function (): void {
            DB::beginTransaction();
            try {
                DB::statement("SELECT set_config('app.role', 'service', true)");
                $fixture = $this->directFixture();
                DB::table('test_session_grants')->insert($this->grantRow($fixture));
                $before = $this->grantDefinitions();

                (require database_path('migrations/2026_09_09_000700_create_test_session_grants.php'))->up();

                $this->assertEquals($before, $this->grantDefinitions());
            } finally {
                DB::rollBack();
            }
        });
    }

    public function test_owner_populated_down_refuses_without_partial_schema_security_or_data_mutation(): void
    {
        $this->asOwner(function (): void {
            DB::beginTransaction();
            try {
                DB::statement("SELECT set_config('app.role', 'service', true)");
                $fixture = $this->directFixture();
                DB::table('test_session_grants')->insert($this->grantRow($fixture));
                $before = $this->grantDefinitions();

                try {
                    (require database_path('migrations/2026_09_09_000700_create_test_session_grants.php'))->down();
                    $this->fail('Populated test session grant history must refuse rollback.');
                } catch (RuntimeException $exception) {
                    $this->assertSame('Test session grant history prevents rollback.', $exception->getMessage());
                }

                $this->assertEquals($before, $this->grantDefinitions());
            } finally {
                DB::rollBack();
            }
        });
    }

    #[DataProvider('counterfeitDefinitions')]
    public function test_owner_rerun_rejects_counterfeit_full_definitions_without_delta(string $component): void
    {
        $this->asOwner(function () use ($component): void {
            DB::beginTransaction();
            try {
                match ($component) {
                    'instrument_check' => DB::unprepared('ALTER TABLE test_session_grants DROP CONSTRAINT test_session_grants_instrument_check; ALTER TABLE test_session_grants ADD CONSTRAINT test_session_grants_instrument_check CHECK (true)'),
                    'shape_check' => DB::unprepared('ALTER TABLE test_session_grants DROP CONSTRAINT test_session_grants_shape_check; ALTER TABLE test_session_grants ADD CONSTRAINT test_session_grants_shape_check CHECK (true)'),
                    'trigger_event' => DB::unprepared('DROP TRIGGER test_session_grants_identity_guard ON test_session_grants; CREATE TRIGGER test_session_grants_identity_guard BEFORE INSERT OR UPDATE ON test_session_grants FOR EACH ROW EXECUTE FUNCTION app_private.guard_test_session_grant_identity()'),
                    'extra_trigger' => DB::unprepared('CREATE TRIGGER test_session_grants_counterfeit_guard BEFORE INSERT ON test_session_grants FOR EACH ROW EXECUTE FUNCTION app_private.guard_test_session_grant_identity()'),
                    'function_body' => DB::unprepared('CREATE OR REPLACE FUNCTION app_private.guard_test_session_grant_identity() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$ BEGIN RETURN NEW; END; $$'),
                    'function_security' => DB::unprepared('CREATE OR REPLACE FUNCTION app_private.guard_test_session_grant_identity() RETURNS trigger LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public AS $$ BEGIN RETURN NEW; END; $$'),
                    'function_search_path' => DB::unprepared('CREATE OR REPLACE FUNCTION app_private.guard_test_session_grant_identity() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$ BEGIN RETURN NEW; END; $$'),
                    'unexpected_grantee' => DB::unprepared('CREATE ROLE test_session_grants_counterfeit_role NOLOGIN; GRANT SELECT ON test_session_grants TO test_session_grants_counterfeit_role'),
                    'grant_option' => DB::unprepared('GRANT SELECT ON test_session_grants TO psikotes_runtime WITH GRANT OPTION'),
                    default => throw new RuntimeException("Unknown counterfeit component {$component}."),
                };
                $before = $this->grantDefinitions();
                try {
                    (require database_path('migrations/2026_09_09_000700_create_test_session_grants.php'))->up();
                    $this->fail("Counterfeit {$component} was accepted.");
                } catch (RuntimeException $exception) {
                    $this->assertStringContainsString('partial PostgreSQL enforcement', $exception->getMessage());
                }
                $this->assertEquals($before, $this->grantDefinitions());
            } finally {
                DB::rollBack();
            }
        });
    }

    /** @return iterable<string,array{string}> */
    public static function counterfeitDefinitions(): iterable
    {
        yield 'instrument check true' => ['instrument_check'];
        yield 'shape check true' => ['shape_check'];
        yield 'trigger omits delete event' => ['trigger_event'];
        yield 'unexpected extra trigger' => ['extra_trigger'];
        yield 'function returns before validation' => ['function_body'];
        yield 'function loses security definer' => ['function_security'];
        yield 'function unsafe search path' => ['function_search_path'];
        yield 'unexpected role grant' => ['unexpected_grantee'];
        yield 'runtime select grant option' => ['grant_option'];
    }

    /** @return array{branch:int,participant:int,case:int,order:int,entitlement:int,session:int} */
    private function directFixture(): array
    {
        $key = (string) Str::ulid();
        $branch = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => $key,
            'organization_code' => $key, 'display_name' => $key,
        ]);
        $package = DB::table('packages')->insertGetId([
            'code' => 'PKG-'.$key, 'name' => $key, 'amount' => 99000,
            'currency' => 'IDR', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['dass21', 'ist'] as $sort => $type) {
            DB::table('package_items')->insert([
                'package_id' => $package, 'test_type' => $type, 'sort_order' => $sort,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $branch, 'referral_branch_id' => $branch, 'referral_source' => 'default',
            'package_id' => $package, 'source_system' => 'DIRECT_PUBLIC',
            'full_name' => $key, 'phone' => '620000000000',
        ]);
        $publicId = (string) Str::ulid();
        $case = DB::table('assessment_cases')->insertGetId([
            'public_id' => $publicId, 'participant_id' => $participant,
            'organization_id' => $branch, 'package_id' => $package,
            'origin' => 'DIRECT_PUBLIC', 'intended_field_snapshot' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $paymentMethod = DB::table('payment_methods')->insertGetId([
            'code' => 'METHOD-'.$key, 'display_name' => $key, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $settledAt = now()->subMinute();
        $order = DB::table('orders')->insertGetId([
            'public_id' => $publicId, 'participant_id' => $participant,
            'assessment_case_id' => $case, 'payment_method_id' => $paymentMethod,
            'status' => 'paid', 'amount' => 99000, 'currency' => 'IDR', 'paid_at' => $settledAt,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['dass21', 'ist'] as $type) {
            $id = DB::table('entitlements')->insertGetId([
                'participant_id' => $participant, 'order_id' => $order, 'test_type' => $type,
                'assessment_case_id' => $type === 'dass21' ? null : $case,
                'status' => 'ready', 'ready_at' => $settledAt, 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($type === 'ist') {
                $entitlement = $id;
            }
        }
        $session = DB::table('test_sessions')->insertGetId([
            'public_id' => (string) Str::ulid(), 'participant_id' => $participant,
            'assessment_case_id' => $case, 'test_type' => 'ist', 'attempt_no' => 1,
            'authorization_id' => (string) Str::ulid(), 'allocation_intent_id' => (string) Str::ulid(),
            'duration_seconds' => 3600, 'status' => 'created', 'answers_revision' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return compact('branch', 'participant', 'case', 'order', 'entitlement', 'session');
    }

    /** @param array{branch:int,participant:int,case:int,order:int,entitlement:int,session:int} $fixture
     * @return array<string,mixed>
     */
    private function grantRow(array $fixture): array
    {
        return [
            'test_session_id' => $fixture['session'], 'assessment_case_id' => $fixture['case'],
            'participant_id' => $fixture['participant'], 'organization_id' => $fixture['branch'],
            'test_type' => 'ist', 'origin' => 'DIRECT_PUBLIC', 'grant_kind' => 'entitlement',
            'order_id' => $fixture['order'], 'entitlement_id' => $fixture['entitlement'],
            'created_at' => now(),
        ];
    }

    private function boundSession(int $participant, int $case, int $attemptNo = 1): int
    {
        return DB::table('test_sessions')->insertGetId([
            'public_id' => (string) Str::ulid(), 'participant_id' => $participant,
            'assessment_case_id' => $case, 'test_type' => 'ist', 'attempt_no' => $attemptNo,
            'authorization_id' => (string) Str::ulid(), 'allocation_intent_id' => (string) Str::ulid(),
            'duration_seconds' => 3600, 'status' => 'created', 'answers_revision' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param array<string,mixed> $fixture
     * @return array<string,mixed>
     */
    private function integratedGrantRow(array $fixture, int $session): array
    {
        foreach (['organization', 'participant', 'case', 'attempt', 'entitlement'] as $key) {
            if (! is_int($fixture[$key] ?? null)) {
                throw new RuntimeException("Integrated fixture {$key} is unavailable.");
            }
        }

        return [
            'test_session_id' => $session, 'assessment_case_id' => $fixture['case'],
            'participant_id' => $fixture['participant'], 'organization_id' => $fixture['organization'],
            'test_type' => 'ist', 'origin' => 'INTEGRATED', 'grant_kind' => 'assessment_entitlement',
            'assessment_participant_id' => $fixture['attempt'],
            'assessment_entitlement_id' => $fixture['entitlement'], 'created_at' => now(),
        ];
    }

    /** @return array<string,mixed> */
    private function integratedFixture(): array
    {
        $fixture = AssessmentAccessFixture::create();
        $settledAt = now()->subMinute();
        DB::table('assessment_entitlements')->where('assessment_participant_id', $fixture['attempt'])
            ->update(['ready_at' => $settledAt]);
        DB::table('assessment_bill_items')->where('bill_id', $fixture['bill'])
            ->update(['settled_at' => $settledAt]);
        DB::table('assessment_bills')->where('id', $fixture['bill'])
            ->update(['paid_at' => $settledAt]);

        return $fixture;
    }

    private function assertSqlState(string $state, callable $operation): void
    {
        DB::beginTransaction();
        try {
            $operation();
            $this->fail("Expected SQLSTATE {$state}.");
        } catch (QueryException $exception) {
            $this->assertSame($state, $exception->errorInfo[0] ?? null, $exception->getMessage());
        } finally {
            DB::rollBack();
        }
    }

    /** @return array<string,mixed> */
    private function grantDefinitions(): array
    {
        return [
            'columns' => DB::select(<<<'SQL'
                SELECT attribute.attname,format_type(attribute.atttypid,attribute.atttypmod) type,
                       attribute.attnotnull,
                       pg_get_expr(default_value.adbin,default_value.adrelid) default_value
                FROM pg_attribute attribute
                LEFT JOIN pg_attrdef default_value
                  ON default_value.adrelid=attribute.attrelid AND default_value.adnum=attribute.attnum
                WHERE attribute.attrelid='test_session_grants'::regclass
                  AND attribute.attnum>0 AND NOT attribute.attisdropped
                ORDER BY attribute.attnum
                SQL),
            'constraints' => DB::select("SELECT conname,pg_get_constraintdef(oid,false) definition FROM pg_constraint WHERE conrelid='test_session_grants'::regclass ORDER BY conname"),
            'indexes' => DB::select(<<<'SQL'
                SELECT indexname,indexdef
                FROM pg_indexes
                WHERE schemaname='public' AND (
                    indexname='test_session_grants_pkey'
                    OR indexname LIKE '%\_grant\_scope\_unique' ESCAPE '\'
                    OR indexname IN (
                        'test_session_grants_assessment_entitlement_unique',
                        'test_session_grants_entitlement_unique'
                    )
                )
                ORDER BY indexname
                SQL),
            'trigger' => DB::select("SELECT pg_get_triggerdef(oid,false) definition FROM pg_trigger WHERE tgrelid='test_session_grants'::regclass AND NOT tgisinternal ORDER BY tgname"),
            'function' => DB::select("SELECT pg_get_functiondef(proc.oid) definition,pg_get_userbyid(proc.proowner) owner FROM pg_proc proc JOIN pg_namespace namespace ON namespace.oid=proc.pronamespace WHERE namespace.nspname='app_private' AND proc.proname='guard_test_session_grant_identity'"),
            'table' => DB::select("SELECT relrowsecurity,relforcerowsecurity,pg_get_userbyid(relowner) owner FROM pg_class WHERE oid='test_session_grants'::regclass"),
            'policies' => DB::select(<<<'SQL'
                SELECT policyname,permissive,roles,cmd,qual,with_check
                FROM pg_policies
                WHERE schemaname='public' AND tablename='test_session_grants'
                ORDER BY policyname
                SQL),
            'acl' => DB::select(<<<'SQL'
                SELECT CASE WHEN acl.grantee=0 THEN 'PUBLIC' ELSE pg_get_userbyid(acl.grantee) END grantee,
                       acl.privilege_type,acl.is_grantable
                FROM pg_class class
                CROSS JOIN LATERAL aclexplode(COALESCE(class.relacl,acldefault('r',class.relowner))) acl
                WHERE class.oid='test_session_grants'::regclass AND acl.grantee <> class.relowner
                ORDER BY grantee,privilege_type
                SQL),
            'data' => DB::select('SELECT * FROM test_session_grants ORDER BY test_session_id'),
        ];
    }

    private function asOwner(callable $callback): void
    {
        $runtime = DB::getDefaultConnection();
        $config = config('database.connections.'.$runtime);
        config()->set('database.connections.test_session_grant_owner', [...$config, 'username' => 'org_test_owner']);
        DB::setDefaultConnection('test_session_grant_owner');
        Schema::clearResolvedInstance('db.schema');
        try {
            $this->assertSame('org_test_owner', DB::selectOne('SELECT current_user AS name')->name);
            $callback();
        } finally {
            DB::setDefaultConnection($runtime);
            Schema::clearResolvedInstance('db.schema');
            DB::purge('test_session_grant_owner');
            config()->set('database.connections.test_session_grant_owner', null);
        }
    }
}
