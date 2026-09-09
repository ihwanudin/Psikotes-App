<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** PostgreSQL-authoritative durable checkout-session schema, RLS, and rollback proof. */
final class CheckoutSessionSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_runtime_schema_constraints_indexes_and_service_only_rls_are_exact(): void
    {
        $identity = DB::selectOne('SELECT current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        $this->assertSame('psikotes_runtime', $identity->name);
        $this->assertFalse($identity->rolsuper);
        $this->assertFalse($identity->rolbypassrls);

        $names = DB::table('pg_constraint')->whereRaw("conrelid = 'checkout_sessions'::regclass")
            ->pluck('conname')->all();
        foreach (self::constraintNames() as $name) {
            $this->assertContains($name, $names);
        }
        $indexes = DB::select("SELECT indexname, indexdef FROM pg_indexes
            WHERE schemaname = 'public' AND tablename = 'checkout_sessions' ORDER BY indexname");
        $definitions = collect($indexes)->mapWithKeys(fn ($index): array => [$index->indexname => $index->indexdef]);
        foreach (['checkout_sessions_selector_digest_unique', 'checkout_sessions_checkout_handoff_id_unique',
            'checkout_sessions_one_active_attempt_unique', 'checkout_sessions_attempt_latest_idx',
            'checkout_sessions_expiry_cleanup_idx'] as $name) {
            $this->assertTrue($definitions->has($name), "Missing {$name}.");
        }
        $this->assertStringNotContainsString('now()', strtolower(implode(' ', $definitions->all())));
        $security = DB::selectOne("SELECT relrowsecurity, relforcerowsecurity
            FROM pg_class WHERE oid = 'checkout_sessions'::regclass");
        $this->assertTrue($security->relrowsecurity);
        $this->assertTrue($security->relforcerowsecurity);
        $policy = DB::table('pg_policies')->where('schemaname', 'public')
            ->where('tablename', 'checkout_sessions')->sole();
        $this->assertSame('checkout_sessions_service', $policy->policyname);
        $this->assertSame('{psikotes_runtime}', $policy->roles);
        $this->assertStringContainsString("app_role() = 'service'", $policy->qual);
        $this->assertStringContainsString("app_role() = 'service'", $policy->with_check);
    }

    /** @return iterable<string, array{array<string,mixed>}> */
    public static function invalidRows(): iterable
    {
        yield 'public id' => [['public_id' => 'INVALID']];
        yield 'selector uppercase' => [['selector_digest' => str_repeat('A', 64)]];
        yield 'csrf short' => [['csrf_digest' => str_repeat('a', 63)]];
        yield 'blank source' => [['source_system' => '']];
        yield 'foreign contract' => [['contract_version' => 'checkout-v1']];
        yield 'last seen before establish' => [['last_seen_at' => '2026-09-02 00:00:59+00']];
        yield 'idle not after last seen' => [['idle_expires_at' => '2026-09-02 00:02:00+00']];
        yield 'idle after absolute' => [['idle_expires_at' => '2026-09-02 02:02:01+00']];
        yield 'active inactive marker' => [['active_marker' => null]];
        yield 'active terminal timestamp' => [['revoked_at' => '2026-09-02 00:03:00+00']];
        yield 'revoked missing timestamp' => [['status' => 'REVOKED', 'active_marker' => null,
            'revocation_reason' => 'LOGOUT']];
        yield 'revoked unknown reason' => [['status' => 'REVOKED', 'active_marker' => null,
            'revoked_at' => '2026-09-02 00:03:00+00', 'revocation_reason' => 'FREE_TEXT']];
        yield 'expired missing timestamp' => [['status' => 'EXPIRED', 'active_marker' => null]];
        yield 'expired before due' => [['status' => 'EXPIRED', 'active_marker' => null,
            'expired_at' => '2026-09-02 00:31:59+00']];
        yield 'unknown status' => [['status' => 'PENDING']];
    }

    #[DataProvider('invalidRows')]
    public function test_runtime_check_constraints_reject_invalid_rows(array $override): void
    {
        app(RlsContextRunner::class)->runAsService(function () use ($override): void {
            $graph = $this->graph();
            $this->assertConstraintViolation('23514', function () use ($graph, $override): void {
                DB::table('checkout_sessions')->insert([...$this->row($graph), ...$override]);
            });
            $this->assertSame(0, DB::table('checkout_sessions')->count());
        });
    }

    public function test_runtime_uniques_and_cross_tenant_composite_binding_are_authoritative(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            $graph = $this->graph();
            $first = $this->row($graph, 'REVOKED');
            DB::table('checkout_sessions')->insert($first);
            foreach ([
                ['checkout_handoff_id' => $graph['handoff']],
                ['selector_digest' => $first['selector_digest']],
                ['csrf_digest' => $first['csrf_digest']],
            ] as $override) {
                $other = $this->graph();
                $this->assertConstraintViolation('23505', function () use ($other, $override): void {
                    DB::table('checkout_sessions')->insert([...$this->row($other, 'REVOKED'), ...$override]);
                });
            }

            $active = $this->graph();
            DB::table('checkout_sessions')->insert($this->row($active));
            $second = $this->consumedHandoff($active, 2);
            $this->assertConstraintViolation('23505', function () use ($active, $second): void {
                DB::table('checkout_sessions')->insert($this->row([...$active, 'handoff' => $second]));
            });

            $bound = $this->graph();
            $foreign = $this->graph();
            $this->assertConstraintViolation('23503', function () use ($bound, $foreign): void {
                DB::table('checkout_sessions')->insert([...$this->row($bound, 'REVOKED'),
                    'organization_id' => $foreign['organization']]);
            });
            $this->assertSame(2, DB::table('checkout_sessions')->count());
        });
    }

    public function test_runtime_no_context_and_non_service_roles_are_denied(): void
    {
        $graph = app(RlsContextRunner::class)->runAsService(function (): array {
            $graph = $this->graph();
            DB::table('checkout_sessions')->insert($this->row($graph));

            return $graph;
        });
        DB::select("SELECT set_config('app.role', '', true), set_config('app.branch_id', '', true), set_config('app.participant_id', '', true)");
        $this->assertSame(0, DB::table('checkout_sessions')->count());
        foreach (['participant', 'branch_admin', 'staff', 'psychologist', 'super_admin'] as $role) {
            app(RlsContextRunner::class)->run(
                new RlsContext($role, $graph['organization'], $graph['participant']),
                function (): void {
                    $this->assertSame(0, DB::table('checkout_sessions')->count());
                },
            );
            try {
                app(RlsContextRunner::class)->run(
                    new RlsContext($role, $graph['organization'], $graph['participant']),
                    fn () => DB::table('checkout_sessions')->insert($this->row($graph, 'REVOKED')),
                );
                $this->fail('Non-service role inserted a checkout session.');
            } catch (QueryException $exception) {
                $this->assertSame('42501', $exception->getCode());
            }
        }
        app(RlsContextRunner::class)->runAsService(function (): void {
            $this->assertSame(1, DB::table('checkout_sessions')->count());
        });
    }

    public function test_runtime_attempt_and_handoff_cascade_while_source_and_client_restrict(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            $graph = $this->graph();
            DB::table('checkout_sessions')->insert($this->row($graph));
            foreach ([['integration_sources', $graph['source']], ['integration_clients', $graph['client']]] as [$table, $id]) {
                $this->assertConstraintViolation('23503', fn () => DB::table($table)->where('id', $id)->delete());
            }
            DB::table('checkout_handoffs')->where('id', $graph['handoff'])->delete();
            $this->assertSame(0, DB::table('checkout_sessions')->count());

            $other = $this->graph();
            DB::table('checkout_sessions')->insert($this->row($other));
            DB::table('assessment_participants')->where('id', $other['attempt'])->delete();
            $this->assertSame(0, DB::table('checkout_sessions')->count());
        });
    }

    public function test_owner_empty_roundtrip_populated_down_and_parent_preflight_are_fail_closed(): void
    {
        $this->assertFileExists('/.dockerenv');
        $runId = getenv('ORG_TEST_RUN_ID');
        $this->assertIsString($runId);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $runId);
        $runtime = DB::getDefaultConnection();
        $config = config('database.connections.'.$runtime);
        config()->set('database.connections.checkout_session_ddl_test', [...$config, 'username' => 'org_test_owner']);
        $owner = DB::connection('checkout_session_ddl_test');
        try {
            $owner->beginTransaction();
            DB::setDefaultConnection('checkout_session_ddl_test');
            Schema::clearResolvedInstance('db.schema');
            $structure = $this->structure();
            $graph = $this->graph();
            $handoffBefore = DB::table('checkout_handoffs')->where('id', $graph['handoff'])->first();
            $this->migrateDown();
            $this->assertFalse(Schema::hasTable('checkout_sessions'));
            $this->assertFalse($this->parentScopeUniqueExists());
            $this->assertEquals($handoffBefore, DB::table('checkout_handoffs')->where('id', $graph['handoff'])->first());
            $this->migrateUp();
            $this->assertEquals($structure, $this->structure());

            DB::statement("SELECT set_config('app.role', 'service', true)");
            DB::table('checkout_sessions')->insert($this->row($graph));
            $before = DB::table('checkout_sessions')->first();
            DB::statement("SELECT set_config('app.role', '', true)");
            try {
                $this->migrateDown();
                $this->fail('Rollback discarded checkout session history.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Checkout session history prevents rollback.', $exception->getMessage());
            }
            $this->assertEquals($structure, $this->structure());
            DB::statement("SELECT set_config('app.role', 'service', true)");
            $this->assertEquals($before, DB::table('checkout_sessions')->first());

            DB::table('checkout_sessions')->delete();
            $this->migrateDown();
            DB::statement('ALTER TABLE checkout_handoffs DROP CONSTRAINT checkout_handoffs_pkey');
            $duplicate = (array) DB::table('checkout_handoffs')->where('id', $graph['handoff'])->first();
            $duplicate['public_id'] = (string) Str::ulid();
            $duplicate['token_digest'] = hash('sha256', 'duplicate-token');
            $duplicate['issue_number'] = 2;
            $duplicate['issue_idempotency_key_digest'] = hash('sha256', 'duplicate-idempotency');
            $duplicate['request_hash'] = hash('sha256', 'duplicate-request');
            DB::table('checkout_handoffs')->insert($duplicate);
            try {
                $this->migrateUp();
                $this->fail('Duplicate parent scope bypassed migration preflight.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Checkout handoff scope prevents checkout session migration.', $exception->getMessage());
            }
            $this->assertFalse(Schema::hasTable('checkout_sessions'));
            $this->assertFalse($this->parentScopeUniqueExists());
        } finally {
            if ($owner->transactionLevel() > 0) {
                $owner->rollBack();
            }
            DB::setDefaultConnection($runtime);
            Schema::clearResolvedInstance('db.schema');
            DB::purge('checkout_session_ddl_test');
            config()->set('database.connections.checkout_session_ddl_test', null);
        }
        $this->assertSame('psikotes_runtime', DB::selectOne('SELECT current_user AS name')->name);
    }

    /** @return list<string> */
    private static function constraintNames(): array
    {
        return [
            'checkout_sessions_handoff_scope_fk', 'checkout_sessions_attempt_scope_fk',
            'checkout_sessions_client_scope_fk', 'checkout_sessions_source_scope_fk',
            'checkout_sessions_identity_check', 'checkout_sessions_digest_check',
            'checkout_sessions_time_check', 'checkout_sessions_lifecycle_check',
        ];
    }

    /** @return array{organization:int,participant:int,client:int,source:int,package:int,attempt:int,handoff:int} */
    private function graph(): array
    {
        $key = (string) Str::ulid();
        $organization = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => 'Synthetic', 'organization_code' => $key,
            'display_name' => 'Synthetic',
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $organization, 'referral_branch_id' => $organization,
            'referral_source' => 'default', 'full_name' => 'Synthetic', 'phone' => '620000000000',
        ]);
        $client = DB::table('integration_clients')->insertGetId([
            'organization_id' => $organization, 'client_id' => $key, 'credential_reference' => 'synthetic-only',
        ]);
        $source = DB::table('integration_sources')->insertGetId([
            'integration_client_id' => $client, 'source_system' => 'CHECKOUT_SESSION_SOURCE',
            'contract_version' => 'checkout-v2', 'allowed_assessment_packages' => '["SESSION"]',
            'allowed_funding_modes' => '[]', 'status' => 'ACTIVE',
        ]);
        $package = DB::table('packages')->insertGetId([
            'code' => $key, 'name' => 'Synthetic', 'amount' => 100, 'currency' => 'IDR',
        ]);
        $timestamp = now();
        $case = DB::table('assessment_cases')->insertGetId([
            'public_id' => $key, 'participant_id' => $participant, 'organization_id' => $organization,
            'package_id' => $package, 'origin' => 'INTEGRATED', 'intended_field_snapshot' => null,
            'created_at' => $timestamp, 'updated_at' => $timestamp,
        ]);
        $attempt = DB::table('assessment_participants')->insertGetId([
            'organization_id' => $organization, 'integration_client_id' => $client,
            'participant_id' => $participant, 'package_id' => $package,
            'assessment_case_id' => $case, 'assessment_attempt_id' => $key, 'source_system' => 'CHECKOUT_SESSION_SOURCE',
            'external_candidate_id' => $key, 'funding_mode' => 'COMMERCIAL_SELF_PAY',
            'assessment_status' => 'PROVISIONED', 'idempotency_key' => $key,
            'request_hash' => hash('sha256', $key), 'logical_assessment_key' => hash('sha256', 'logical'.$key),
            'created_at' => $timestamp, 'updated_at' => $timestamp,
        ]);
        $handoff = $this->consumedHandoff(compact(
            'organization', 'participant', 'client', 'source', 'package', 'attempt',
        ), 1);

        return compact('organization', 'participant', 'client', 'source', 'package', 'attempt', 'handoff');
    }

    /** @param array{organization:int,participant:int,client:int,source:int,package:int,attempt:int} $graph */
    private function consumedHandoff(array $graph, int $issue): int
    {
        $issued = CarbonImmutable::parse('2026-09-02T00:00:00+00:00');

        return DB::table('checkout_handoffs')->insertGetId([
            'public_id' => (string) Str::ulid(), 'assessment_participant_id' => $graph['attempt'],
            'organization_id' => $graph['organization'], 'participant_id' => $graph['participant'],
            'package_id' => $graph['package'], 'integration_client_id' => $graph['client'],
            'integration_source_id' => $graph['source'], 'source_system' => 'CHECKOUT_SESSION_SOURCE',
            'contract_version' => 'checkout-v2', 'purpose' => 'checkout-handoff',
            'destination' => 'integrated-checkout-session',
            'token_digest' => hash('sha256', $graph['attempt'].'-token-'.$issue),
            'active_marker' => null, 'status' => 'CONSUMED', 'issue_number' => $issue,
            'issue_idempotency_key_digest' => hash('sha256', $graph['attempt'].'-idempotency-'.$issue),
            'request_hash' => hash('sha256', $graph['attempt'].'-request-'.$issue),
            'issued_at' => $issued, 'expires_at' => $issued->addMinutes(10),
            'consumed_at' => $issued->addMinute(),
        ]);
    }

    /** @param array{organization:int,participant:int,client:int,source:int,package:int,attempt:int,handoff:int} $graph
     * @return array<string,mixed>
     */
    private function row(array $graph, string $status = 'ACTIVE'): array
    {
        $established = CarbonImmutable::parse('2026-09-02T00:02:00+00:00');
        $row = [
            'public_id' => (string) Str::ulid(),
            'selector_digest' => hash('sha256', (string) Str::ulid()),
            'csrf_digest' => hash('sha256', (string) Str::ulid()),
            'checkout_handoff_id' => $graph['handoff'], 'assessment_participant_id' => $graph['attempt'],
            'organization_id' => $graph['organization'], 'participant_id' => $graph['participant'],
            'package_id' => $graph['package'], 'integration_client_id' => $graph['client'],
            'integration_source_id' => $graph['source'], 'source_system' => 'CHECKOUT_SESSION_SOURCE',
            'contract_version' => 'checkout-v2', 'status' => $status,
            'active_marker' => $status === 'ACTIVE' ? true : null,
            'established_at' => $established, 'last_seen_at' => $established,
            'idle_expires_at' => $established->addMinutes(30),
            'absolute_expires_at' => $established->addMinutes(120),
            'revoked_at' => null, 'expired_at' => null, 'revocation_reason' => null,
        ];
        if ($status === 'REVOKED') {
            $row['revoked_at'] = $established->addMinute();
            $row['revocation_reason'] = 'LOGOUT';
        } elseif ($status === 'EXPIRED') {
            $row['expired_at'] = $established->addMinutes(30);
        }

        return $row;
    }

    /** @param callable(): void $operation */
    private function assertConstraintViolation(string $sqlState, callable $operation): void
    {
        DB::beginTransaction();
        try {
            $operation();
            $this->fail('Expected database constraint violation was not raised.');
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, $exception->getCode());
        } finally {
            DB::rollBack();
        }
    }

    /** @return array<string,mixed> */
    private function structure(): array
    {
        return [
            'columns' => DB::select("SELECT attname, format_type(atttypid, atttypmod) AS type, attnotnull,
                pg_get_expr(adbin, adrelid) AS default_value FROM pg_attribute
                LEFT JOIN pg_attrdef ON adrelid = attrelid AND adnum = attnum
                WHERE attrelid = 'checkout_sessions'::regclass AND attnum > 0 AND NOT attisdropped ORDER BY attnum"),
            'constraints' => DB::select("SELECT conname, pg_get_constraintdef(oid) AS definition
                FROM pg_constraint WHERE conrelid = 'checkout_sessions'::regclass ORDER BY conname"),
            'indexes' => DB::select("SELECT indexname, indexdef FROM pg_indexes
                WHERE schemaname = 'public' AND tablename = 'checkout_sessions' ORDER BY indexname"),
            'policies' => DB::select("SELECT * FROM pg_policies
                WHERE schemaname = 'public' AND tablename = 'checkout_sessions' ORDER BY policyname"),
            'security' => DB::select("SELECT relrowsecurity, relforcerowsecurity, relowner
                FROM pg_class WHERE oid = 'checkout_sessions'::regclass"),
            'parent_unique' => $this->parentScopeUniqueExists(),
        ];
    }

    private function parentScopeUniqueExists(): bool
    {
        return DB::table('pg_constraint')->where('conname', 'checkout_handoffs_checkout_session_scope_unique')
            ->whereRaw("conrelid = 'checkout_handoffs'::regclass")->exists();
    }

    private function migrateUp(): void
    {
        $migration = require database_path('migrations/2026_09_02_000200_create_checkout_sessions.php');
        if (! is_object($migration) || ! method_exists($migration, 'up')) {
            throw new RuntimeException('Checkout session migration has no up method.');
        }
        $migration->up();
    }

    private function migrateDown(): void
    {
        $migration = require database_path('migrations/2026_09_02_000200_create_checkout_sessions.php');
        if (! is_object($migration) || ! method_exists($migration, 'down')) {
            throw new RuntimeException('Checkout session migration has no down method.');
        }
        $migration->down();
    }
}
