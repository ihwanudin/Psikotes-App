<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Security\RlsContextRunner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class GenericEntitlementCaseUniquenessMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('psikotes_runtime', DB::selectOne('SELECT current_user AS name')->name);
        $this->asOwner(fn () => $this->migrate('down'));
    }

    protected function tearDown(): void
    {
        try {
            $this->asOwner(fn () => $this->migrate('up'));
        } finally {
            parent::tearDown();
        }
    }

    public function test_case_and_dass_uniqueness_are_exact_and_down_refuses_multi_case_history(): void
    {
        $this->asOwner(function (): void {
            DB::beginTransaction();
            try {
                $before = $this->catalogExceptContractIndexes();
                $this->migrate('up');
                $indexes = $this->asRuntimeRole(function () use ($before): array {
                    $graph = $this->integratedGraph('scope');
                    $foreign = $this->integratedGraph('foreign');
                    app(RlsContextRunner::class)->runAsService(function () use ($graph): void {
                        DB::table('entitlements')->insert($this->entitlement($graph['participant'], $graph['cases'][0], 'ist'));
                        DB::table('entitlements')->insert($this->entitlement($graph['participant'], $graph['cases'][1], 'ist'));
                        DB::table('entitlements')->insert($this->entitlement($graph['participant'], null, 'dass21'));
                    });
                    $this->assertSame($before, $this->catalogExceptContractIndexes());
                    $this->assertSame(2, app(RlsContextRunner::class)->runAsService(
                        fn (): int => DB::table('entitlements')->where('test_type', 'ist')->count(),
                    ));
                    $this->assertRejected(fn () => DB::table('entitlements')->insert(
                        $this->entitlement($graph['participant'], $graph['cases'][0], 'ist'),
                    ));
                    $this->assertRejected(fn () => DB::table('entitlements')->insert(
                        $this->entitlement($graph['participant'], null, 'dass21'),
                    ));
                    $this->assertGraphRejected(fn () => DB::table('entitlements')->insert(
                        $this->entitlement($graph['participant'], $foreign['cases'][0], 'ist'),
                    ));
                    $dass = app(RlsContextRunner::class)->runAsService(
                        fn (): object => DB::table('entitlements')->where('test_type', 'dass21')->sole(),
                    );
                    $this->assertNull($dass->assessment_case_id);

                    return $this->contractIndexes();
                });
                try {
                    $this->migrate('down');
                    $this->fail('Multi-case history must prevent legacy uniqueness restoration.');
                } catch (RuntimeException $exception) {
                    $this->assertStringContainsString('multi-case entitlement history prevents rollback', $exception->getMessage());
                }
                $this->assertSame($indexes, $this->contractIndexes());
            } finally {
                DB::rollBack();
            }
        });
    }

    public function test_idempotency_and_counterfeit_detection_are_atomic(): void
    {
        $this->asOwner(function (): void {
            DB::beginTransaction();
            try {
                $this->migrate('up');
                $exact = $this->contractIndexes();
                $this->migrate('up');
                $this->assertSame($exact, $this->contractIndexes());
                $this->migrate('down');
                $down = $this->contractIndexes();
                $this->assertSame(['entitlements_case_test_type_unique',
                    'entitlements_participant_id_test_type_unique'], array_column($down, 'indexname'));
                $this->migrate('up');
                $this->assertSame($exact, $this->contractIndexes());
                DB::statement('DROP INDEX entitlements_dass_participant_unique');
                DB::statement("CREATE UNIQUE INDEX entitlements_dass_participant_unique ON entitlements (participant_id) WHERE test_type = 'ist'");
                $counterfeit = $this->contractIndexes();
                try {
                    $this->migrate('up');
                    $this->fail('Counterfeit uniqueness index must fail closed.');
                } catch (RuntimeException $exception) {
                    $this->assertStringContainsString('counterfeit or partial uniqueness state', $exception->getMessage());
                }
                $this->assertSame($counterfeit, $this->contractIndexes());
            } finally {
                DB::rollBack();
            }
        });
    }

    public function test_two_runtime_connections_cannot_create_the_same_case_entitlement(): void
    {
        $this->asOwner(fn () => $this->migrate('up'));
        $graph = $this->integratedGraph('concurrent');
        $entitlement = $this->entitlement($graph['participant'], $graph['cases'][0], 'ist');

        try {
            $results = $this->raceInsert($entitlement);
            $statuses = array_column($results, 'status');
            sort($statuses);
            $this->assertSame(['23505', 'inserted'], $statuses, json_encode($results, JSON_THROW_ON_ERROR));
            $this->assertSame(['psikotes_runtime', 'psikotes_runtime'], array_column($results, 'role'));
            $this->assertSame(1, app(RlsContextRunner::class)->runAsService(
                fn (): int => DB::table('entitlements')
                    ->where('participant_id', $graph['participant'])
                    ->where('assessment_case_id', $graph['cases'][0])
                    ->where('test_type', 'ist')
                    ->count(),
            ));
        } finally {
            $this->cleanupEntitlementWrites($graph['participant']);
        }
    }

    public function test_up_refuses_to_repair_a_missing_000400_boundary(): void
    {
        $this->asOwner(function (): void {
            DB::beginTransaction();
            try {
                $requirement = require database_path('migrations/2026_09_10_000400_enforce_generic_entitlement_case_identity.php');
                $this->assertInstanceOf(Migration::class, $requirement);
                (new \ReflectionMethod($requirement, 'down'))->invoke($requirement);
                $before = $this->catalogExceptContractIndexes();
                try {
                    $this->migrate('up');
                    $this->fail('Missing 000400 boundary must fail closed.');
                } catch (RuntimeException $exception) {
                    $this->assertStringContainsString('000400 requirement and graph guard state is not exact', $exception->getMessage());
                }
                $this->assertSame($before, $this->catalogExceptContractIndexes());
            } finally {
                DB::rollBack();
            }
        });
    }

    /** @return array{branch:int,package:int,client:int,participant:int,cases:list<int>} */
    private function integratedGraph(string $suffix): array
    {
        return app(RlsContextRunner::class)->runAsService(function () use ($suffix): array {
            $key = strtoupper(substr((string) Str::ulid(), 0, 20));
            $branch = DB::table('branches')->insertGetId(['code' => $key, 'name' => $suffix, 'ref_code' => $key,
                'organization_code' => $key, 'display_name' => $suffix]);
            $package = DB::table('packages')->insertGetId(['code' => 'uniq-'.$key, 'name' => $suffix,
                'amount' => 99000, 'currency' => 'IDR', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            foreach (['dass21', 'ist'] as $sort => $type) {
                DB::table('package_items')->insert(['package_id' => $package, 'test_type' => $type,
                    'sort_order' => $sort, 'created_at' => now(), 'updated_at' => now()]);
            }
            $participant = DB::table('participants')->insertGetId(['branch_id' => $branch,
                'referral_branch_id' => $branch, 'referral_source' => 'manual', 'package_id' => $package,
                'source_system' => 'SYNTHETIC', 'full_name' => $suffix, 'intended_field' => 'UMUM',
                'phone' => '620000000001']);
            $client = DB::table('integration_clients')->insertGetId(['organization_id' => $branch,
                'client_id' => 'uniq-'.$key, 'credential_reference' => 'synthetic',
                'result_delivery_mode' => 'POLL', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
            $cases = [];
            foreach ([1, 2] as $number) {
                $publicId = (string) Str::ulid();
                $case = DB::table('assessment_cases')->insertGetId(['public_id' => $publicId,
                    'participant_id' => $participant, 'organization_id' => $branch, 'package_id' => $package,
                    'origin' => 'INTEGRATED', 'created_at' => now(), 'updated_at' => now()]);
                DB::table('assessment_participants')->insert(['assessment_case_id' => $case,
                    'integration_client_id' => $client, 'organization_id' => $branch, 'participant_id' => $participant,
                    'package_id' => $package, 'assessment_attempt_id' => $publicId, 'source_system' => 'SYNTHETIC',
                    'external_candidate_id' => "candidate-{$key}-{$number}", 'funding_mode' => 'SPONSORED',
                    'assessment_status' => 'READY', 'result_version' => 0,
                    'idempotency_key' => "key-{$key}-{$number}",
                    'request_hash' => hash('sha256', "request-{$key}-{$number}"),
                    'logical_assessment_key' => hash('sha256', "logical-{$key}-{$number}"),
                    'created_at' => now(), 'updated_at' => now()]);
                $cases[] = $case;
            }

            return compact('branch', 'package', 'client', 'participant', 'cases');
        });
    }

    /** @return array<string,mixed> */
    private function entitlement(int $participant, ?int $case, string $type): array
    {
        return ['participant_id' => $participant, 'order_id' => null, 'assessment_case_id' => $case,
            'test_type' => $type, 'status' => 'ready', 'ready_at' => now(), 'created_at' => now(), 'updated_at' => now()];
    }

    private function assertRejected(callable $operation): void
    {
        try {
            app(RlsContextRunner::class)->runAsService($operation);
            $this->fail('Uniqueness was not enforced.');
        } catch (QueryException $exception) {
            $this->assertSame('23505', $exception->getCode());
        }
    }

    private function assertGraphRejected(callable $operation): void
    {
        try {
            app(RlsContextRunner::class)->runAsService($operation);
            $this->fail('Cross-tenant case identity must be rejected.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->getCode());
        }
    }

    /** @param array<string,mixed> $entitlement
     * @return list<array{role:string,status:string}>
     */
    private function raceInsert(array $entitlement): array
    {
        $this->assertTrue(function_exists('pcntl_fork'), 'Concurrency requires pcntl; never skip.');
        DB::purge('pgsql');
        $gate = random_int(1, 2_000_000_000);
        $gateHeld = false;
        $workers = [];
        try {
            foreach ([1, 2] as $_worker) {
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
                if ($pair === false || ($pid = pcntl_fork()) === -1) {
                    throw new RuntimeException('Unable to create uniqueness concurrency worker.');
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
                        fwrite($pair[1], json_encode(['pid' => $identity->pid], JSON_THROW_ON_ERROR)."\n");
                        if (fgets($pair[1]) !== "go\n") {
                            throw new RuntimeException('Uniqueness concurrency barrier timed out.');
                        }
                        try {
                            app(RlsContextRunner::class)->runAsService(function () use ($gate, $entitlement): void {
                                DB::select('SELECT pg_advisory_lock_shared(?)', [$gate]);
                                DB::select('SELECT pg_advisory_unlock_shared(?)', [$gate]);
                                DB::table('entitlements')->insert($entitlement);
                            });
                            $status = 'inserted';
                        } catch (QueryException $exception) {
                            $status = (string) $exception->getCode();
                        }
                        $result = ['role' => (string) $identity->name, 'status' => $status];
                    } catch (Throwable $exception) {
                        $result = ['role' => 'unknown', 'status' => $exception::class.': '.$exception->getMessage()];
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
            $this->assertNotSame($backendIds[0], $backendIds[1]);
            foreach ($backendIds as $backendId) {
                $deadline = microtime(true) + 5;
                do {
                    $waiting = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [$backendId]);
                    if ($waiting?->wait_event_type === 'Lock') {
                        break;
                    }
                    usleep(10000);
                } while (microtime(true) < $deadline);
                $this->assertSame('Lock', $waiting?->wait_event_type, 'Both runtime workers must reach the insert barrier.');
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

    private function cleanupEntitlementWrites(int $participant): void
    {
        $this->asOwner(function () use ($participant): void {
            DB::table('entitlements')->where('participant_id', $participant)->delete();
            $this->assertSame(0, DB::table('entitlements')->where('participant_id', $participant)->count());
        });
    }

    /** @return list<array<string,mixed>> */
    private function catalogExceptContractIndexes(): array
    {
        return array_values(array_map(static fn (object $row): array => (array) $row, DB::select(<<<'SQL'
            SELECT 'table' kind,class.relrowsecurity::text value,class.relforcerowsecurity::text extra,COALESCE(class.relacl::text,'') more
            FROM pg_class class WHERE class.oid='entitlements'::regclass
            UNION ALL SELECT 'constraint',pg_get_constraintdef(con.oid,false),con.convalidated::text,con.condeferrable::text
            FROM pg_constraint con WHERE con.conrelid='entitlements'::regclass
              AND con.conname <> 'entitlements_participant_id_test_type_unique'
            UNION ALL SELECT 'index',indexdef,'','' FROM pg_indexes WHERE schemaname='public' AND tablename='entitlements'
              AND indexname NOT IN ('entitlements_participant_id_test_type_unique','entitlements_dass_participant_unique')
            UNION ALL SELECT 'trigger',pg_get_triggerdef(trigger.oid,false),trigger.tgenabled::text,procedure.prosrc
            FROM pg_trigger trigger JOIN pg_proc procedure ON procedure.oid=trigger.tgfoid
            WHERE trigger.tgrelid='entitlements'::regclass AND NOT trigger.tgisinternal
            UNION ALL SELECT 'policy',cmd,COALESCE(qual,''),COALESCE(with_check,'') FROM pg_policies
            WHERE schemaname='public' AND tablename='entitlements' ORDER BY kind,value
            SQL)));
    }

    /** @return list<array<string,mixed>> */
    private function contractIndexes(): array
    {
        return array_values(array_map(static fn (object $row): array => (array) $row, DB::select(<<<'SQL'
            SELECT indexname,indexdef FROM pg_indexes WHERE schemaname='public' AND tablename='entitlements'
              AND indexname IN ('entitlements_case_test_type_unique','entitlements_dass_participant_unique',
                'entitlements_participant_id_test_type_unique') ORDER BY indexname
            SQL)));
    }

    private function migrate(string $operation): void
    {
        $migration = require database_path('migrations/2026_09_10_000500_contract_generic_entitlement_uniqueness.php');
        if (! $migration instanceof Migration || ! in_array($operation, ['up', 'down'], true)) {
            throw new RuntimeException('Uniqueness migration unavailable.');
        }
        (new \ReflectionMethod($migration, $operation))->invoke($migration);
    }

    private function asOwner(callable $callback): mixed
    {
        $runtime = DB::getDefaultConnection();
        $config = config('database.connections.'.$runtime);
        config()->set('database.connections.uniqueness_owner', [...$config, 'username' => 'org_test_owner']);
        DB::setDefaultConnection('uniqueness_owner');
        Schema::clearResolvedInstance('db.schema');
        try {
            return $callback();
        } finally {
            DB::disconnect('uniqueness_owner');
            DB::setDefaultConnection($runtime);
            Schema::clearResolvedInstance('db.schema');
            config()->set('database.connections.uniqueness_owner', null);
        }
    }

    private function asRuntimeRole(callable $callback): mixed
    {
        $owner = DB::selectOne('SELECT current_user AS name');
        $this->assertSame('org_test_owner', $owner->name);
        DB::statement('SET LOCAL ROLE psikotes_runtime');
        try {
            $identity = DB::selectOne(<<<'SQL'
                SELECT current_user AS name, role.rolsuper, role.rolbypassrls,
                       has_table_privilege(current_user, 'entitlements', 'INSERT') AS can_insert,
                       has_table_privilege(current_user, 'entitlements', 'TRIGGER') AS can_trigger
                FROM pg_roles role WHERE role.rolname = current_user
                SQL);
            $this->assertSame('psikotes_runtime', $identity->name);
            $this->assertFalse($identity->rolsuper);
            $this->assertFalse($identity->rolbypassrls);
            $this->assertTrue($identity->can_insert);
            $this->assertFalse($identity->can_trigger);

            return $callback();
        } finally {
            DB::statement('RESET ROLE');
            $this->assertSame('org_test_owner', DB::selectOne('SELECT current_user AS name')->name);
        }
    }
}
