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
                $this->assertSame(['dass21', 'ist'], DB::table('entitlements')->where('order_id', $order->id)
                    ->orderBy('test_type')->pluck('test_type')->all());
                $this->assertSame(2, DB::table('consent_records')->where('participant_id', $participant->id)->count());
            });
        } finally {
            $this->cleanupConcurrentGraph($token, $fixture);
        }
    }

    /** @param list<string> $types
     * @return array{branch:int,package:int,participant:int,method:int}
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
        $method = DB::table('payment_methods')->insertGetId([
            'code' => 'method-'.$key, 'display_name' => $suffix, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return compact('branch', 'package', 'participant', 'method');
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
        $migration = require database_path('migrations/2026_09_09_000600_bind_direct_public_orders_to_assessment_cases.php');
        $operation = [$migration, $direction];
        if (! is_callable($operation)) {
            throw new RuntimeException("Migration operation {$direction} is unavailable.");
        }
        $operation();
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
                    fwrite($pair[1], json_encode($result, JSON_THROW_ON_ERROR)."\n");
                    fclose($pair[1]);
                    DB::disconnect('pgsql');
                    exit(0);
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
}
