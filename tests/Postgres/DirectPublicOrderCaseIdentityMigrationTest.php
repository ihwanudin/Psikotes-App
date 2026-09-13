<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Actions\Registration\RegisterParticipant;
use App\Security\RlsContextRunner;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\ForkedProcessResult;
use Throwable;

final class DirectPublicOrderCaseIdentityMigrationTest extends TestCase
{
    public function test_owner_backfills_exact_history_and_refuses_populated_rollback(): void
    {
        $this->asOwner(function (): void {
            DB::beginTransaction();
            try {
                $this->migrate('down');
                foreach (['branches', 'packages', 'package_items', 'participants', 'orders', 'entitlements', 'assessment_cases'] as $table) {
                    DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
                }
                $graph = $this->baseGraph('history', ['dass21', 'ist']);
                $publicId = (string) Str::ulid();
                $createdAt = '2026-09-01 03:15:00+00';
                $order = DB::table('orders')->insertGetId([
                    'public_id' => $publicId, 'participant_id' => $graph['participant'],
                    'payment_method_id' => $graph['method'], 'status' => 'pending',
                    'amount' => 99000, 'currency' => 'IDR', 'created_at' => $createdAt, 'updated_at' => $createdAt,
                ]);
                foreach (['dass21', 'ist'] as $type) {
                    DB::table('entitlements')->insert([
                        'participant_id' => $graph['participant'], 'order_id' => $order,
                        'test_type' => $type, 'status' => 'locked', 'created_at' => $createdAt, 'updated_at' => $createdAt,
                    ]);
                }

                $this->migrate('up');

                $bound = DB::table('orders')->where('id', $order)->first();
                $case = DB::table('assessment_cases')->where('id', $bound->assessment_case_id)->first();
                $this->assertSame($publicId, $case->public_id);
                $this->assertSame($graph['participant'], $case->participant_id);
                $this->assertSame($graph['branch'], $case->organization_id);
                $this->assertSame($graph['package'], $case->package_id);
                $this->assertSame('DIRECT_PUBLIC', $case->origin);
                $this->assertNull($case->intended_field_snapshot);
                $this->assertSame(strtotime($createdAt), strtotime((string) $case->created_at));
                foreach (['orders', 'assessment_cases'] as $table) {
                    $security = DB::selectOne('SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid=?::regclass', [$table]);
                    $this->assertTrue($security->relrowsecurity, $table);
                    $this->assertTrue($security->relforcerowsecurity, $table);
                }
                $entitlements = DB::table('entitlements')->where('order_id', $order)
                    ->pluck('assessment_case_id', 'test_type');
                $this->assertNull($entitlements['dass21']);
                $this->assertSame($case->id, $entitlements['ist']);
                DB::table('entitlements')->where('order_id', $order)->delete();

                try {
                    $this->migrate('down');
                    $this->fail('Populated direct binding must refuse rollback.');
                } catch (RuntimeException $exception) {
                    $this->assertSame('Direct public order case history prevents rollback.', $exception->getMessage());
                }
                $this->assertTrue(Schema::hasColumn('orders', 'assessment_case_id'));
            } finally {
                DB::rollBack();
            }
        });
    }

