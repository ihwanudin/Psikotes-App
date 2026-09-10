<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;

final class GenericEntitlementCaseRequirementMigrationTest extends OrganizationPaymentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->migrate('down');
    }

    public function test_conditional_requirement_preserves_exact_schema_and_child_grant_then_rolls_back_only_it(): void
    {
        $graph = $this->directGraph('valid');
        $before = $this->baseObjects();

        $this->migrate('up');
        $this->migrate('up');

        $this->assertSame($before, $this->baseObjects());
        $this->assertSame($graph['entitlement'], DB::table('test_session_grants')->sole()->entitlement_id);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->assertRejected(fn () => DB::table('entitlements')->insert(
            $this->entitlementRow($graph['participant'], $graph['order'], null, 'papi'),
        ));
        $this->assertRejected(fn () => DB::table('entitlements')->insert(
            $this->entitlementRow($graph['participant'], $graph['order'], $graph['case'], 'dass21'),
        ));
        $this->assertRejected(fn () => DB::table('entitlements')->insert(
            $this->entitlementRow($graph['participant'], $graph['order'], null, 'dass21'),
        ));

        $this->migrate('down');
        $this->migrate('down');
        $this->assertSame($before, $this->baseObjects());
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    #[DataProvider('violations')]
    public function test_historical_violation_aborts_atomically_without_schema_delta(string $type): void
    {
        $graph = $this->directGraph('invalid-'.$type);
        if ($type === 'generic-null') {
            DB::unprepared('DROP TRIGGER entitlements_case_update_guard');
            DB::table('entitlements')->where('id', $graph['entitlement'])->update(['assessment_case_id' => null]);
            $this->restoreEntitlementUpdateGuard();
        } else {
            DB::unprepared('DROP TRIGGER entitlements_case_update_guard');
            DB::table('entitlements')->where('id', $graph['dass'])->update(['assessment_case_id' => $graph['case']]);
            $this->restoreEntitlementUpdateGuard();
        }
        $before = $this->schema();

        try {
            $this->migrate('up');
            $this->fail('Invalid history must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('historical entitlement case requirements', $exception->getMessage());
        }

        $this->assertSame($before, $this->schema());
        $this->assertSame(1, (int) DB::scalar('PRAGMA foreign_keys'));
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    /** @return iterable<string,array{string}> */
    public static function violations(): iterable
    {
        yield 'generic NULL' => ['generic-null'];
        yield 'DASS bound' => ['dass-bound'];
    }

    public function test_idempotent_rerun_rejects_counterfeit_named_constraint_without_delta(): void
    {
        $this->directGraph('counterfeit');
        $this->migrate('up');
        $original = (string) DB::scalar("SELECT sql FROM sqlite_master WHERE type='table' AND name='entitlements'");
        $counterfeit = str_replace(
            "CONSTRAINT entitlements_case_requirement_check CHECK ((test_type = 'dass21' AND assessment_case_id IS NULL) OR (test_type <> 'dass21' AND assessment_case_id IS NOT NULL))",
            'CONSTRAINT entitlements_case_requirement_check CHECK (1)',
            $original,
        );
        DB::statement('PRAGMA writable_schema = ON');
        try {
            DB::table('sqlite_master')->where('type', 'table')->where('name', 'entitlements')
                ->update(['sql' => $counterfeit]);
            $before = $this->schema();
            foreach (['up', 'down'] as $operation) {
                try {
                    $this->migrate($operation);
                    $this->fail('Counterfeit requirement must fail closed.');
                } catch (RuntimeException $exception) {
                    $this->assertStringContainsString('counterfeit or partial requirement', $exception->getMessage());
                }
                $this->assertSame($before, $this->schema());
            }
        } finally {
            DB::table('sqlite_master')->where('type', 'table')->where('name', 'entitlements')
                ->update(['sql' => $original]);
            DB::statement('PRAGMA writable_schema = OFF');
        }
    }

    /** @return array{participant:int,case:int,order:int,entitlement:int,dass:int} */
    private function directGraph(string $suffix): array
    {
        $key = strtoupper(substr(hash('sha256', $suffix), 0, 20));
        $branch = DB::table('branches')->insertGetId([
            'code' => $key, 'name' => $suffix, 'ref_code' => $key,
            'organization_code' => $key, 'display_name' => $suffix,
        ]);
        $package = DB::table('packages')->insertGetId([
            'code' => 'package-'.$suffix, 'name' => $suffix, 'amount' => 99000,
            'currency' => 'IDR', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['dass21', 'ist', 'papi'] as $sort => $type) {
            DB::table('package_items')->insert([
                'package_id' => $package, 'test_type' => $type, 'sort_order' => $sort,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $branch, 'referral_branch_id' => $branch, 'referral_source' => 'manual',
            'package_id' => $package, 'source_system' => 'DIRECT_PUBLIC', 'full_name' => $suffix,
            'intended_field' => 'KAIGO', 'phone' => '620000000000',
        ]);
        $publicId = (string) Str::ulid();
        $case = DB::table('assessment_cases')->insertGetId([
            'public_id' => $publicId, 'participant_id' => $participant, 'organization_id' => $branch,
            'package_id' => $package, 'origin' => 'DIRECT_PUBLIC', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $method = DB::table('payment_methods')->insertGetId([
            'code' => 'method-'.$suffix, 'display_name' => $suffix, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $order = DB::table('orders')->insertGetId([
            'public_id' => $publicId, 'participant_id' => $participant, 'assessment_case_id' => $case,
            'payment_method_id' => $method, 'status' => 'paid', 'amount' => 99000,
            'currency' => 'IDR', 'paid_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $dass = DB::table('entitlements')->insertGetId($this->entitlementRow($participant, $order, null, 'dass21'));
        $entitlement = DB::table('entitlements')->insertGetId(
            $this->entitlementRow($participant, $order, $case, 'ist'),
        );
        DB::table('entitlements')->insert($this->entitlementRow($participant, $order, $case, 'papi'));
        $session = DB::table('test_sessions')->insertGetId([
            'public_id' => (string) Str::ulid(), 'participant_id' => $participant,
            'assessment_case_id' => $case, 'test_type' => 'ist', 'attempt_no' => 1,
            'authorization_id' => (string) Str::ulid(), 'allocation_intent_id' => (string) Str::ulid(),
            'duration_seconds' => 3600, 'status' => 'created', 'answers_revision' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('test_session_grants')->insert([
            'test_session_id' => $session, 'assessment_case_id' => $case,
            'participant_id' => $participant, 'organization_id' => $branch,
            'test_type' => 'ist', 'origin' => 'DIRECT_PUBLIC', 'grant_kind' => 'entitlement',
            'order_id' => $order, 'entitlement_id' => $entitlement, 'created_at' => now(),
        ]);

        return compact('participant', 'case', 'order', 'entitlement', 'dass');
    }

    /** @return array<string,mixed> */
    private function entitlementRow(int $participant, int $order, ?int $case, string $type): array
    {
        return [
            'participant_id' => $participant, 'order_id' => $order, 'assessment_case_id' => $case,
            'test_type' => $type, 'status' => 'ready', 'ready_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ];
    }

    private function restoreEntitlementUpdateGuard(): void
    {
        $insert = (string) DB::scalar("SELECT sql FROM sqlite_master WHERE type='trigger' AND name='entitlements_case_insert_guard'");
        $sql = str_replace(
            ['entitlements_case_insert_guard BEFORE INSERT', 'WHEN (NEW.test_type'],
            ['entitlements_case_update_guard BEFORE UPDATE', 'WHEN (OLD.assessment_case_id IS NOT NULL AND NEW.assessment_case_id IS NOT OLD.assessment_case_id) OR (NEW.test_type'],
            $insert,
        );
        if (DB::connection()->getPdo()->exec($sql) === false) {
            throw new RuntimeException('Unable to restore entitlement update guard.');
        }
    }

    private function assertRejected(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Constraint was not enforced.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    /** @return list<array<string,mixed>> */
    private function baseObjects(): array
    {
        return array_values(array_map(static fn (object $row): array => (array) $row, DB::select(
            "SELECT type,name,tbl_name,sql FROM sqlite_master WHERE name <> 'entitlements_case_requirement_check'
             AND ((tbl_name='entitlements' AND type IN ('index','trigger'))
                OR (type='trigger' AND lower(sql) LIKE '%entitlements%')) ORDER BY type,name",
        )));
    }

    /** @return list<array<string,mixed>> */
    private function schema(): array
    {
        return array_values(array_map(static fn (object $row): array => (array) $row, DB::select(
            "SELECT type,name,tbl_name,sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type,name",
        )));
    }

    private function migration(): Migration
    {
        $migration = require database_path('migrations/2026_09_10_000400_enforce_generic_entitlement_case_identity.php');
        if (! $migration instanceof Migration) {
            throw new RuntimeException('Requirement migration unavailable.');
        }

        return $migration;
    }

    private function migrate(string $operation): void
    {
        if (! in_array($operation, ['up', 'down'], true)) {
            throw new RuntimeException('Unsupported migration operation.');
        }
        $migration = $this->migration();
        (new \ReflectionMethod($migration, $operation))->invoke($migration);
    }
}
