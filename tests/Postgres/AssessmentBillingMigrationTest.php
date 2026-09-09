<?php

declare(strict_types=1);

namespace Tests\Postgres;

use Carbon\CarbonImmutable;
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
            $phaseTwoCaseMigration = require database_path('migrations/2026_09_09_000300_backfill_integrated_assessment_cases.php');
            $sessionCaseMigration = require database_path('migrations/2026_09_09_000400_harden_test_session_case_identity.php');
            $legacySelectionCaseMigration = require database_path('migrations/2026_09_09_000500_bind_legacy_selection_assessment_cases.php');
            $directPublicCaseMigration = require database_path('migrations/2026_09_09_000600_bind_direct_public_orders_to_assessment_cases.php');
            $sessionGrantMigration = require database_path('migrations/2026_09_09_000700_create_test_session_grants.php');
            $assessmentCaseStructure = $this->assessmentCaseStructure();
            $sessionGrantMigration->down();
            $directPublicCaseMigration->down();
            $legacySelectionCaseMigration->down();
            $sessionCaseMigration->down();
            $phaseTwoCaseMigration->down();
            $fixture = Fixture::create(withCase: false);
            DB::table('participants')->where('id', $fixture['participant'])
                ->update(['source_system' => 'P6B_TEST']);
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
                $columns = Schema::getColumnListing($table);
                if ($table === 'assessment_participants') {
                    $columns = array_values(array_diff($columns, ['assessment_case_id']));
                }
                $legacy[$table] = DB::table($table)->where('id', $id)->first($columns);
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
            $checkoutStructure = $this->checkoutStructure();
            $assessmentCaseMigration = require database_path('migrations/2026_09_09_000200_create_assessment_cases.php');

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

            // Descendants must be rolled back before the ancestor unique index they reference.
            $this->assertSame(0, DB::table('checkout_handoffs')->count());
            $this->migrateCheckoutDown();
            $this->assertFalse(Schema::hasTable('checkout_handoffs'));
            $this->assertSame(0, DB::table('assessment_cases')->count());
            $this->assertSame(0, DB::table('assessment_participants')->whereNotNull('assessment_case_id')->count());
            $this->assertSame(0, DB::table('test_sessions')->whereNotNull('assessment_case_id')->count());
            $assessmentCaseMigration->down();
            $this->assertFalse(Schema::hasTable('assessment_cases'));
            $this->assertFalse(Schema::hasColumn('assessment_participants', 'assessment_case_id'));
            $this->assertFalse(Schema::hasColumn('test_sessions', 'assessment_case_id'));
            foreach (array_reverse($migrations) as $migration) {
                $migration->down();
            }
            foreach ($billing as $table => $row) {
                $this->assertFalse(Schema::hasTable($table));
            }
            foreach ($legacy as $table => $row) {
                $this->assertEquals($row, DB::table($table)->where('id', $row->id)->first(array_keys((array) $row)));
            }
            foreach ($migrations as $migration) {
                $migration->up();
            }
            foreach ($billing as $table => $row) {
                DB::table($table)->insert($row);
                $this->assertEquals($row, (array) DB::table($table)->where('id', $row['id'])->first(array_keys($row)));
            }
            $this->migrateCheckoutUp();
            $this->assertEquals($checkoutStructure, $this->checkoutStructure());
            $assessmentCaseMigration->up();
            $phaseTwoCaseMigration->up();
            $sessionCaseMigration->up();
            $legacySelectionCaseMigration->up();
            $directPublicCaseMigration->up();
            $sessionGrantMigration->up();
            $this->assertEquals($assessmentCaseStructure, $this->assessmentCaseStructure());
            $source = DB::table('integration_sources')->insertGetId([
                'integration_client_id' => DB::table('assessment_participants')->where('id', $fixture['attempt'])
                    ->value('integration_client_id'),
                'source_system' => DB::table('assessment_participants')->where('id', $fixture['attempt'])
                    ->value('source_system'), 'contract_version' => 'checkout-v2',
                'allowed_assessment_packages' => '[]', 'allowed_funding_modes' => '[]', 'status' => 'ACTIVE',
            ]);
            DB::table('checkout_handoffs')->insert($this->checkoutRow($fixture, $source));
            $this->assertSame(1, DB::table('checkout_handoffs')->where('assessment_participant_id', $fixture['attempt'])->count());
            $this->assertSame('locked', DB::table('assessment_entitlements')->where('organization_id', $fixture['organization'])->value('status'));
            foreach ($legacy as $table => $row) {
                $this->assertEquals($row, DB::table($table)->where('id', $row->id)->first(array_keys((array) $row)));
            }
        } finally {
            // PostgreSQL transactional DDL restores descendant/ancestor order on any assertion failure.
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

    /** @return array<string, mixed> */
    private function checkoutStructure(): array
    {
        return [
            'columns' => DB::select("SELECT attname, format_type(atttypid, atttypmod) AS type, attnotnull,
                pg_get_expr(adbin, adrelid) AS default_value FROM pg_attribute
                LEFT JOIN pg_attrdef ON adrelid = attrelid AND adnum = attnum
                WHERE attrelid = 'checkout_handoffs'::regclass AND attnum > 0 AND NOT attisdropped ORDER BY attnum"),
            'constraints' => DB::select("SELECT conname, pg_get_constraintdef(oid) AS definition
                FROM pg_constraint WHERE conrelid = 'checkout_handoffs'::regclass ORDER BY conname"),
            'indexes' => DB::select("SELECT indexname, indexdef FROM pg_indexes
                WHERE schemaname = 'public' AND tablename = 'checkout_handoffs' ORDER BY indexname"),
            'policies' => DB::select("SELECT * FROM pg_policies
                WHERE schemaname = 'public' AND tablename = 'checkout_handoffs' ORDER BY policyname"),
            'security' => DB::select("SELECT relrowsecurity, relforcerowsecurity, relowner
                FROM pg_class WHERE oid = 'checkout_handoffs'::regclass"),
            'parent_scope_constraints' => DB::select("SELECT conrelid::regclass::text AS table_name, conname,
                pg_get_constraintdef(oid) AS definition FROM pg_constraint
                WHERE conname IN (
                    'assessment_attempt_checkout_handoff_scope_unique',
                    'integration_clients_checkout_handoff_scope_unique',
                    'integration_sources_checkout_handoff_scope_unique'
                ) ORDER BY conname"),
        ];
    }

    /** @return array<string, mixed> */
    private function assessmentCaseStructure(): array
    {
        return [
            'columns' => DB::select("SELECT attname, format_type(atttypid, atttypmod) AS type, attnotnull,
                pg_get_expr(adbin, adrelid) AS default_value FROM pg_attribute
                LEFT JOIN pg_attrdef ON adrelid = attrelid AND adnum = attnum
                WHERE attrelid = 'assessment_cases'::regclass AND attnum > 0 AND NOT attisdropped ORDER BY attnum"),
            'constraints' => DB::select("SELECT conname, pg_get_constraintdef(oid) AS definition
                FROM pg_constraint WHERE conrelid = 'assessment_cases'::regclass ORDER BY conname"),
            'indexes' => DB::select("SELECT indexname, indexdef FROM pg_indexes
                WHERE schemaname = 'public' AND tablename = 'assessment_cases' ORDER BY indexname"),
            'policies' => DB::select("SELECT * FROM pg_policies
                WHERE schemaname = 'public' AND tablename = 'assessment_cases' ORDER BY policyname"),
            'security' => DB::select("SELECT relrowsecurity, relforcerowsecurity, relowner
                FROM pg_class WHERE oid = 'assessment_cases'::regclass"),
            'parent_links' => DB::select("SELECT conrelid::regclass::text AS table_name, conname,
                pg_get_constraintdef(oid) AS definition FROM pg_constraint
                WHERE conname IN (
                    'assessment_participants_case_fk',
                    'test_sessions_assessment_case_fk'
                ) ORDER BY conname"),
        ];
    }

    private function migrateCheckoutUp(): void
    {
        DB::transaction(function (): void {
            $migration = require database_path('migrations/2026_09_02_000100_create_checkout_handoffs.php');
            if (! is_object($migration) || ! method_exists($migration, 'up')) {
                throw new \RuntimeException('Checkout handoff migration has no up method.');
            }
            $migration->up();

            $sessions = require database_path('migrations/2026_09_02_000200_create_checkout_sessions.php');
            if (! is_object($sessions) || ! method_exists($sessions, 'up')) {
                throw new \RuntimeException('Checkout session migration has no up method.');
            }
            $sessions->up();
        });
    }

    private function migrateCheckoutDown(): void
    {
        DB::transaction(function (): void {
            $sessions = require database_path('migrations/2026_09_02_000200_create_checkout_sessions.php');
            if (! is_object($sessions) || ! method_exists($sessions, 'down')) {
                throw new \RuntimeException('Checkout session migration has no down method.');
            }
            $sessions->down();

            $migration = require database_path('migrations/2026_09_02_000100_create_checkout_handoffs.php');
            if (! is_object($migration) || ! method_exists($migration, 'down')) {
                throw new \RuntimeException('Checkout handoff migration has no down method.');
            }
            $migration->down();
        });
    }

    /** @param array<string, int|string> $fixture
     * @return array<string, mixed>
     */
    private function checkoutRow(array $fixture, int $source): array
    {
        $issued = CarbonImmutable::now('UTC')->startOfSecond();
        $attempt = DB::table('assessment_participants')->where('id', $fixture['attempt'])->first();

        return [
            'public_id' => (string) Str::ulid(), 'assessment_participant_id' => $fixture['attempt'],
            'organization_id' => $fixture['organization'], 'participant_id' => $fixture['participant'],
            'package_id' => $fixture['package'], 'integration_client_id' => $attempt->integration_client_id,
            'integration_source_id' => $source, 'source_system' => $attempt->source_system,
            'contract_version' => 'checkout-v2', 'purpose' => 'checkout-handoff',
            'destination' => 'integrated-checkout-session', 'token_digest' => hash('sha256', 'migration-token'),
            'active_marker' => true, 'status' => 'ISSUED', 'issue_number' => 1,
            'issue_idempotency_key_digest' => hash('sha256', 'migration-idempotency'),
            'request_hash' => hash('sha256', 'migration-request'), 'issued_at' => $issued,
            'expires_at' => $issued->addMinutes(10), 'created_at' => $issued, 'updated_at' => $issued,
        ];
    }
}