    public function test_runtime_service_guard_allows_payment_lifecycle_but_freezes_linked_identity(): void
    {
        DB::beginTransaction();
        try {
            app(RlsContextRunner::class)->runAsService(function (): void {
                $graph = $this->baseGraph('runtime', ['dass21', 'ist']);
                $publicId = (string) Str::ulid();
                $now = now();
                $case = DB::table('assessment_cases')->insertGetId([
                    'public_id' => $publicId, 'participant_id' => $graph['participant'],
                    'organization_id' => $graph['branch'], 'package_id' => $graph['package'],
                    'origin' => 'DIRECT_PUBLIC', 'intended_field_snapshot' => 'KAIGO',
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $order = DB::table('orders')->insertGetId([
                    'public_id' => $publicId, 'participant_id' => $graph['participant'],
                    'assessment_case_id' => $case, 'payment_method_id' => $graph['method'],
                    'status' => 'pending', 'amount' => 99000, 'currency' => 'IDR',
                    'created_at' => $now, 'updated_at' => $now,
                ]);

                $this->assertSame(1, DB::table('orders')->where('id', $order)->update(['status' => 'paid', 'paid_at' => now()]));
                foreach (['public_id' => (string) Str::ulid(), 'participant_id' => PHP_INT_MAX,
                    'assessment_case_id' => null, 'created_at' => now()->subDay()] as $column => $value) {
                    $this->assertSqlState('P0001', fn () => DB::table('orders')->where('id', $order)->update([$column => $value]));
                }
                $this->assertSqlState('P0001', fn () => DB::table('orders')->where('id', $order)->delete());
            });
        } finally {
            DB::rollBack();
        }
    }

    public function test_two_historical_orders_for_one_direct_participant_abort_without_schema_or_rls_delta(): void
    {
        $this->asOwner(function (): void {
            DB::beginTransaction();
            try {
                $this->migrate('down');
                foreach (['branches', 'packages', 'package_items', 'participants', 'orders', 'entitlements', 'assessment_cases'] as $table) {
                    DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
                }
                $graph = $this->baseGraph('duplicate-order', ['dass21', 'ist']);
                $first = $this->historicalOrder($graph, 'first');
                $this->historicalOrder($graph, 'second');
                foreach (['dass21', 'ist'] as $type) {
                    DB::table('entitlements')->insert([
                        'participant_id' => $graph['participant'], 'order_id' => $first,
                        'test_type' => $type, 'status' => 'locked', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                foreach (['branches', 'packages', 'package_items', 'participants', 'orders', 'entitlements', 'assessment_cases'] as $table) {
                    DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
                }
                $before = $this->postgresSchema();

                try {
                    $this->migrate('up');
                    $this->fail('Multiple direct orders must fail before backfill.');
                } catch (RuntimeException $exception) {
                    $this->assertStringContainsString('each direct participant must have exactly one order', $exception->getMessage());
                }

                $this->assertEquals($before, $this->postgresSchema());
                $this->assertFalse(Schema::hasColumn('orders', 'assessment_case_id'));
                foreach (['orders', 'assessment_cases'] as $table) {
                    $this->assertTrue((bool) DB::scalar(
                        'SELECT relforcerowsecurity FROM pg_class WHERE oid=?::regclass', [$table],
                    ));
                }
                DB::statement('ALTER TABLE assessment_cases NO FORCE ROW LEVEL SECURITY');
                $this->assertSame(0, DB::table('assessment_cases')->where('origin', 'DIRECT_PUBLIC')->count());
            } finally {
                DB::rollBack();
            }
        });
    }

    public function test_extra_main_entitlement_without_order_aborts_without_schema_or_rls_delta(): void
    {
        $this->malformedEntitlementMigrationProbe(
            'extra-null-order',
            ['dass21', 'ist'],
            static function (array $graph): void {
                DB::table('entitlements')->insert([
                    'participant_id' => $graph['participant'], 'order_id' => null,
                    'test_type' => 'rmib', 'status' => 'locked',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            },
        );
    }

    public function test_extra_dass_entitlement_linked_to_another_order_aborts_without_schema_or_rls_delta(): void
    {
        $this->malformedEntitlementMigrationProbe(
            'extra-other-order',
            ['dass21'],
            function (array $graph): void {
                $otherParticipant = DB::table('participants')->insertGetId([
                    'branch_id' => $graph['branch'], 'referral_branch_id' => $graph['branch'],
                    'referral_source' => 'manual', 'source_system' => 'LEGACY_SELECTION',
                    'package_id' => $graph['package'], 'full_name' => 'other order owner',
                    'intended_field' => 'KAIGO', 'phone' => '629999999999',
                ]);
                $otherOrder = $this->historicalOrder([
                    'participant' => $otherParticipant,
                    'method' => $graph['method'],
                ], 'other-owner');
                DB::table('entitlements')->insert([
                    'participant_id' => $graph['participant'], 'order_id' => $otherOrder,
                    'test_type' => 'ist', 'status' => 'locked',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            },
        );
    }

    public function test_two_real_processes_observing_the_same_empty_token_converge_on_one_exact_graph(): void
    {
        $fixture = app(RlsContextRunner::class)->runAsService(function (): array {
            $key = (string) Str::ulid();
            $branch = DB::table('branches')->insertGetId([
                'code' => $key, 'name' => 'race', 'ref_code' => $key,
                'organization_code' => $key, 'display_name' => 'race',
                'is_default' => true, 'is_active' => true,
            ]);
            $package = DB::table('packages')->insertGetId([
                'code' => 'PKG-'.$key, 'name' => 'race', 'amount' => 99000,
                'currency' => 'IDR', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (['dass21', 'ist'] as $position => $type) {
                DB::table('package_items')->insert([
                    'package_id' => $package, 'test_type' => $type, 'sort_order' => $position + 1,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $method = DB::table('payment_methods')->insertGetId([
                'code' => 'method-'.$key, 'display_name' => 'race', 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return ['branch' => $branch, 'package' => $package, 'method_code' => 'method-'.$key];
        });
        $token = (string) Str::uuid();
        $input = [
            'full_name' => 'Concurrent Participant', 'gender' => 'female',
            'birth_date' => '2001-04-15', 'education_level' => 'SMA/SMK',
            'intended_field' => 'KAIGO', 'phone' => '+6281234567890',
            'email' => 'concurrent@example.test', 'include_consultation' => false,
        ];
        $operation = static function () use ($input, $fixture, $token): array {
            $participant = app(RegisterParticipant::class)->handle(
                $input, $fixture['package'], $fixture['method_code'], $token, null,
            );

            return ['participant' => $participant->id];
        };

        try {
            $results = $this->race($operation, $operation);
            $this->assertArrayNotHasKey('error', $results[0]);
            $this->assertArrayNotHasKey('error', $results[1]);
            $this->assertSame($results[0]['participant'], $results[1]['participant']);
            app(RlsContextRunner::class)->runAsService(function () use ($token): void {
                $participant = DB::table('participants')->where('registration_token', $token)->sole();
                $order = DB::table('orders')->where('participant_id', $participant->id)->sole();
                $case = DB::table('assessment_cases')->where('participant_id', $participant->id)->sole();
                $this->assertSame($order->public_id, $case->public_id);
                $this->assertSame($order->assessment_case_id, $case->id);
                $entitlements = DB::table('entitlements')->where('order_id', $order->id)
                    ->orderBy('test_type')->get();
                $this->assertSame(['dass21', 'ist'], $entitlements->pluck('test_type')->all());
                $this->assertNull($entitlements->firstWhere('test_type', 'dass21')->assessment_case_id);
                $this->assertSame($case->id, $entitlements->firstWhere('test_type', 'ist')->assessment_case_id);
                $this->assertSame(2, DB::table('consent_records')->where('participant_id', $participant->id)->count());
            });
        } finally {
            $this->cleanupConcurrentGraph($token, $fixture);
        }
    }

    public function test_migration_waiting_on_participants_does_not_deadlock_a_dass_replay(): void
    {
        $this->asOwner(fn () => $this->migrate('down'));
        $token = (string) Str::uuid();
        $input = [
            'full_name' => 'Migration Replay', 'gender' => 'female',
            'birth_date' => '2001-04-15', 'education_level' => 'SMA/SMK',
            'intended_field' => 'KAIGO', 'phone' => '+6281234567800',
            'email' => 'migration-replay@example.test', 'include_consultation' => false,
        ];
        $fixture = app(RlsContextRunner::class)->runAsService(function () use ($input, $token): array {
            $graph = $this->baseGraph('migration-replay', ['dass21']);
            $hashInput = [...$input, 'package_id' => $graph['package'], 'payment_method_code' => $graph['method_code']];
            ksort($hashInput);
            $payloadHash = hash_hmac(
                'sha256', json_encode($hashInput, JSON_THROW_ON_ERROR), (string) config('app.key'),
            );
            DB::table('participants')->where('id', $graph['participant'])->update([
                'registration_token' => $token, 'registration_payload_hash' => $payloadHash,
            ]);
            $order = $this->historicalOrder($graph, 'migration-replay');
            DB::table('entitlements')->insert([
                'participant_id' => $graph['participant'], 'order_id' => $order,
                'test_type' => 'dass21', 'status' => 'locked', 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $graph;
        });
        $replay = static function () use ($input, $fixture, $token): array {
            $participant = app(RegisterParticipant::class)->handle(
                $input, $fixture['package'], $fixture['method_code'], $token, null,
            );

            return ['participant' => $participant->id];
        };

        try {
            $results = $this->migrationReplayRace($replay);
            $this->assertArrayNotHasKey('error', $results['replay']);
            $this->assertArrayNotHasKey('error', $results['migration']);
            $this->assertSame($fixture['participant'], $results['replay']['participant']);
            $this->assertTrue($results['migration']['migrated']);
            $this->assertTrue(Schema::hasColumn('orders', 'assessment_case_id'));
        } finally {
            $this->cleanupConcurrentGraph($token, $fixture);
            if (! Schema::hasColumn('orders', 'assessment_case_id')
                || ! Schema::hasColumn('entitlements', 'assessment_case_id')) {
                $this->asOwner(fn () => $this->migrate('up'));
            }
        }
    }

    /** @param list<string> $types
     * @return array{branch:int,package:int,participant:int,method:int,method_code:string}
     */
    private function baseGraph(string $suffix, array $types): array
    {
        $key = (string) Str::ulid();
        $branch = DB::table('branches')->insertGetId([
            'code' => $key, 'name' => $suffix, 'ref_code' => $key,
            'organization_code' => $key, 'display_name' => $suffix,
        ]);
        $package = DB::table('packages')->insertGetId([
            'code' => 'PKG-'.$key, 'name' => $suffix, 'amount' => 99000,
            'currency' => 'IDR', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($types as $position => $type) {
            DB::table('package_items')->insert([
                'package_id' => $package, 'test_type' => $type, 'sort_order' => $position + 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $branch, 'referral_branch_id' => $branch, 'referral_source' => 'manual',
            'source_system' => 'DIRECT_PUBLIC', 'package_id' => $package, 'full_name' => $suffix,
            'intended_field' => 'KAIGO', 'phone' => '620000000000',
        ]);
        $methodCode = 'method-'.$key;
        $method = DB::table('payment_methods')->insertGetId([
            'code' => $methodCode, 'display_name' => $suffix, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return compact('branch', 'package', 'participant', 'method') + ['method_code' => $methodCode];
    }

    /** @param array{participant:int,method:int} $graph */
    private function historicalOrder(array $graph, string $suffix): int
    {
        return DB::table('orders')->insertGetId([
            'public_id' => (string) Str::ulid(), 'participant_id' => $graph['participant'],
            'payment_method_id' => $graph['method'], 'status' => 'pending',
            'amount' => 99000, 'currency' => 'IDR',
            'metadata' => json_encode(['fixture' => $suffix], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @param  list<string>  $types
     * @param  callable(array{branch:int,package:int,participant:int,method:int,method_code:string}):void  $addMalformedEntitlement
     */
    private function malformedEntitlementMigrationProbe(
        string $suffix,
        array $types,
        callable $addMalformedEntitlement,
    ): void {
        $this->asOwner(function () use ($suffix, $types, $addMalformedEntitlement): void {
            DB::beginTransaction();
            try {
                $this->migrate('down');
                $tables = ['packages', 'package_items', 'participants', 'orders', 'entitlements', 'assessment_cases'];
                foreach ($tables as $table) {
                    DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
                }
                $graph = $this->baseGraph($suffix, $types);
                $order = $this->historicalOrder($graph, $suffix);
                foreach ($types as $type) {
                    DB::table('entitlements')->insert([
                        'participant_id' => $graph['participant'], 'order_id' => $order,
                        'test_type' => $type, 'status' => 'locked',
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                $addMalformedEntitlement($graph);
                foreach ($tables as $table) {
                    DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
                }
                $before = $this->postgresSchema();

                try {
                    $this->migrate('up');
                    $this->fail('Every participant entitlement must belong to the sole direct order.');
                } catch (RuntimeException $exception) {
                    $this->assertStringContainsString('participant entitlement order differs', $exception->getMessage());
                }

                $this->assertEquals($before, $this->postgresSchema());
                $this->assertFalse(Schema::hasColumn('orders', 'assessment_case_id'));
                foreach ($tables as $table) {
                    $this->assertTrue((bool) DB::scalar(
                        'SELECT relforcerowsecurity FROM pg_class WHERE oid=?::regclass', [$table],
                    ));
                }
                DB::statement('ALTER TABLE assessment_cases NO FORCE ROW LEVEL SECURITY');
                $this->assertSame(0, DB::table('assessment_cases')->where('origin', 'DIRECT_PUBLIC')->count());
            } finally {
                DB::rollBack();
            }
        });
    }

    /** @return array<string, mixed> */
    private function postgresSchema(): array
    {
        return [
            'columns' => DB::select(<<<'SQL'
                SELECT attrelid::regclass::text AS table_name, attname, atttypid, atttypmod, attnotnull
                FROM pg_attribute
                WHERE attrelid IN ('orders'::regclass, 'assessment_cases'::regclass)
                  AND attnum > 0 AND NOT attisdropped ORDER BY table_name, attnum
                SQL),
            'constraints' => DB::select(<<<'SQL'
                SELECT conrelid::regclass::text AS table_name, conname, pg_get_constraintdef(oid) AS definition
                FROM pg_constraint WHERE conrelid IN ('orders'::regclass, 'assessment_cases'::regclass)
                ORDER BY table_name, conname
                SQL),
            'indexes' => DB::select(<<<'SQL'
                SELECT tablename, indexname, indexdef FROM pg_indexes
                WHERE schemaname='public' AND tablename IN ('orders','assessment_cases')
                ORDER BY tablename, indexname
                SQL),
            'triggers' => DB::select(<<<'SQL'
                SELECT tgrelid::regclass::text AS table_name, tgname, pg_get_triggerdef(oid) AS definition
                FROM pg_trigger WHERE tgrelid IN ('orders'::regclass, 'assessment_cases'::regclass)
                  AND NOT tgisinternal ORDER BY table_name, tgname
                SQL),
            'security' => DB::select(<<<'SQL'
                SELECT oid::regclass::text AS table_name, relrowsecurity, relforcerowsecurity
                FROM pg_class WHERE oid IN ('orders'::regclass, 'assessment_cases'::regclass)
                ORDER BY table_name
                SQL),
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

    private function migrate(string $direction): void
    {
        if ($direction === 'down' && Schema::hasTable('test_session_grants')) {
            (require database_path('migrations/2026_09_09_000700_create_test_session_grants.php'))->down();
        }
        $genericRequirement = require database_path('migrations/2026_09_10_000400_enforce_generic_entitlement_case_identity.php');
        $genericIdentity = require database_path('migrations/2026_09_10_000300_expand_generic_entitlement_case_identity.php');
        if ($direction === 'down' && Schema::hasColumn('entitlements', 'assessment_case_id')) {
            $genericRequirement->down();
            $genericIdentity->down();
        }
        $migration = require database_path('migrations/2026_09_09_000600_bind_direct_public_orders_to_assessment_cases.php');
        $operation = [$migration, $direction];
        if (! is_callable($operation)) {
            throw new RuntimeException("Migration operation {$direction} is unavailable.");
        }
        if ($direction === 'down' || ! Schema::hasColumn('orders', 'assessment_case_id')) {
            $operation();
        }
        if ($direction === 'up' && ! Schema::hasColumn('entitlements', 'assessment_case_id')) {
            $genericIdentity->up();
            $genericRequirement->up();
        }
        if ($direction === 'up' && ! Schema::hasTable('test_session_grants')) {
            (require database_path('migrations/2026_09_09_000700_create_test_session_grants.php'))->up();
        }
    }

    private function asOwner(callable $operation): void
    {
        $runtime = DB::getDefaultConnection();
        $config = config('database.connections.'.$runtime);
        config()->set('database.connections.direct_case_owner', [...$config, 'username' => 'org_test_owner']);
        DB::setDefaultConnection('direct_case_owner');
        Schema::clearResolvedInstance('db.schema');
        try {
            $operation();
        } finally {
            DB::setDefaultConnection($runtime);
            Schema::clearResolvedInstance('db.schema');
            DB::purge('direct_case_owner');
            config()->set('database.connections.direct_case_owner', null);
        }
    }

    /** @param array{branch:int,package:int,method_code:string} $fixture */
    private function cleanupConcurrentGraph(string $token, array $fixture): void
    {
        $this->asOwner(function () use ($token, $fixture): void {
            DB::transaction(function () use ($token, $fixture): void {
                foreach (['branches', 'packages', 'package_items', 'participants', 'orders', 'entitlements',
                    'consent_records', 'assessment_cases', 'payment_methods'] as $table) {
                    DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
                }
                DB::statement('ALTER TABLE orders DISABLE TRIGGER USER');
                DB::statement('ALTER TABLE assessment_cases DISABLE TRIGGER USER');
                $participant = DB::table('participants')->where('registration_token', $token)->value('id');
                if (is_numeric($participant)) {
                    DB::table('consent_records')->where('participant_id', $participant)->delete();
                    DB::table('entitlements')->where('participant_id', $participant)->delete();
                    DB::table('orders')->where('participant_id', $participant)->delete();
                    DB::table('assessment_cases')->where('participant_id', $participant)->delete();
                    DB::table('participants')->where('id', $participant)->delete();
                }
                DB::table('package_items')->where('package_id', $fixture['package'])->delete();
                DB::table('packages')->where('id', $fixture['package'])->delete();
                DB::table('payment_methods')->where('code', $fixture['method_code'])->delete();
                DB::table('branches')->where('id', $fixture['branch'])->delete();
                DB::statement('ALTER TABLE assessment_cases ENABLE TRIGGER USER');
                DB::statement('ALTER TABLE orders ENABLE TRIGGER USER');
                foreach (['branches', 'packages', 'package_items', 'participants', 'orders', 'entitlements',
                    'consent_records', 'assessment_cases', 'payment_methods'] as $table) {
                    DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
                }
            });
        });
    }

    /** @return list<array<string, mixed>> */
    private function race(callable $first, callable $second): array
    {
        $this->assertTrue(function_exists('pcntl_fork'), 'Registration concurrency requires pcntl; never skip.');
        DB::purge('pgsql');
        $gate = random_int(1, 2_000_000_000);
        $gateHeld = false;
        $workers = [];
        try {
            foreach ([$first, $second] as $callback) {
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
                if ($pair === false || ($pid = pcntl_fork()) === -1) {
                    throw new RuntimeException('Unable to create registration concurrency worker.');
                }
                if ($pid === 0) {
                    fclose($pair[0]);
                    foreach ($workers as $worker) {
                        fclose($worker['socket']);
                    }
                    DB::purge('pgsql');
                    stream_set_timeout($pair[1], 15);
                    try {
                        $identity = DB::selectOne('SELECT pg_backend_pid() AS pid, current_user AS name');
                        if ($identity->name !== 'psikotes_runtime') {
                            throw new RuntimeException('Worker must use runtime role.');
                        }
                        $armed = true;
                        DB::listen(function (QueryExecuted $query) use (&$armed, $gate): void {
                            if (! $armed || ! str_starts_with($query->sql, 'select')
                                || ! str_contains($query->sql, 'participants')
                                || ! str_contains($query->sql, 'registration_token')
                                || ! str_contains($query->sql, 'limit 2')) {
                                return;
                            }
                            $armed = false;
                            DB::select('SELECT pg_advisory_lock_shared(?)', [$gate]);
                            DB::select('SELECT pg_advisory_unlock_shared(?)', [$gate]);
                        });
                        fwrite($pair[1], json_encode(['pid' => $identity->pid], JSON_THROW_ON_ERROR)."\n");
                        if (fgets($pair[1]) !== "go\n") {
                            throw new RuntimeException('Registration barrier timed out.');
                        }
                        $result = $callback();
                    } catch (Throwable $exception) {
                        $result = ['class' => $exception::class, 'error' => $exception->getMessage()];
                    }
                    ForkedProcessResult::sendAndExit($pair[1], $result,
                        static function (): void {
                            DB::disconnect('pgsql');
                        });
                }
                fclose($pair[1]);
                stream_set_timeout($pair[0], 15);
                $workers[] = ['pid' => $pid, 'socket' => $pair[0]];
            }
            $backendIds = [];
            foreach ($workers as $worker) {
                $backendIds[] = json_decode((string) fgets($worker['socket']), true, flags: JSON_THROW_ON_ERROR)['pid'];
            }
            DB::select('SELECT pg_advisory_lock(?)', [$gate]);
            $gateHeld = true;
            foreach ($workers as $worker) {
                fwrite($worker['socket'], "go\n");
            }
            foreach ($backendIds as $backendId) {
                $deadline = microtime(true) + 5;
                do {
                    $waiting = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [$backendId]);
                    if ($waiting?->wait_event_type === 'Lock') {
                        break;
                    }
                    usleep(10000);
                } while (microtime(true) < $deadline);
                $this->assertSame('Lock', $waiting?->wait_event_type, 'Both workers must finish the empty read before release.');
            }
            DB::select('SELECT pg_advisory_unlock(?)', [$gate]);
            $gateHeld = false;

            $results = [];
            foreach ($workers as $worker) {
                $results[] = json_decode((string) fgets($worker['socket']), true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            if ($gateHeld) {
                DB::select('SELECT pg_advisory_unlock(?)', [$gate]);
            }
            foreach ($workers as $worker) {
                fclose($worker['socket']);
                pcntl_waitpid($worker['pid'], $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }
        }
    }

    /** @return array{replay:array<string,mixed>,migration:array<string,mixed>} */
    private function migrationReplayRace(callable $replay): array
    {
        $this->assertTrue(function_exists('pcntl_fork'), 'Migration/replay concurrency requires pcntl; never skip.');
        DB::purge('pgsql');
        $gate = random_int(1, 2_000_000_000);
        $gateHeld = false;
        $workers = [];
        $runtimeConfig = config('database.connections.'.DB::getDefaultConnection());
        config()->set('database.connections.direct_case_race_monitor', [
            ...$runtimeConfig,
            'username' => 'org_test_owner',
        ]);
        try {
            $replayPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            if ($replayPair === false || ($replayPid = pcntl_fork()) === -1) {
                throw new RuntimeException('Unable to create replay worker.');
            }
            if ($replayPid === 0) {
                fclose($replayPair[0]);
                DB::purge('pgsql');
                stream_set_timeout($replayPair[1], 15);
                try {
                    $identity = DB::selectOne('SELECT pg_backend_pid() AS pid, current_user AS name');
                    if ($identity->name !== 'psikotes_runtime') {
                        throw new RuntimeException('Replay worker must use runtime role.');
                    }
                    $armed = true;
                    DB::listen(function (QueryExecuted $query) use (&$armed, $gate): void {
                        if (! $armed || ! str_starts_with($query->sql, 'select')
                            || ! str_contains($query->sql, 'participants')
                            || ! str_contains($query->sql, 'registration_token')
                            || ! str_contains($query->sql, 'limit 2')) {
                            return;
                        }
                        $armed = false;
                        DB::select('SELECT pg_advisory_lock_shared(?)', [$gate]);
                        DB::select('SELECT pg_advisory_unlock_shared(?)', [$gate]);
                    });
                    fwrite($replayPair[1], json_encode(['pid' => $identity->pid], JSON_THROW_ON_ERROR)."\n");
                    if (fgets($replayPair[1]) !== "go\n") {
                        throw new RuntimeException('Replay barrier timed out.');
                    }
                    $result = $replay();
                } catch (Throwable $exception) {
                    $result = ['class' => $exception::class, 'error' => $exception->getMessage()];
                }
                ForkedProcessResult::sendAndExit($replayPair[1], $result,
                    static function (): void {
                        DB::disconnect('pgsql');
                    });
            }
            fclose($replayPair[1]);
            stream_set_timeout($replayPair[0], 20);
            $workers[] = ['pid' => $replayPid, 'socket' => $replayPair[0]];
            $replayBackend = json_decode((string) fgets($replayPair[0]), true, flags: JSON_THROW_ON_ERROR)['pid'];

            DB::select('SELECT pg_advisory_lock(?)', [$gate]);
            $gateHeld = true;
            fwrite($replayPair[0], "go\n");
            $this->waitForBackendLock($replayBackend, 'Replay must finish its participant read before migration starts.');

            $migrationPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            if ($migrationPair === false || ($migrationPid = pcntl_fork()) < 0) {
                throw new RuntimeException('Unable to create migration worker.');
            }
            if ($migrationPid === 0) {
                fclose($migrationPair[0]);
                fclose($replayPair[0]);
                DB::purge('pgsql');
                stream_set_timeout($migrationPair[1], 20);
                try {
                    $runtime = DB::getDefaultConnection();
                    $config = config('database.connections.'.$runtime);
                    config()->set('database.connections.direct_case_race_owner', [...$config, 'username' => 'org_test_owner']);
                    DB::setDefaultConnection('direct_case_race_owner');
                    Schema::clearResolvedInstance('db.schema');
                    $identity = DB::selectOne('SELECT pg_backend_pid() AS pid, current_user AS name');
                    if ($identity->name !== 'org_test_owner') {
                        throw new RuntimeException('Migration worker must use owner role.');
                    }
                    fwrite($migrationPair[1], json_encode(['pid' => $identity->pid], JSON_THROW_ON_ERROR)."\n");
                    $migration = require database_path('migrations/2026_09_09_000600_bind_direct_public_orders_to_assessment_cases.php');
                    $migration->up();
                    $result = ['migrated' => true];
                } catch (Throwable $exception) {
                    $result = ['class' => $exception::class, 'error' => $exception->getMessage()];
                }
                ForkedProcessResult::sendOrExitFailure($migrationPair[1], $result);
                fgets($migrationPair[1]);
                fclose($migrationPair[1]);
                exit(0);
            }
            fclose($migrationPair[1]);
            stream_set_timeout($migrationPair[0], 20);
            $workers[] = ['pid' => $migrationPid, 'socket' => $migrationPair[0]];
            $migrationBackend = json_decode((string) fgets($migrationPair[0]), true, flags: JSON_THROW_ON_ERROR)['pid'];
            $this->waitForBackendLock(
                $migrationBackend,
                'Migration must wait on the participant table first.',
                $migrationPair[0],
                'direct_case_race_monitor',
            );

            DB::select('SELECT pg_advisory_unlock(?)', [$gate]);
            $gateHeld = false;
            $replayResult = json_decode((string) fgets($replayPair[0]), true, flags: JSON_THROW_ON_ERROR);
            $migrationResult = json_decode((string) fgets($migrationPair[0]), true, flags: JSON_THROW_ON_ERROR);
            fwrite($migrationPair[0], "finish\n");

            return ['replay' => $replayResult, 'migration' => $migrationResult];
        } finally {
            if ($gateHeld) {
                DB::select('SELECT pg_advisory_unlock(?)', [$gate]);
            }
            foreach ($workers as $worker) {
                fclose($worker['socket']);
                pcntl_waitpid($worker['pid'], $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }
            DB::purge('direct_case_race_monitor');
            config()->set('database.connections.direct_case_race_monitor', null);
        }
    }

    /** @param resource|null $resultSocket */
    private function waitForBackendLock(
        int $backendId,
        string $message,
        $resultSocket = null,
        ?string $monitorConnection = null,
    ): void {
        $deadline = microtime(true) + 5;
        $waiting = null;
        do {
            $waiting = DB::connection($monitorConnection)->selectOne(
                'SELECT state, wait_event_type, wait_event, query FROM pg_stat_activity WHERE pid = ?',
                [$backendId],
            );
            if ($waiting?->wait_event_type === 'Lock') {
                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        $earlyResult = null;
        if (is_resource($resultSocket)) {
            $read = [$resultSocket];
            $write = null;
            $except = null;
            if (stream_select($read, $write, $except, 0) === 1) {
                $earlyResult = trim((string) fgets($resultSocket));
            }
        }

        $this->fail(
            $message
            .' Last activity: '.json_encode($waiting, JSON_THROW_ON_ERROR)
            .' Early result: '.($earlyResult ?? 'none'),
        );
    }
}
