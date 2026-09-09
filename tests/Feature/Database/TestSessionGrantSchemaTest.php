<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionMethod;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;

final class TestSessionGrantSchemaTest extends OrganizationPaymentTestCase
{
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
        $session = $this->unboundSession();
        $base = [
            'test_session_id' => $session,
            'assessment_case_id' => 1,
            'participant_id' => 1,
            'organization_id' => 1,
            'test_type' => 'dass21',
            'origin' => 'DIRECT_PUBLIC',
            'grant_kind' => 'entitlement',
            'order_id' => 1,
            'entitlement_id' => 1,
            'created_at' => now(),
        ];

        $this->assertRejected(fn () => DB::table('test_session_grants')->insert($base));
        $this->assertRejected(fn () => DB::table('test_session_grants')->insert([
            ...$base,
            'test_type' => 'ist',
            'grant_kind' => 'assessment_entitlement',
            'assessment_entitlement_id' => 1,
        ]));
    }

    public function test_empty_down_up_is_safe_but_populated_down_refuses_without_delta(): void
    {
        $this->migrate('down');
        $this->migrate('up');

        $graph = $this->directGraph('populated');
        $session = $this->createBoundSession($graph['participant'], $graph['case']);
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

    /** @return array{branch:int,participant:int,case:int,order:int,entitlement:int} */
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
                'participant_id' => $participant, 'order_id' => $order, 'test_type' => $type,
                'status' => 'ready', 'ready_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($type === 'ist') {
                $entitlement = $id;
            }
        }

        return compact('branch', 'participant', 'case', 'order', 'entitlement');
    }

    private function createBoundSession(int $participant, int $case): int
    {
        return DB::table('test_sessions')->insertGetId([
            'public_id' => (string) Str::ulid(), 'participant_id' => $participant,
            'assessment_case_id' => $case, 'test_type' => 'ist', 'attempt_no' => 1,
            'authorization_id' => (string) Str::ulid(), 'allocation_intent_id' => (string) Str::ulid(),
            'duration_seconds' => 3600, 'status' => 'created', 'answers_revision' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function migrate(string $direction): void
    {
        $migration = require database_path('migrations/2026_09_09_000700_create_test_session_grants.php');
        if (! is_object($migration) || ! in_array($direction, ['up', 'down'], true) || ! method_exists($migration, $direction)) {
            throw new RuntimeException("Migration operation {$direction} is unavailable.");
        }

        (new ReflectionMethod($migration, $direction))->invoke($migration);
    }

    private function assertRejected(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected database rejection.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
