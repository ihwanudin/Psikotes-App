<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentAccessFixture;

final class TestSessionGrantSchemaTest extends OrganizationPaymentTestCase
{
    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            RefreshDatabaseState::$migrated = false;
        }
    }

    use RefreshDatabase;

    public function test_migration_is_additive_and_never_guesses_historical_grants(): void
    {
        $session = $this->unboundSession();

        $this->assertTrue(Schema::hasTable('test_session_grants'));
        $this->assertDatabaseMissing('test_session_grants', ['test_session_id' => $session]);
        $this->assertDatabaseHas('test_sessions', ['id' => $session, 'assessment_case_id' => null]);

        $columns = collect(DB::select("PRAGMA table_info('test_session_grants')"))->keyBy('name');
        foreach ([
            'test_session_id', 'assessment_case_id', 'participant_id', 'organization_id',
            'test_type', 'origin', 'grant_kind', 'assessment_participant_id', 'order_id',
            'selection_participant_id', 'assessment_entitlement_id', 'entitlement_id', 'created_at',
        ] as $column) {
            $this->assertArrayHasKey($column, $columns);
        }

        $this->assertSame(1, (int) $columns['test_session_id']->pk);
        foreach (['assessment_case_id', 'participant_id', 'organization_id', 'test_type', 'origin', 'grant_kind', 'created_at'] as $column) {
            $this->assertSame(1, (int) $columns[$column]->notnull, $column);
        }
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    public function test_shape_checks_reject_dass_polymorphic_or_mismatched_origin_grants(): void
    {
        $graph = $this->directGraph('dass-shape');
        $session = $this->createDassSessionForConstraintProbe($graph['participant'], $graph['case']);
        $base = [
            'test_session_id' => $session,
            'assessment_case_id' => $graph['case'],
            'participant_id' => $graph['participant'],
            'organization_id' => $graph['branch'],
            'test_type' => 'dass21',
            'origin' => 'DIRECT_PUBLIC',
            'grant_kind' => 'entitlement',
            'order_id' => $graph['order'],
            'entitlement_id' => $graph['dass_entitlement'],
            'created_at' => now(),
        ];

        $this->assertRejected(fn () => DB::table('test_session_grants')->insert($base), 'test_session_grants_instrument_check');
        $this->assertRejected(fn () => DB::table('test_session_grants')->insert([
            ...$this->directGrant($graph),
            'grant_kind' => 'assessment_entitlement',
            'assessment_entitlement_id' => 1,
        ]), 'test_session_grants_shape_check');
    }

    public function test_each_supported_origin_accepts_only_its_exact_ready_graph(): void
    {
        $direct = $this->directGraph('valid-direct');
        $this->insertGrant($this->directGrant($direct));

        $integrated = AssessmentAccessFixture::create();
        $integratedSession = $this->createBoundSession($integrated['participant'], (int) $integrated['case']);
        $this->insertGrant([
            'test_session_id' => $integratedSession, 'assessment_case_id' => $integrated['case'],
            'participant_id' => $integrated['participant'], 'organization_id' => $integrated['organization'],
            'test_type' => 'ist', 'origin' => 'INTEGRATED', 'grant_kind' => 'assessment_entitlement',
            'assessment_participant_id' => $integrated['attempt'],
            'assessment_entitlement_id' => $integrated['entitlement'], 'created_at' => now(),
        ]);

        $legacy = $this->legacyGraph('valid-legacy');
        $this->insertGrant($this->legacyGrant($legacy));
        $this->assertSame(3, DB::table('test_session_grants')->count());
    }

    public function test_direct_grant_rejects_extra_case_order_orderless_entitlement_and_composition_drift(): void
    {
        $extraCase = $this->directGraph('extra-case');
        DB::table('assessment_cases')->insert([
            'public_id' => (string) Str::ulid(), 'participant_id' => $extraCase['participant'],
            'organization_id' => $extraCase['branch'], 'package_id' => $extraCase['package'],
            'origin' => 'DIRECT_PUBLIC', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertRejected(fn () => $this->insertGrant($this->directGrant($extraCase)), 'exact durable source graph');

        $extraOrder = $this->directGraph('extra-order');
        $orderGuard = DB::selectOne("SELECT sql FROM sqlite_master WHERE type='trigger' AND name='orders_direct_case_insert_guard'");
        $this->assertNotNull($orderGuard);
        DB::statement('DROP TRIGGER orders_direct_case_insert_guard');
        try {
            $secondPublicId = (string) Str::ulid();
            $secondCase = DB::table('assessment_cases')->insertGetId(['public_id' => $secondPublicId,
                'participant_id' => $extraOrder['participant'], 'organization_id' => $extraOrder['branch'],
                'package_id' => $extraOrder['package'], 'origin' => 'DIRECT_PUBLIC',
                'created_at' => now(), 'updated_at' => now()]);
            DB::table('orders')->insert(['public_id' => $secondPublicId,
                'participant_id' => $extraOrder['participant'], 'assessment_case_id' => $secondCase,
                'payment_method_id' => $extraOrder['payment_method'], 'status' => 'paid', 'amount' => 99000,
                'currency' => 'IDR', 'paid_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        } finally {
            DB::statement((string) $orderGuard->sql);
        }
        $this->assertRejected(fn () => $this->insertGrant($this->directGrant($extraOrder)), 'exact durable source graph');

        $orderless = $this->directGraph('orderless');
        DB::table('package_items')->insert(['package_id' => $orderless['package'], 'test_type' => 'papi',
            'sort_order' => 3, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertRejected(fn () => DB::table('entitlements')->insert([
            'participant_id' => $orderless['participant'], 'assessment_case_id' => $orderless['case'], 'order_id' => null,
            'test_type' => 'papi', 'status' => 'ready', 'ready_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]), 'exact case source graph');

        $drift = $this->directGraph('composition-drift');
        DB::table('package_items')->insert(['package_id' => $drift['package'], 'test_type' => 'papi',
            'sort_order' => 3, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertRejected(fn () => $this->insertGrant($this->directGrant($drift)), 'exact durable source graph');

        $missingDass = $this->directGraph('missing-dass');
        DB::table('entitlements')->where('id', $missingDass['dass_entitlement'])->delete();
        DB::table('package_items')->where('package_id', $missingDass['package'])->where('test_type', 'dass21')->delete();
        $this->assertRejected(fn () => $this->insertGrant($this->directGrant($missingDass)), 'exact durable source graph');
    }

    public function test_integrated_grant_requires_durable_checkout_price_and_settlement_graph(): void
    {
        foreach (['metadata', 'snapshot', 'settlement', 'composition'] as $corruption) {
            $graph = AssessmentAccessFixture::create();
            $session = $this->createBoundSession($graph['participant'], (int) $graph['case']);
            match ($corruption) {
                'metadata' => DB::table('assessment_participants')->where('id', $graph['attempt'])->update(['metadata' => '{}']),
                'snapshot' => DB::table('assessment_charges')->where('id', $graph['charge'])->update(['price_snapshot' => json_encode([
                    'version' => 1, 'packageId' => $graph['package'], 'packageCode' => 'synthetic',
                    'packageName' => 'Synthetic', 'testTypes' => ['dass21'], 'baseAmount' => 100,
                    'consultationRequested' => false, 'consultationAmount' => 0, 'amount' => 100, 'currency' => 'IDR',
                ], JSON_THROW_ON_ERROR)]),
                'settlement' => DB::table('assessment_bills')->where('id', $graph['bill'])->update(['status' => 'pending', 'paid_at' => null]),
                'composition' => DB::table('package_items')->where('package_id', $graph['package'])->where('test_type', 'dass21')->delete(),
            };

            $this->assertRejected(fn () => $this->insertGrant([
                'test_session_id' => $session, 'assessment_case_id' => $graph['case'],
                'participant_id' => $graph['participant'], 'organization_id' => $graph['organization'],
                'test_type' => 'ist', 'origin' => 'INTEGRATED', 'grant_kind' => 'assessment_entitlement',
                'assessment_participant_id' => $graph['attempt'],
                'assessment_entitlement_id' => $graph['entitlement'], 'created_at' => now(),
            ]), 'exact durable source graph');
        }
    }

    #[DataProvider('sqliteCorruptions')]
    public function test_rerun_rejects_counterfeit_sqlite_state_without_delta(string $component): void
    {
        match ($component) {
            'support_index' => DB::unprepared('DROP INDEX test_sessions_grant_scope_unique; CREATE INDEX test_sessions_grant_scope_unique ON test_sessions (id, assessment_case_id, participant_id, test_type)'),
            'insert_guard' => DB::unprepared("DROP TRIGGER test_session_grants_insert_guard; CREATE TRIGGER test_session_grants_insert_guard BEFORE INSERT ON test_session_grants FOR EACH ROW BEGIN SELECT RAISE(ABORT, 'counterfeit'); END"),
            'insert_when_false' => $this->replaceSqliteTrigger('test_session_grants_insert_guard', fn (string $sql): string => preg_replace('/\bWHEN\b/', 'WHEN 0 AND', $sql, 1) ?? $sql),
            'insert_comment' => DB::unprepared('DROP TRIGGER test_session_grants_insert_guard; CREATE TRIGGER test_session_grants_insert_guard BEFORE INSERT ON test_session_grants FOR EACH ROW WHEN 0 BEGIN SELECT 1; /* case_row.package_id IS NULL; SELECT 1 FROM package_items item; exact durable source graph */ END'),
            'update_guard' => DB::unprepared("DROP TRIGGER test_session_grants_update_guard; CREATE TRIGGER test_session_grants_update_guard AFTER UPDATE ON test_session_grants FOR EACH ROW BEGIN SELECT RAISE(ABORT, 'Test session grant history is append-only'); END"),
            'update_when_false' => DB::unprepared("DROP TRIGGER test_session_grants_update_guard; CREATE TRIGGER test_session_grants_update_guard BEFORE UPDATE ON test_session_grants FOR EACH ROW WHEN 0 BEGIN SELECT RAISE(ABORT, 'Test session grant history is append-only'); END"),
            'instrument_check' => $this->replaceSqliteTableDefinition("CONSTRAINT test_session_grants_instrument_check CHECK (test_type IN ('ist','papi','rmib','kraepelin'))", 'CONSTRAINT test_session_grants_instrument_check CHECK (1)'),
            'extra_trigger' => DB::unprepared('CREATE TRIGGER test_session_grants_counterfeit_guard BEFORE INSERT ON test_session_grants FOR EACH ROW BEGIN SELECT 1; END'),
            default => throw new RuntimeException('Unknown synthetic corruption.'),
        };
        $before = DB::select("SELECT type,name,sql FROM sqlite_master WHERE name LIKE 'test_session_grants_%' OR name='test_sessions_grant_scope_unique' ORDER BY type,name");
        try {
            $this->migrate('up');
            $this->fail('Counterfeit state was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('partial SQLite enforcement', $exception->getMessage());
        }
        $this->assertEquals($before, DB::select("SELECT type,name,sql FROM sqlite_master WHERE name LIKE 'test_session_grants_%' OR name='test_sessions_grant_scope_unique' ORDER BY type,name"));
    }

    /** @return iterable<string,array{string}> */
    public static function sqliteCorruptions(): iterable
    {
        yield 'support index definition' => ['support_index'];
        yield 'insert guard body' => ['insert_guard'];
        yield 'insert guard disabled by false predicate' => ['insert_when_false'];
        yield 'insert guard expected fragments hidden in comment' => ['insert_comment'];
        yield 'update guard event' => ['update_guard'];
        yield 'update guard disabled by false predicate' => ['update_when_false'];
        yield 'instrument check replaced by true' => ['instrument_check'];
        yield 'unexpected extra trigger' => ['extra_trigger'];
    }

    public function test_empty_down_up_is_safe_but_populated_down_refuses_without_delta(): void
    {
        $this->migrate('down');
        $this->migrate('up');

        $graph = $this->directGraph('populated');
        $session = $graph['session'];
        DB::table('test_session_grants')->insert([
            'test_session_id' => $session,
            'assessment_case_id' => $graph['case'],
            'participant_id' => $graph['participant'],
            'organization_id' => $graph['branch'],
            'test_type' => 'ist',
            'origin' => 'DIRECT_PUBLIC',
            'grant_kind' => 'entitlement',
            'order_id' => $graph['order'],
            'entitlement_id' => $graph['entitlement'],
            'created_at' => now(),
        ]);
        $before = DB::select("SELECT type,name,sql FROM sqlite_master WHERE tbl_name='test_session_grants' OR name LIKE 'test_session_grants_%' ORDER BY type,name");

        try {
            $this->migrate('down');
            $this->fail('Populated grant history must refuse rollback.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Test session grant history prevents rollback.', $exception->getMessage());
        }

        $this->assertEquals($before, DB::select("SELECT type,name,sql FROM sqlite_master WHERE tbl_name='test_session_grants' OR name LIKE 'test_session_grants_%' ORDER BY type,name"));
        $this->assertDatabaseHas('test_session_grants', ['test_session_id' => $session]);
    }

    private function unboundSession(): int
    {
        $key = (string) Str::ulid();
        $branch = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => 'Grant schema',
            'organization_code' => $key, 'display_name' => 'Grant schema',
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $branch, 'referral_branch_id' => $branch, 'referral_source' => 'default',
            'package_id' => null, 'source_system' => 'P4_TEST',
            'full_name' => 'Grant schema', 'phone' => '620000000000',
        ]);

        return DB::table('test_sessions')->insertGetId([
            'public_id' => (string) Str::ulid(), 'participant_id' => $participant,
            'assessment_case_id' => null, 'test_type' => 'ist', 'attempt_no' => 1,
            'authorization_id' => (string) Str::ulid(), 'allocation_intent_id' => (string) Str::ulid(),
            'duration_seconds' => 3600, 'status' => 'created', 'answers_revision' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{branch:int,package:int,participant:int,case:int,payment_method:int,order:int,entitlement:int,dass_entitlement:int,session:int} */
    private function directGraph(string $suffix): array
    {
        $key = (string) Str::ulid();
        $branch = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => $suffix,
            'organization_code' => $key, 'display_name' => $suffix,
        ]);
        $package = DB::table('packages')->insertGetId([
            'code' => 'PKG-'.$key, 'name' => $suffix, 'amount' => 99000,
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
                'participant_id' => $participant, 'assessment_case_id' => $type === 'dass21' ? null : $case,
                'order_id' => $order, 'test_type' => $type,
                'status' => 'ready', 'ready_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($type === 'ist') {
                $entitlement = $id;
            } else {
                $dass_entitlement = $id;
            }
        }

        $session = $this->createBoundSession($participant, $case);

        $payment_method = $paymentMethod;

        return compact('branch', 'package', 'participant', 'case', 'payment_method', 'order', 'entitlement', 'dass_entitlement', 'session');
    }

    private function createBoundSession(int $participant, int $case, string $testType = 'ist'): int
    {
        return DB::table('test_sessions')->insertGetId([
            'public_id' => (string) Str::ulid(), 'participant_id' => $participant,
            'assessment_case_id' => $case, 'test_type' => $testType, 'attempt_no' => 1,
            'authorization_id' => (string) Str::ulid(), 'allocation_intent_id' => (string) Str::ulid(),
            'duration_seconds' => 3600, 'status' => 'created', 'answers_revision' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function createDassSessionForConstraintProbe(int $participant, int $case): int
    {
        $trigger = DB::selectOne("SELECT sql FROM sqlite_master WHERE type='trigger' AND name='test_sessions_contract_insert'");
        if ($trigger === null) {
            throw new RuntimeException('Test session insert guard is unavailable.');
        }
        DB::statement('DROP TRIGGER test_sessions_contract_insert');
        try {
            return $this->createBoundSession($participant, $case, 'dass21');
        } finally {
            DB::statement((string) $trigger->sql);
        }
    }

    /** @param array{branch:int,participant:int,case:int,order:int,entitlement:int,session:int} $graph
     * @return array<string,mixed>
     */
    private function directGrant(array $graph): array
    {
        return ['test_session_id' => $graph['session'], 'assessment_case_id' => $graph['case'],
            'participant_id' => $graph['participant'], 'organization_id' => $graph['branch'],
            'test_type' => 'ist', 'origin' => 'DIRECT_PUBLIC', 'grant_kind' => 'entitlement',
            'order_id' => $graph['order'], 'entitlement_id' => $graph['entitlement'], 'created_at' => now()];
    }

    /** @return array{branch:int,participant:int,case:int,selection:int,entitlement:int,session:int} */
    private function legacyGraph(string $suffix): array
    {
        $key = (string) Str::ulid();
        $branch = DB::table('branches')->insertGetId(['code' => $key, 'ref_code' => $key, 'name' => $suffix,
            'organization_code' => $key, 'display_name' => $suffix]);
        $participant = DB::table('participants')->insertGetId(['branch_id' => $branch,
            'referral_branch_id' => $branch, 'referral_source' => 'manual', 'package_id' => null,
            'source_system' => 'SELEKSI_BEASISWA_JEPANG', 'full_name' => $suffix, 'phone' => '620000000000']);
        $case = DB::table('assessment_cases')->insertGetId(['public_id' => (string) Str::ulid(),
            'participant_id' => $participant, 'organization_id' => $branch, 'package_id' => null,
            'origin' => 'LEGACY_SELECTION', 'created_at' => now(), 'updated_at' => now()]);
        $selection = DB::table('selection_participants')->insertGetId(['client_id' => 'client-'.$key,
            'external_candidate_id' => 'candidate-'.$key, 'selection_round_id' => 'round-'.$key,
            'registration_id' => 'registration-'.$key, 'participant_id' => $participant,
            'assessment_case_id' => $case, 'idempotency_key' => 'key-'.$key,
            'request_hash' => hash('sha256', $key), 'created_at' => now(), 'updated_at' => now()]);
        $entitlement = DB::table('entitlements')->insertGetId(['participant_id' => $participant,
            'assessment_case_id' => $case, 'order_id' => null, 'test_type' => 'ist', 'status' => 'ready', 'ready_at' => now(),
            'created_at' => now(), 'updated_at' => now()]);
        $session = $this->createBoundSession($participant, $case);

        return compact('branch', 'participant', 'case', 'selection', 'entitlement', 'session');
    }

    /** @param array{branch:int,participant:int,case:int,selection:int,entitlement:int,session:int} $graph
     * @return array<string,mixed>
     */
    private function legacyGrant(array $graph): array
    {
        return ['test_session_id' => $graph['session'], 'assessment_case_id' => $graph['case'],
            'participant_id' => $graph['participant'], 'organization_id' => $graph['branch'],
            'test_type' => 'ist', 'origin' => 'LEGACY_SELECTION', 'grant_kind' => 'entitlement',
            'selection_participant_id' => $graph['selection'], 'entitlement_id' => $graph['entitlement'],
            'created_at' => now()];
    }

    /** @param array<string,mixed> $grant */
    private function insertGrant(array $grant): void
    {
        DB::table('test_session_grants')->insert($grant);
    }

    private function migrate(string $direction): void
    {
        $migration = require database_path('migrations/2026_09_09_000700_create_test_session_grants.php');
        if (! is_object($migration) || ! in_array($direction, ['up', 'down'], true) || ! method_exists($migration, $direction)) {
            throw new RuntimeException("Migration operation {$direction} is unavailable.");
        }

        (new ReflectionMethod($migration, $direction))->invoke($migration);
    }

    private function assertRejected(callable $operation, string $message): void
    {
        try {
            $operation();
            $this->fail('Expected database rejection.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    /** @param callable(string):string $transform */
    private function replaceSqliteTrigger(string $name, callable $transform): void
    {
        $trigger = DB::selectOne("SELECT sql FROM sqlite_master WHERE type='trigger' AND name=?", [$name]);
        if ($trigger === null) {
            throw new RuntimeException("Missing SQLite trigger {$name}.");
        }
        DB::statement("DROP TRIGGER {$name}");
        $result = DB::connection()->getPdo()->exec($transform((string) $trigger->sql));
        if ($result === false) {
            throw new RuntimeException("Unable to replace SQLite trigger {$name}.");
        }
    }

    private function replaceSqliteTableDefinition(string $from, string $to): void
    {
        DB::statement('PRAGMA writable_schema = ON');
        try {
            DB::update("UPDATE sqlite_master SET sql=replace(sql, ?, ?) WHERE type='table' AND name='test_session_grants'", [$from, $to]);
        } finally {
            DB::statement('PRAGMA writable_schema = OFF');
        }
    }
}
