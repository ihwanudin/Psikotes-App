<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Security\RlsContextRunner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GenericEntitlementCaseIdentityMigrationTest extends TestCase
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

    public function test_postgres_catalog_has_exact_case_scope_contract(): void
    {
        $column = DB::selectOne("SELECT attnotnull FROM pg_attribute WHERE attrelid='entitlements'::regclass AND attname='assessment_case_id' AND NOT attisdropped");
        $foreign = DB::selectOne("SELECT convalidated,condeferrable,condeferred,pg_get_constraintdef(oid,false) definition FROM pg_constraint WHERE conrelid='entitlements'::regclass AND conname='entitlements_case_scope_fk'");
        $indexes = DB::table('pg_indexes')->where('schemaname', 'public')->where('tablename', 'entitlements')
            ->whereIn('indexname', ['entitlements_case_test_type_unique', 'entitlements_case_grant_scope_unique'])
            ->pluck('indexdef', 'indexname');
        $trigger = DB::selectOne(<<<'SQL'
            SELECT t.tgtype,t.tgenabled,p.prosecdef,p.proconfig,n.nspname||'.'||p.proname function_name
            FROM pg_trigger t JOIN pg_proc p ON p.oid=t.tgfoid
            JOIN pg_namespace n ON n.oid=p.pronamespace
            WHERE t.tgrelid='entitlements'::regclass AND t.tgname='entitlements_case_identity_guard'
              AND NOT t.tgisinternal
            SQL);

        $this->assertFalse($column->attnotnull);
        $this->assertTrue($foreign->convalidated);
        $this->assertFalse($foreign->condeferrable);
        $this->assertFalse($foreign->condeferred);
        $this->assertSame('FOREIGN KEY (assessment_case_id, participant_id) REFERENCES assessment_cases(id, participant_id) ON DELETE RESTRICT', $foreign->definition);
        $this->assertStringContainsString('UNIQUE', $indexes['entitlements_case_test_type_unique']);
        $this->assertStringContainsString('WHERE (assessment_case_id IS NOT NULL)', $indexes['entitlements_case_test_type_unique']);
        $this->assertStringContainsString('(id, assessment_case_id, participant_id, test_type)', $indexes['entitlements_case_grant_scope_unique']);
        $this->assertSame(23, (int) $trigger->tgtype);
        $this->assertSame('O', $trigger->tgenabled);
        $this->assertTrue($trigger->prosecdef);
        $this->assertSame('{"search_path=pg_catalog, public"}', $trigger->proconfig);
        $this->assertSame('app_private.guard_generic_entitlement_case_identity', $trigger->function_name);
    }

    public function test_service_can_use_compatibility_null_but_cannot_bind_dass_or_cross_case(): void
    {
        DB::rollBack();
        $this->asOwner(fn () => $this->requirementMigration('down'));
        try {
            DB::beginTransaction();
            app(RlsContextRunner::class)->runAsService(function (): void {
                $before = DB::table('entitlements')->count();
                $first = $this->directGraph('first');
                $second = $this->directGraph('second');
                DB::table('entitlements')->insert($this->entitlement($first['participant'], null, 'papi'));
                $this->assertDatabaseCount('entitlements', $before + 5);
                $this->assertSqlState('23514', fn () => DB::table('entitlements')->insert([
                    ...$this->entitlement($first['participant'], $first['order'], 'rmib'),
                    'assessment_case_id' => $second['case'],
                ]));
                $this->assertSqlState('23514', fn () => DB::table('entitlements')->where('id', $first['dass'])
                    ->update(['assessment_case_id' => $first['case']]));
                $this->assertSqlState('P0001', fn () => DB::table('entitlements')->where('id', $first['generic'])
                    ->update(['assessment_case_id' => null]));
            });
            DB::rollBack();

            $this->asOwner(fn () => $this->requirementMigration('up'));
            DB::beginTransaction();
            app(RlsContextRunner::class)->runAsService(function (): void {
                $graph = $this->directGraph('enforced');
                $this->assertSqlState('23514', fn () => DB::table('entitlements')->insert(
                    $this->entitlement($graph['participant'], null, 'papi'),
                ));
            });
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->asOwner(fn () => $this->requirementMigration('up'));
            DB::beginTransaction();
        }
    }

    /** @return array{branch:int,participant:int,case:int,order:int,generic:int,dass:int} */
    private function directGraph(string $suffix): array
    {
        $key = (string) Str::ulid();
        $branch = DB::table('branches')->insertGetId([
            'code' => $key, 'name' => $suffix, 'ref_code' => $key,
            'organization_code' => $key, 'display_name' => $suffix,
        ]);
        $package = DB::table('packages')->insertGetId([
            'code' => 'package-'.$key, 'name' => $suffix, 'amount' => 99000,
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
            'full_name' => $suffix, 'phone' => '620000000000',
        ]);
        $publicId = (string) Str::ulid();
        $case = DB::table('assessment_cases')->insertGetId([
            'public_id' => $publicId, 'participant_id' => $participant, 'organization_id' => $branch,
            'package_id' => $package, 'origin' => 'DIRECT_PUBLIC', 'intended_field_snapshot' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $method = DB::table('payment_methods')->insertGetId([
            'code' => 'method-'.$key, 'display_name' => $suffix, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $order = DB::table('orders')->insertGetId([
            'public_id' => $publicId, 'participant_id' => $participant, 'assessment_case_id' => $case,
            'payment_method_id' => $method, 'status' => 'pending', 'amount' => 99000,
            'currency' => 'IDR', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $dass = DB::table('entitlements')->insertGetId($this->entitlement($participant, $order, 'dass21'));
        $generic = DB::table('entitlements')->insertGetId([
            ...$this->entitlement($participant, $order, 'ist'), 'assessment_case_id' => $case,
        ]);

        return compact('branch', 'participant', 'case', 'order', 'generic', 'dass');
    }

    /** @return array<string,mixed> */
    private function entitlement(int $participant, ?int $order, string $type): array
    {
        return ['participant_id' => $participant, 'order_id' => $order, 'test_type' => $type,
            'status' => 'locked', 'created_at' => now(), 'updated_at' => now()];
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

    private function assertDatabaseCount(string $table, int $count): void
    {
        $this->assertSame($count, DB::table($table)->count());
    }

    private function requirementMigration(string $operation): void
    {
        $migration = require database_path('migrations/2026_09_10_000400_enforce_generic_entitlement_case_identity.php');
        if (! is_object($migration) || ! is_callable([$migration, $operation])) {
            throw new RuntimeException("Requirement migration operation {$operation} is unavailable.");
        }[$migration, $operation]();
    }

    private function asOwner(callable $callback): void
    {
        $runtime = DB::getDefaultConnection();
        $config = config('database.connections.'.$runtime);
        config()->set('database.connections.generic_case_migration_owner', [...$config, 'username' => 'org_test_owner']);
        DB::setDefaultConnection('generic_case_migration_owner');
        Schema::clearResolvedInstance('db.schema');
        try {
            $callback();
        } finally {
            DB::setDefaultConnection($runtime);
            Schema::clearResolvedInstance('db.schema');
            DB::purge('generic_case_migration_owner');
            config()->set('database.connections.generic_case_migration_owner', null);
        }
    }
}
