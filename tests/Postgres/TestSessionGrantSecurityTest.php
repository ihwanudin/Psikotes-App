<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

final class TestSessionGrantSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
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
            $this->assertSqlState('23505', fn () => DB::table('test_session_grants')->insert([
                ...$this->grantRow($second), 'entitlement_id' => $first['entitlement'],
                'order_id' => $first['order'],
            ]));

            DB::table('test_sessions')->where('id', $first['session'])->update([
                'status' => 'in_progress', 'started_at' => now(), 'ends_at' => now()->addHour(),
            ]);
            $this->assertSame('in_progress', DB::table('test_sessions')->where('id', $first['session'])->value('status'));
        });
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
        $order = DB::table('orders')->insertGetId([
            'public_id' => $publicId, 'participant_id' => $participant,
            'assessment_case_id' => $case, 'payment_method_id' => $paymentMethod,
            'status' => 'paid', 'amount' => 99000, 'currency' => 'IDR', 'paid_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['dass21', 'ist'] as $type) {
            $id = DB::table('entitlements')->insertGetId([
                'participant_id' => $participant, 'order_id' => $order, 'test_type' => $type,
                'status' => 'ready', 'ready_at' => now(), 'created_at' => now(), 'updated_at' => now(),
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
}
