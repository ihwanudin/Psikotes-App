<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Domain\AssessmentSessions\SessionDefinition;
use App\Security\RlsContextRunner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TestSessionDefinitionSnapshotSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(Schema::hasColumn('test_sessions', 'session_definition_payload'));
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_catalog_is_nullable_unindexed_and_guards_are_owner_only(): void
    {
        $rows = DB::select(<<<'SQL'
            SELECT attname,format_type(atttypid,atttypmod) type,attnotnull
            FROM pg_attribute
            WHERE attrelid='test_sessions'::regclass
              AND attname LIKE 'session_definition_%' AND attnum > 0 AND NOT attisdropped
            ORDER BY attname
            SQL);
        $columns = [];
        foreach ($rows as $column) {
            $data = (array) $column;
            $columns[(string) $data['attname']] = [(string) $data['type'], (bool) $data['attnotnull']];
        }
        $this->assertSame([
            'session_definition_checksum' => ['character(64)', false],
            'session_definition_payload' => ['jsonb', false],
            'session_definition_provenance' => ['character varying(255)', false],
            'session_definition_version' => ['character varying(100)', false],
        ], $columns);
        $this->assertSame(0, (int) DB::scalar(<<<'SQL'
            SELECT count(*) FROM pg_indexes
            WHERE schemaname='public' AND tablename='test_sessions'
              AND indexdef ILIKE '%session_definition_checksum%'
            SQL));

        $functions = collect(DB::select(<<<'SQL'
            SELECT proc.proname,proc.prosecdef,proc.proconfig,language.lanname,
                pg_get_userbyid(proc.proowner) owner,proc.proacl
            FROM pg_proc proc
            JOIN pg_namespace namespace ON namespace.oid=proc.pronamespace
            JOIN pg_language language ON language.oid=proc.prolang
            WHERE namespace.nspname='app_private' AND proc.proname IN (
                'guard_test_session_definition_snapshot'
            ) ORDER BY proc.proname
            SQL))->keyBy('proname');
        $this->assertCount(1, $functions);
        $owner = DB::scalar("SELECT pg_get_userbyid(relowner) FROM pg_class WHERE oid='test_sessions'::regclass");
        foreach ($functions as $function) {
            $this->assertSame($owner, $function->owner);
            $this->assertSame('plpgsql', $function->lanname);
            $this->assertSame('{"search_path=pg_catalog, public"}', $function->proconfig);
            $this->assertSame("{{$owner}=X/{$owner}}", $function->proacl);
        }
        $this->assertFalse((bool) $functions['guard_test_session_definition_snapshot']->prosecdef);

        $triggers = collect(DB::select(<<<'SQL'
            SELECT tgname,tgenabled,pg_get_triggerdef(oid,false) definition
            FROM pg_trigger
            WHERE NOT tgisinternal AND tgname LIKE '%definition_snapshot%'
            ORDER BY tgname
            SQL))->keyBy('tgname');
        $this->assertSame([
            'test_sessions_definition_snapshot_guard',
        ], $triggers->keys()->all());
        $this->assertSame('O', $triggers['test_sessions_definition_snapshot_guard']->tgenabled);
    }

    public function test_runtime_service_accepts_legacy_and_snapshotted_grants_during_expand_window(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            $historical = $this->directFixture(false);
            foreach ($this->snapshotColumns() as $column) {
                $this->assertNull(DB::table('test_sessions')->where('id', $historical['session'])->value($column));
            }
            DB::table('test_session_grants')->insert($this->grantRow($historical));
            $this->assertSame(1, DB::table('test_session_grants')
                ->where('test_session_id', $historical['session'])->count());

            $bound = $this->directFixture(true);
            DB::table('test_session_grants')->insert($this->grantRow($bound));
            $this->assertSame(1, DB::table('test_session_grants')
                ->where('test_session_id', $bound['session'])->count());

            $started = now();
            DB::table('test_sessions')->where('id', $bound['session'])->update([
                'status' => 'in_progress', 'started_at' => $started,
                'ends_at' => $started->copy()->addSeconds(60),
            ]);
            $this->assertSame('in_progress', DB::table('test_sessions')->where('id', $bound['session'])->value('status'));
            $this->assertSqlState('P0001', fn () => DB::table('test_sessions')
                ->where('id', $bound['session'])->update(['session_definition_version' => 'synthetic-v2']));
        });
    }

    public function test_postgres_rejects_invalid_identity_shape_and_kraepelin_types(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            $definition = $this->fixedDefinition();
            $valid = $this->sessionRow() + $this->snapshot($definition);

            $this->assertSqlState('23514', fn () => DB::table('test_sessions')->insert([
                ...$valid, 'session_definition_checksum' => str_repeat('A', 64),
            ]));
            $this->assertSqlState('23514', fn () => DB::table('test_sessions')->insert([
                ...$valid, 'session_definition_version' => "synthetic\u{200B}v1",
            ]));
            $extra = $definition;
            $extra['unexpected'] = true;
            $this->assertSqlState('23514', fn () => DB::table('test_sessions')->insert(
                $this->sessionRow() + $this->snapshot($extra),
            ));
            $formatCode = $definition;
            $formatCode['subtests'][0]['code'] = "SYNTHETIC\u{200B}";
            $this->assertSqlState('23514', fn () => DB::table('test_sessions')->insert(
                $this->sessionRow() + $this->snapshot($formatCode),
            ));

            $kraepelin = $this->kraepelinDefinition();
            DB::table('test_sessions')->insert(
                $this->sessionRow(duration: 750, testType: 'kraepelin') + $this->snapshot($kraepelin),
            );
            $kraepelin['generator']['columns'] = '50';
            $this->assertSqlState('23514', fn () => DB::table('test_sessions')->insert(
                $this->sessionRow(duration: 750, testType: 'kraepelin') + $this->snapshot($kraepelin),
            ));
        });
    }

    #[DataProvider('counterfeitDefinitions')]
    public function test_owner_rerun_rejects_counterfeit_definitions_without_delta(string $component): void
    {
        $this->asOwner(function () use ($component): void {
            DB::beginTransaction();
            try {
                match ($component) {
                    'constraint' => DB::unprepared('ALTER TABLE test_sessions DROP CONSTRAINT test_sessions_definition_snapshot_completeness_check; ALTER TABLE test_sessions ADD CONSTRAINT test_sessions_definition_snapshot_completeness_check CHECK (true)'),
                    'function_body' => DB::unprepared("CREATE OR REPLACE FUNCTION app_private.guard_test_session_definition_snapshot() RETURNS trigger LANGUAGE plpgsql SET search_path = pg_catalog, public AS \$\$ BEGIN PERFORM 'jsonb_object_length(definition) <> 9'; RETURN NEW; END; \$\$"),
                    'function_security' => DB::unprepared('ALTER FUNCTION app_private.guard_test_session_definition_snapshot() SECURITY DEFINER'),
                    'function_search_path' => DB::unprepared('ALTER FUNCTION app_private.guard_test_session_definition_snapshot() SET search_path = public'),
                    'function_acl' => DB::unprepared('GRANT EXECUTE ON FUNCTION app_private.guard_test_session_definition_snapshot() TO psikotes_runtime'),
                    'disabled_trigger' => DB::unprepared('ALTER TABLE test_sessions DISABLE TRIGGER test_sessions_definition_snapshot_guard'),
                    'extra_trigger' => DB::unprepared('CREATE TRIGGER counterfeit_definition_snapshot_guard BEFORE INSERT ON test_sessions FOR EACH ROW EXECUTE FUNCTION app_private.guard_test_session_definition_snapshot()'),
                    default => throw new RuntimeException("Unknown counterfeit component {$component}."),
                };
                $before = $this->definitions();
                try {
                    (require database_path('migrations/2026_09_10_000100_add_test_session_definition_snapshots.php'))->up();
                    $this->fail("Counterfeit {$component} was accepted.");
                } catch (RuntimeException $exception) {
                    $this->assertStringContainsString('partial PostgreSQL snapshot enforcement', $exception->getMessage());
                }
                $this->assertEquals($before, $this->definitions());
            } finally {
                DB::rollBack();
            }
        });
    }

    /** @return iterable<string,array{string}> */
    public static function counterfeitDefinitions(): iterable
    {
        yield 'completeness constraint' => ['constraint'];
        yield 'session guard body' => ['function_body'];
        yield 'session guard definer' => ['function_security'];
        yield 'unsafe search path' => ['function_search_path'];
        yield 'runtime function execute' => ['function_acl'];
        yield 'disabled session trigger' => ['disabled_trigger'];
        yield 'extra snapshot trigger' => ['extra_trigger'];
    }

    public function test_populated_snapshot_refuses_owner_rollback_without_delta(): void
    {
        $this->asOwner(function (): void {
            DB::beginTransaction();
            try {
                DB::statement("SELECT set_config('app.role','service',true)");
                $definition = $this->fixedDefinition();
                $session = DB::table('test_sessions')->insertGetId(
                    $this->sessionRow() + $this->snapshot($definition),
                );
                $before = $this->definitions();
                try {
                    (require database_path('migrations/2026_09_10_000100_add_test_session_definition_snapshots.php'))->down();
                    $this->fail('Populated definition history must refuse rollback.');
                } catch (RuntimeException $exception) {
                    $this->assertSame('Test session definition snapshot history prevents rollback.', $exception->getMessage());
                }
                $this->assertEquals($before, $this->definitions());
                $this->assertSame($definition['checksum'], DB::table('test_sessions')
                    ->where('id', $session)->value('session_definition_checksum'));
            } finally {
                DB::rollBack();
            }
        });
    }

    public function test_owner_down_up_preserves_historical_null_session_without_backfill(): void
    {
        $this->asOwner(function (): void {
            DB::beginTransaction();
            try {
                DB::statement("SELECT set_config('app.role','service',true)");
                $before = $this->definitions();
                $migration = require database_path('migrations/2026_09_10_000100_add_test_session_definition_snapshots.php');
                $migration->down();
                $this->assertFalse(Schema::hasColumn('test_sessions', 'session_definition_payload'));
                $historical = $this->directFixture(false);
                $migration->up();
                $this->assertEquals($before, $this->definitions());
                foreach ($this->snapshotColumns() as $column) {
                    $this->assertNull(DB::table('test_sessions')
                        ->where('id', $historical['session'])->value($column));
                }
            } finally {
                DB::rollBack();
            }
        });
    }

    /** @return list<string> */
    private function snapshotColumns(): array
    {
        return ['session_definition_version', 'session_definition_provenance',
            'session_definition_checksum', 'session_definition_payload'];
    }

    /** @return array<string,mixed> */
    private function fixedDefinition(): array
    {
        $definition = [
            'instrument' => 'ist', 'version' => 'synthetic-v1',
            'provenance' => 'synthetic-test-fixture', 'checksum' => '',
            'total_duration_seconds' => 60,
            'subtests' => [['code' => 'SYNTHETIC', 'duration_seconds' => 60, 'item_count' => 1]],
            'randomization' => 'fixed', 'seed' => null, 'generator' => null,
        ];
        $definition['checksum'] = SessionDefinition::checksumFor($definition);

        return $definition;
    }

    /** @return array<string,mixed> */
    private function kraepelinDefinition(): array
    {
        $definition = [
            'instrument' => 'kraepelin', 'version' => 'synthetic-v1',
            'provenance' => 'synthetic-test-fixture', 'checksum' => '',
            'total_duration_seconds' => 750,
            'subtests' => [['code' => 'SYNTHETIC', 'duration_seconds' => 750, 'item_count' => 1350]],
            'randomization' => 'seeded', 'seed' => 'synthetic-seed',
            'generator' => [
                'algorithm' => 'synthetic-generator', 'version' => 'synthetic-v1',
                'columns' => 50, 'seconds_per_column' => 15,
                'numbers_per_column' => 28, 'answer_slots_per_column' => 27,
            ],
        ];
        $definition['checksum'] = SessionDefinition::checksumFor($definition);

        return $definition;
    }

    /** @param array<string,mixed> $definition
     * @return array<string,mixed>
     */
    private function snapshot(array $definition): array
    {
        return [
            'session_definition_version' => $definition['version'],
            'session_definition_provenance' => $definition['provenance'],
            'session_definition_checksum' => $definition['checksum'],
            'session_definition_payload' => json_encode($definition, JSON_THROW_ON_ERROR),
        ];
    }

    /** @return array<string,mixed> */
    private function sessionRow(?int $participant = null, int $duration = 60, string $testType = 'ist'): array
    {
        if ($participant === null) {
            $key = (string) Str::ulid();
            $branch = DB::table('branches')->insertGetId([
                'code' => $key, 'ref_code' => $key, 'name' => $key,
                'organization_code' => $key, 'display_name' => $key,
            ]);
            $participant = DB::table('participants')->insertGetId([
                'branch_id' => $branch, 'referral_branch_id' => $branch,
                'referral_source' => 'default', 'source_system' => 'P4_TEST',
                'full_name' => $key, 'phone' => '620000000000',
            ]);
        }

        return [
            'public_id' => (string) Str::ulid(), 'participant_id' => $participant,
            'assessment_case_id' => null, 'test_type' => $testType, 'attempt_no' => 1,
            'authorization_id' => (string) Str::ulid(), 'allocation_intent_id' => (string) Str::ulid(),
            'duration_seconds' => $duration, 'status' => 'created', 'answers_revision' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ];
    }

    /** @return array{branch:int,participant:int,case:int,order:int,entitlement:int,session:int} */
    private function directFixture(bool $snapshot): array
    {
        $key = (string) Str::ulid();
        $branch = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => $key,
            'organization_code' => $key, 'display_name' => $key,
        ]);
        $package = DB::table('packages')->insertGetId([
            'code' => 'PKG-'.$key, 'name' => $key, 'amount' => 99000,
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
            'full_name' => $key, 'phone' => '620000000000',
        ]);
        $publicId = (string) Str::ulid();
        $case = DB::table('assessment_cases')->insertGetId([
            'public_id' => $publicId, 'participant_id' => $participant,
            'organization_id' => $branch, 'package_id' => $package,
            'origin' => 'DIRECT_PUBLIC', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $method = DB::table('payment_methods')->insertGetId([
            'code' => 'METHOD-'.$key, 'display_name' => $key, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $order = DB::table('orders')->insertGetId([
            'public_id' => $publicId, 'participant_id' => $participant,
            'assessment_case_id' => $case, 'payment_method_id' => $method,
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
        $row = $this->sessionRow($participant);
        $row['assessment_case_id'] = $case;
        if ($snapshot) {
            $row += $this->snapshot($this->fixedDefinition());
        }
        $session = DB::table('test_sessions')->insertGetId($row);

        return compact('branch', 'participant', 'case', 'order', 'entitlement', 'session');
    }

    /** @param array{branch:int,participant:int,case:int,order:int,entitlement:int,session:int} $fixture
     * @return array<string,mixed>
     */
    private function grantRow(array $fixture): array
    {
        return [
            'test_session_id' => $fixture['session'], 'assessment_case_id' => $fixture['case'],
            'participant_id' => $fixture['participant'], 'organization_id' => $fixture['branch'],
            'test_type' => 'ist', 'origin' => 'DIRECT_PUBLIC', 'grant_kind' => 'entitlement',
            'order_id' => $fixture['order'], 'entitlement_id' => $fixture['entitlement'],
            'created_at' => now(),
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

    /** @return array<string,mixed> */
    private function definitions(): array
    {
        return [
            'columns' => DB::select("SELECT attname,format_type(atttypid,atttypmod) type,attnotnull FROM pg_attribute WHERE attrelid='test_sessions'::regclass AND attname LIKE 'session_definition_%' AND attnum>0 AND NOT attisdropped ORDER BY attname"),
            'constraints' => DB::select("SELECT conname,pg_get_constraintdef(oid,false) definition FROM pg_constraint WHERE conrelid='test_sessions'::regclass AND conname LIKE '%definition_snapshot%' ORDER BY conname"),
            'triggers' => DB::select("SELECT tgname,tgenabled,pg_get_triggerdef(oid,false) definition FROM pg_trigger WHERE NOT tgisinternal AND tgname LIKE '%definition_snapshot%' ORDER BY tgname"),
            'functions' => DB::select("SELECT proc.proname,proc.prosecdef,proc.proconfig,proc.proacl,proc.prosrc,pg_get_userbyid(proc.proowner) owner FROM pg_proc proc JOIN pg_namespace namespace ON namespace.oid=proc.pronamespace WHERE namespace.nspname='app_private' AND proc.proname LIKE 'guard_test_session%definition_snapshot' ORDER BY proc.proname"),
            'indexes' => DB::select("SELECT indexname,indexdef FROM pg_indexes WHERE schemaname='public' AND tablename='test_sessions' AND indexdef ILIKE '%session_definition_checksum%' ORDER BY indexname"),
        ];
    }

    private function asOwner(callable $callback): void
    {
        $runtime = DB::getDefaultConnection();
        $config = config('database.connections.'.$runtime);
        config()->set('database.connections.session_definition_owner', [...$config, 'username' => 'org_test_owner']);
        DB::setDefaultConnection('session_definition_owner');
        Schema::clearResolvedInstance('db.schema');
        try {
            $this->assertSame('org_test_owner', DB::selectOne('SELECT current_user AS name')->name);
            $callback();
        } finally {
            DB::setDefaultConnection($runtime);
            Schema::clearResolvedInstance('db.schema');
            DB::purge('session_definition_owner');
            config()->set('database.connections.session_definition_owner', null);
        }
    }
}
