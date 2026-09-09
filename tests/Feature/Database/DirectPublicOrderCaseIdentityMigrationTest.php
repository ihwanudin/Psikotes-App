<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;

final class DirectPublicOrderCaseIdentityMigrationTest extends OrganizationPaymentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->migrate('down');
    }

    public function test_exact_historical_main_order_gets_an_aliased_case_without_inventing_field(): void
    {
        $fixture = $this->directOrder('history', ['dass21', 'ist']);

        $this->migrate('up');

        $order = DB::table('orders')->where('id', $fixture['order'])->first();
        $case = DB::table('assessment_cases')->where('id', $order->assessment_case_id)->first();
        $this->assertSame($fixture['public_id'], $case->public_id);
        $this->assertSame($fixture['participant'], $case->participant_id);
        $this->assertSame($fixture['branch'], $case->organization_id);
        $this->assertSame($fixture['package'], $case->package_id);
        $this->assertSame('DIRECT_PUBLIC', $case->origin);
        $this->assertNull($case->intended_field_snapshot);
        $this->assertSame('2026-08-25 03:15:00', $case->created_at);
        $this->assertContains('orders_assessment_case_unique', collect(DB::select(
            "PRAGMA index_list('orders')",
        ))->pluck('name')->all());
        $foreign = collect(DB::select(<<<'SQL'
            SELECT "from" AS source_column, "to" AS target_column
            FROM pragma_foreign_key_list('orders')
            WHERE "table" = 'assessment_cases'
            ORDER BY id, seq
            SQL));
        $this->assertSame(
            ['assessment_case_id', 'public_id', 'participant_id'],
            $foreign->pluck('source_column')->all(),
        );
        $this->assertSame(['id', 'public_id', 'participant_id'], $foreign->pluck('target_column')->all());
    }

    public function test_historical_dass_only_order_remains_unbound(): void
    {
        $fixture = $this->directOrder('dass', ['dass21']);

        $this->migrate('up');

        $this->assertNull(DB::table('orders')->where('id', $fixture['order'])->value('assessment_case_id'));
        $this->assertDatabaseCount('assessment_cases', 0);
    }

    public function test_mismatched_historical_entitlements_abort_the_whole_migration(): void
    {
        $fixture = $this->directOrder('mismatch', ['dass21', 'ist']);
        DB::table('entitlements')->where('order_id', $fixture['order'])->where('test_type', 'ist')->delete();

        try {
            $this->migrate('up');
            $this->fail('Mismatched historical composition must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('package items and entitlements differ', $exception->getMessage());
        }

        $this->assertFalse(Schema::hasColumn('orders', 'assessment_case_id'));
        $this->assertDatabaseCount('assessment_cases', 0);
    }

    public function test_two_orders_for_one_direct_participant_abort_before_any_schema_or_case_write(): void
    {
        $fixture = $this->directOrder('duplicate-order', ['dass21', 'ist']);
        DB::table('orders')->insert([
            'public_id' => (string) Str::ulid(), 'participant_id' => $fixture['participant'],
            'payment_method_id' => DB::table('orders')->where('id', $fixture['order'])->value('payment_method_id'),
            'status' => 'pending', 'amount' => 100000, 'currency' => 'IDR',
            'created_at' => '2026-08-25 03:15:00', 'updated_at' => '2026-08-25 03:15:00',
        ]);
        $before = $this->sqliteSchema();

        try {
            $this->migrate('up');
            $this->fail('Multiple direct orders must fail before backfill.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('each direct participant must have exactly one order', $exception->getMessage());
        }

        $this->assertSame($before, $this->sqliteSchema());
        $this->assertFalse(Schema::hasColumn('orders', 'assessment_case_id'));
        $this->assertDatabaseCount('assessment_cases', 0);
        $this->assertDatabaseCount('orders', 2);
    }

    public function test_linked_order_identity_is_immutable_while_payment_lifecycle_remains_mutable(): void
    {
        $fixture = $this->directOrder('guard', ['dass21', 'ist']);
        $this->migrate('up');
        $order = DB::table('orders')->where('id', $fixture['order'])->first();

        $this->assertSame(1, DB::table('orders')->where('id', $fixture['order'])
            ->update(['status' => 'paid', 'paid_at' => now(), 'updated_at' => now()]));
        foreach ([
            'public_id' => (string) Str::ulid(),
            'participant_id' => $this->participant('other', $fixture['package'], 'DIRECT_PUBLIC')['participant'],
            'assessment_case_id' => null,
            'created_at' => '2026-08-26 03:15:00',
        ] as $column => $value) {
            $this->assertRejected(fn () => DB::table('orders')->where('id', $fixture['order'])
                ->update([$column => $value]));
        }
        $this->assertRejected(fn () => DB::table('orders')->where('id', $fixture['order'])->delete());
        $this->assertSame($order->assessment_case_id, DB::table('orders')->where('id', $fixture['order'])
            ->value('assessment_case_id'));
    }

    public function test_populated_binding_refuses_rollback_without_changing_history(): void
    {
        $fixture = $this->directOrder('rollback', ['dass21', 'ist']);
        $this->migrate('up');
        $case = DB::table('orders')->where('id', $fixture['order'])->value('assessment_case_id');

        try {
            $this->migrate('down');
            $this->fail('Populated rollback must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Direct public order case history prevents rollback.', $exception->getMessage());
        }

        $this->assertSame($case, DB::table('orders')->where('id', $fixture['order'])
            ->value('assessment_case_id'));
    }

    /** @param list<string> $testTypes
     * @return array{branch:int,participant:int,package:int,order:int,public_id:string}
     */
    private function directOrder(string $suffix, array $testTypes): array
    {
        $package = $this->package($suffix, $testTypes);
        $graph = $this->participant($suffix, $package, 'DIRECT_PUBLIC');
        $publicId = (string) Str::ulid();
        $paymentMethod = DB::table('payment_methods')->insertGetId([
            'code' => 'method-'.$suffix, 'display_name' => $suffix, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $order = DB::table('orders')->insertGetId([
            'public_id' => $publicId, 'participant_id' => $graph['participant'],
            'payment_method_id' => $paymentMethod, 'status' => 'pending', 'amount' => 100000,
            'currency' => 'IDR', 'created_at' => '2026-08-25 03:15:00',
            'updated_at' => '2026-08-25 03:15:00',
        ]);
        foreach ($testTypes as $testType) {
            DB::table('entitlements')->insert([
                'participant_id' => $graph['participant'], 'order_id' => $order,
                'test_type' => $testType, 'status' => 'locked',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return [...$graph, 'package' => $package, 'order' => $order, 'public_id' => $publicId];
    }

    /** @param list<string> $testTypes */
    private function package(string $suffix, array $testTypes): int
    {
        $package = DB::table('packages')->insertGetId([
            'code' => 'package-'.$suffix, 'name' => $suffix, 'amount' => 100000,
            'currency' => 'IDR', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($testTypes as $sort => $testType) {
            DB::table('package_items')->insert([
                'package_id' => $package, 'test_type' => $testType, 'sort_order' => $sort,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $package;
    }

    /** @return array{branch:int,participant:int} */
    private function participant(string $suffix, int $package, string $source): array
    {
        $key = (string) Str::ulid();
        $branch = DB::table('branches')->insertGetId([
            'code' => $key, 'name' => $suffix, 'ref_code' => $key,
            'organization_code' => $key, 'display_name' => $suffix,
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $branch, 'referral_branch_id' => $branch, 'referral_source' => 'manual',
            'package_id' => $package, 'source_system' => $source, 'full_name' => $suffix,
            'intended_field' => 'KAIGO', 'phone' => '620000000000',
        ]);

        return compact('branch', 'participant');
    }

    private function assertRejected(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Database invariant was not enforced.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    /** @return list<array<string, mixed>> */
    private function sqliteSchema(): array
    {
        return array_values(array_map(
            static fn (object $row): array => (array) $row,
            DB::select(<<<'SQL'
                SELECT type, name, tbl_name, sql FROM sqlite_master
                WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name
                SQL),
        ));
    }

    private function migrate(string $direction): void
    {
        $migration = require database_path('migrations/2026_09_09_000600_bind_direct_public_orders_to_assessment_cases.php');
        $operation = [$migration, $direction];
        if (! is_callable($operation)) {
            throw new RuntimeException("Migration operation {$direction} is unavailable.");
        }
        $operation();
    }
}
