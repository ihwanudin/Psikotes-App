<?php

declare(strict_types=1);

namespace Tests\Postgres;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use Tests\Support\AssessmentBillingFixture as Fixture;

/** DDL lifecycle evidence only; permission tests use the non-owner runtime connection. */
final class AssessmentBillingMigrationTest extends TestCase
{
    public function test_populated_policy_and_schema_rollback_preserve_legacy_and_reupgrade(): void
    {
        $this->assertFileExists('/.dockerenv');
        $this->assertSame('testing', app()->environment());
        $runId = getenv('ORG_TEST_RUN_ID');
        $this->assertIsString($runId);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $runId);
        $runtime = DB::getDefaultConnection();
        $config = config('database.connections.'.$runtime);
        $this->assertSame('org-test-db', $config['host']);
        $this->assertSame('psikotes_organization_test', $config['database']);
        $this->assertSame('psikotes_runtime', DB::selectOne('SELECT current_user AS name')->name);
        $marker = DB::selectOne("SELECT shobj_description(oid, 'pg_database') AS marker FROM pg_database WHERE datname = current_database()");
        $this->assertSame('ONCAM_ORG_TEST:'.$runId, $marker->marker);

        config()->set('database.connections.billing_ddl_test', [...$config, 'username' => 'org_test_owner']);
        $owner = DB::connection('billing_ddl_test');
        try {
            $this->assertSame('org_test_owner', $owner->selectOne('SELECT current_user AS name')->name);
            $owner->beginTransaction();
            DB::setDefaultConnection('billing_ddl_test');
            Schema::clearResolvedInstance('db.schema');
            $fixture = Fixture::create();
            DB::table('assessment_bill_items')->insert(Fixture::item($fixture));
            DB::table('assessment_entitlements')->insert(Fixture::entitlement($fixture));
            $method = DB::table('assessment_bills')->where('id', $fixture['bill'])->value('payment_method_id');
            $order = DB::table('orders')->insertGetId(['public_id' => (string) Str::ulid(), 'participant_id' => $fixture['participant'],
                'payment_method_id' => $method, 'amount' => 100, 'currency' => 'IDR', 'status' => 'pending']);
            $legacyAccess = DB::table('entitlements')->insertGetId(['participant_id' => $fixture['participant'], 'order_id' => $order,
                'test_type' => 'ist', 'status' => 'ready', 'ready_at' => now()]);
            $legacyIds = ['orders' => $order, 'entitlements' => $legacyAccess, 'participants' => $fixture['participant'],
                'branches' => $fixture['organization'], 'assessment_participants' => $fixture['attempt'], 'packages' => $fixture['package']];
            $legacy = [];
            foreach ($legacyIds as $table => $id) {
                $legacy[$table] = DB::table($table)->where('id', $id)->first();
            }
            $billing = [];
            foreach (['assessment_charges', 'assessment_bills', 'assessment_bill_items', 'assessment_entitlements'] as $table) {
                $columns = Schema::getColumnListing($table);
                if ($table === 'assessment_bills') {
                    $columns = array_values(array_diff($columns, [
                        'proof_checksum_sha256', 'proof_mime_type', 'proof_size_bytes', 'proof_uploaded_at',
                    ]));
                }
                $billing[$table] = (array) DB::table($table)->where('organization_id', $fixture['organization'])->first($columns);
            }
            $migrations = [];
            foreach (['000200_create_assessment_billing', '000300_create_assessment_bill_items',
                '000400_create_assessment_entitlements', '000500_secure_assessment_billing'] as $name) {
                $migrations[] = require database_path('migrations/2026_08_31_'.$name.'.php');
            }

            $migrations[3]->down();
            foreach ($billing as $table => $row) {
                $this->assertEquals($row, (array) DB::table($table)->where('id', $row['id'])->first(array_keys($row)));
                $policies = DB::table('pg_policies')->where('schemaname', 'public')->where('tablename', $table)->pluck('policyname')->all();
                $this->assertSame([$table.'_service'], $policies);
                $this->assertTrue(DB::selectOne('SELECT relforcerowsecurity FROM pg_class WHERE oid = to_regclass(?)', [$table])->relforcerowsecurity);
            }
            $migrations[3]->up();
            foreach ($billing as $table => $row) {
                $this->assertEquals($row, (array) DB::table($table)->where('id', $row['id'])->first(array_keys($row)));
                $this->assertSame(2, DB::table('pg_policies')->where('schemaname', 'public')->where('tablename', $table)->count());
            }

            foreach (array_reverse($migrations) as $migration) {
                $migration->down();
            }
            foreach ($billing as $table => $row) {
                $this->assertFalse(Schema::hasTable($table));
            }
            foreach ($legacy as $table => $row) {
                $this->assertEquals($row, DB::table($table)->where('id', $row->id)->first());
            }
            foreach ($migrations as $migration) {
                $migration->up();
            }
            foreach ($billing as $table => $row) {
                DB::table($table)->insert($row);
                $this->assertEquals($row, (array) DB::table($table)->where('id', $row['id'])->first(array_keys($row)));
            }
            $this->assertSame('locked', DB::table('assessment_entitlements')->where('organization_id', $fixture['organization'])->value('status'));
            foreach ($legacy as $table => $row) {
                $this->assertEquals($row, DB::table($table)->where('id', $row->id)->first());
            }
        } finally {
            if ($owner->transactionLevel() > 0) {
                $owner->rollBack();
            }
            DB::setDefaultConnection($runtime);
            Schema::clearResolvedInstance('db.schema');
            DB::purge('billing_ddl_test');
            config()->set('database.connections.billing_ddl_test', null);
        }
        $this->assertSame('psikotes_runtime', DB::selectOne('SELECT current_user AS name')->name);
    }
}
