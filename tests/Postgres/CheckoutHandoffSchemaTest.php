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

/** PostgreSQL-authoritative checkout handoff constraints, FK lifecycle, and RLS proof. */
final class CheckoutHandoffSchemaTest extends TestCase
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

    public function test_runtime_schema_named_contract_indexes_and_service_only_forced_rls(): void
    {
        $role = DB::selectOne('SELECT current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        $this->assertSame('psikotes_runtime', $role->name);
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);

        $columns = DB::table('information_schema.columns')->where('table_schema', 'public')
            ->where('table_name', 'checkout_handoffs')->get()->keyBy('column_name');
        $this->assertSame('bigint', $columns['id']->data_type);
        $this->assertSame('character', $columns['public_id']->data_type);
        $this->assertSame(26, $columns['public_id']->character_maximum_length);
        foreach (['token_digest', 'issue_idempotency_key_digest', 'request_hash'] as $digest) {
            $this->assertSame('character', $columns[$digest]->data_type);
            $this->assertSame(64, $columns[$digest]->character_maximum_length);
        }
        foreach (['issued_at', 'expires_at', 'consumed_at', 'revoked_at', 'expired_at', 'created_at', 'updated_at'] as $time) {
            $this->assertSame('timestamp with time zone', $columns[$time]->data_type);
        }
        $this->assertArrayNotHasKey('issue_idempotency_key', $columns->all());

        $constraints = DB::table('pg_constraint')->where('conrelid', DB::raw("'checkout_handoffs'::regclass"))
            ->pluck('conname')->all();
        foreach (self::constraintNames() as $constraint) {
            $this->assertContains($constraint, $constraints);
        }
        $indexes = DB::table('pg_indexes')->where('schemaname', 'public')->where('tablename', 'checkout_handoffs')
            ->pluck('indexname')->all();
        foreach (['checkout_handoffs_cleanup_idx', 'checkout_handoffs_source_status_idx'] as $index) {
            $this->assertContains($index, $indexes);
        }
        $security = DB::selectOne("SELECT relrowsecurity, relforcerowsecurity, pg_get_userbyid(relowner) AS owner
            FROM pg_class WHERE oid = 'checkout_handoffs'::regclass");
        $this->assertTrue($security->relrowsecurity);
        $this->assertTrue($security->relforcerowsecurity);
        $this->assertNotSame('psikotes_runtime', $security->owner);
        $policies = DB::table('pg_policies')->where('schemaname', 'public')->where('tablename', 'checkout_handoffs')->get();
        $this->assertCount(1, $policies);
        $this->assertSame('checkout_handoffs_service', $policies->first()->policyname);
        $this->assertSame('{psikotes_runtime}', $policies->first()->roles);
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('invalidRows')]
    public function test_runtime_rejects_malformed_fixed_digest_ttl_and_lifecycle_rows(array $override, string $sqlState = '23514'): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode($sqlState);
        app(RlsContextRunner::class)->runAsService(function () use ($override): void {
            $graph = $this->graph();
            DB::table('checkout_handoffs')->insert([...$this->row($graph), ...$override]);
        });
    }

    /** @return iterable<string, array{array<string, mixed>, 1?: string}> */
    public static function invalidRows(): iterable
    {
        yield 'bad public ulid' => [['public_id' => 'not-a-ulid']];
        yield 'wrong contract' => [['contract_version' => 'v1']];
        yield 'wrong purpose' => [['purpose' => 'assessment-start']];
        yield 'wrong destination' => [['destination' => 'foreign-session']];
        foreach (['token_digest', 'issue_idempotency_key_digest', 'request_hash'] as $field) {
            yield $field.' uppercase' => [[$field => str_repeat('A', 64)]];
            yield $field.' short' => [[$field => str_repeat('a', 63)]];
        }
        yield 'issue zero' => [['issue_number' => 0]];
        yield 'zero ttl' => [['expires_at' => '2026-09-02T00:00:00+00:00']];
        yield 'ttl over maximum' => [['expires_at' => '2026-09-02T00:10:01+00:00']];
        yield 'issued inactive' => [['active_marker' => null]];
        yield 'issued consumed timestamp' => [['consumed_at' => '2026-09-02T00:01:00+00:00']];
        yield 'consumed missing timestamp' => [['status' => 'CONSUMED', 'active_marker' => null]];
        yield 'consumed after expiry' => [['status' => 'CONSUMED', 'active_marker' => null,
            'consumed_at' => '2026-09-02T00:10:00+00:00']];
        yield 'revoked missing reason' => [['status' => 'REVOKED', 'active_marker' => null,
            'revoked_at' => '2026-09-02T00:01:00+00:00']];
        yield 'revoked unknown reason' => [['status' => 'REVOKED', 'active_marker' => null,
            'revoked_at' => '2026-09-02T00:01:00+00:00', 'revocation_reason' => 'FREE_TEXT']];
        yield 'expired before expiry' => [['status' => 'EXPIRED', 'active_marker' => null,
            'expired_at' => '2026-09-02T00:09:59+00:00']];
        yield 'unknown status' => [['status' => 'PENDING']];
    }

    public function test_runtime_allows_terminal_history_and_enforces_single_active_and_idempotency_uniques(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            $graph = $this->graph();
            DB::table('checkout_handoffs')->insert($this->row($graph));
            foreach (['CONSUMED', 'REVOKED', 'EXPIRED'] as $offset => $status) {
                DB::table('checkout_handoffs')->insert($this->row($graph, $offset + 2, $status));
            }
            $this->assertSame(4, DB::table('checkout_handoffs')->count());
            $this->assertConstraintViolation('23505', function () use ($graph): void {
                DB::table('checkout_handoffs')->insert($this->row($graph, 5));
            });
            $duplicate = $this->row($graph, 6, 'REVOKED');
            $duplicate['issue_idempotency_key_digest'] = hash('sha256', $graph['attempt'].'-idempotency-2');
            $this->assertConstraintViolation('23505', function () use ($duplicate): void {
                DB::table('checkout_handoffs')->insert($duplicate);
            });
        });
    }

    public function test_runtime_composite_foreign_keys_reject_every_cross_scope_binding(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            $graph = $this->graph();
            $foreign = $this->graph();
            $foreignVersionSource = DB::table('integration_sources')->insertGetId([
                'integration_client_id' => $graph['client'], 'source_system' => 'HANDOFF_SOURCE',
                'contract_version' => 'v1', 'allowed_assessment_packages' => '["HANDOFF"]',
                'allowed_funding_modes' => '[]', 'status' => 'ACTIVE',
            ]);
            $invalid = [
                'attempt client' => ['integration_client_id' => $foreign['client'], 'integration_source_id' => $foreign['source']],
                'source client' => ['integration_source_id' => $foreign['source']],
                'source system' => ['source_system' => 'FOREIGN_SOURCE'],
                'source contract' => ['integration_source_id' => $foreignVersionSource],
                'organization' => ['organization_id' => $foreign['organization']],
                'participant' => ['participant_id' => $foreign['participant']],
                'package' => ['package_id' => $foreign['package']],
            ];

            foreach ($invalid as $label => $override) {
                $this->assertConstraintViolation('23503', function () use ($graph, $override): void {
                    DB::table('checkout_handoffs')->insert([...$this->row($graph), ...$override]);
                });
                $this->assertSame(0, DB::table('checkout_handoffs')->count(), $label);
            }

            DB::table('checkout_handoffs')->insert($this->row($graph));
            $this->assertSame(1, DB::table('checkout_handoffs')->count());
        });
    }

    public function test_runtime_no_context_and_non_service_are_denied_while_service_is_allowed(): void
    {
        $graph = app(RlsContextRunner::class)->runAsService(function (): array {
            $graph = $this->graph();
            DB::table('checkout_handoffs')->insert($this->row($graph));

            return $graph;
        });
        DB::select("SELECT set_config('app.role', '', true), set_config('app.branch_id', '', true), set_config('app.participant_id', '', true)");
        $this->assertSame(0, DB::table('checkout_handoffs')->count());
        foreach (['participant', 'branch_admin', 'staff', 'psychologist', 'super_admin'] as $offset => $role) {
            app(RlsContextRunner::class)->run(new RlsContext($role, $graph['organization'], $graph['participant']), function (): void {
                $this->assertSame(0, DB::table('checkout_handoffs')->count());
            });
            try {
                app(RlsContextRunner::class)->run(
                    new RlsContext($role, $graph['organization'], $graph['participant']),
                    fn () => DB::table('checkout_handoffs')->insert($this->row($graph, $offset + 2, 'CONSUMED')),
                );
                $this->fail('Non-service role inserted a checkout handoff.');
            } catch (QueryException $exception) {
                $this->assertSame('42501', $exception->getCode());
            }
        }
        app(RlsContextRunner::class)->runAsService(function () use ($graph): void {
            $this->assertSame(1, DB::table('checkout_handoffs')->count());
            $this->assertSame($graph['attempt'], DB::table('checkout_handoffs')->value('assessment_participant_id'));
        });
    }

    public function test_runtime_attempt_cascade_and_source_client_restrict_are_authoritative(): void
    {
        DB::beginTransaction();
        try {
            app(RlsContextRunner::class)->runAsService(function (): void {
                $graph = $this->graph();
                DB::table('checkout_handoffs')->insert($this->row($graph));
                foreach ([['integration_sources', $graph['source']], ['integration_clients', $graph['client']]] as [$table, $id]) {
                    $this->assertConstraintViolation('23503', function () use ($table, $id): void {
                        DB::table($table)->where('id', $id)->delete();
                    });
                }
                DB::table('assessment_participants')->where('id', $graph['attempt'])->delete();
                $this->assertSame(0, DB::table('checkout_handoffs')->count());
                $this->assertSame(1, DB::table('participants')->where('id', $graph['participant'])->count());
            });
        } finally {
            DB::rollBack();
        }
    }

    public function test_owner_empty_roundtrip_preserves_graph_and_populated_down_refuses_without_partial_ddl(): void
    {
        $this->assertFileExists('/.dockerenv');
        $runId = getenv('ORG_TEST_RUN_ID');
        $this->assertIsString($runId);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $runId);
        $runtime = DB::getDefaultConnection();
        $config = config('database.connections.'.$runtime);
        $this->assertSame('org-test-db', $config['host']);
        $this->assertSame('psikotes_organization_test', $config['database']);
        config()->set('database.connections.checkout_handoff_ddl_test', [...$config, 'username' => 'org_test_owner']);
        $owner = DB::connection('checkout_handoff_ddl_test');
        try {
            $owner->beginTransaction();
            DB::setDefaultConnection('checkout_handoff_ddl_test');
            Schema::clearResolvedInstance('db.schema');
            $graph = $this->graph();
            $counts = $this->graphCounts();
            $structure = $this->structure();
            $this->migrateDown();
            $this->assertFalse(Schema::hasTable('checkout_handoffs'));
            $this->assertSame([], $this->parentScopeConstraints());
            $this->assertSame($counts, $this->graphCounts());
            $this->migrateUp();
            $this->assertEquals($structure, $this->structure());
            $this->assertSame($counts, $this->graphCounts());

            DB::statement("SELECT set_config('app.role', 'service', true)");
            DB::table('checkout_handoffs')->insert($this->row($graph));
            $before = DB::table('checkout_handoffs')->first();
            DB::statement("SELECT set_config('app.role', '', true)");
            try {
                $this->migrateDown();
                $this->fail('Rollback discarded checkout handoff history.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Checkout handoff history prevents rollback.', $exception->getMessage());
            }
            $this->assertNull(DB::selectOne('SELECT app_private.app_role() AS role')->role);
            $this->assertEquals($structure, $this->structure());
            DB::statement("SELECT set_config('app.role', 'service', true)");
            $this->assertEquals($before, DB::table('checkout_handoffs')->first());
        } finally {
            if ($owner->transactionLevel() > 0) {
                $owner->rollBack();
            }
            DB::setDefaultConnection($runtime);
            Schema::clearResolvedInstance('db.schema');
            DB::purge('checkout_handoff_ddl_test');
            config()->set('database.connections.checkout_handoff_ddl_test', null);
        }
        $this->assertSame('psikotes_runtime', DB::selectOne('SELECT current_user AS name')->name);
    }

    /** @return list<string> */
    private static function constraintNames(): array
    {
        return [
            'checkout_handoffs_attempt_scope_fk', 'checkout_handoffs_client_fk', 'checkout_handoffs_source_scope_fk',
            'checkout_handoffs_identity_check', 'checkout_handoffs_digest_check', 'checkout_handoffs_fixed_binding_check',
            'checkout_handoffs_ttl_check', 'checkout_handoffs_lifecycle_check',
        ];
    }

    /** @return array{organization:int,participant:int,client:int,source:int,package:int,attempt:int} */
    private function graph(): array
    {
        $key = (string) Str::ulid();
        $organization = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => 'Synthetic', 'organization_code' => $key,
            'display_name' => 'Synthetic',
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $organization, 'referral_branch_id' => $organization, 'referral_source' => 'default',
            'full_name' => 'Synthetic', 'gender' => 'male', 'birth_date' => '2000-01-01',
            'education_level' => 'SMA_SMK', 'intended_field' => 'UMUM', 'phone' => '620000000000',
        ]);
        $client = DB::table('integration_clients')->insertGetId([
            'organization_id' => $organization, 'client_id' => $key, 'credential_reference' => 'synthetic-only',
        ]);
        $source = DB::table('integration_sources')->insertGetId([
            'integration_client_id' => $client, 'source_system' => 'HANDOFF_SOURCE',
            'contract_version' => 'checkout-v2', 'allowed_assessment_packages' => '["HANDOFF"]',
            'allowed_funding_modes' => '[]', 'status' => 'ACTIVE',
        ]);
        $package = DB::table('packages')->insertGetId([
            'code' => $key, 'name' => 'Synthetic', 'amount' => 100, 'currency' => 'IDR',
        ]);
        $attempt = DB::table('assessment_participants')->insertGetId([
            'organization_id' => $organization, 'integration_client_id' => $client, 'participant_id' => $participant,
            'package_id' => $package, 'assessment_attempt_id' => $key, 'source_system' => 'HANDOFF_SOURCE',
            'external_candidate_id' => $key, 'funding_mode' => 'COMMERCIAL_SELF_PAY',
            'assessment_status' => 'PROVISIONED', 'idempotency_key' => $key,
            'request_hash' => hash('sha256', $key), 'logical_assessment_key' => hash('sha256', 'logical'.$key),
        ]);

        return compact('organization', 'participant', 'client', 'source', 'package', 'attempt');
    }

    /** @param array{organization:int,participant:int,client:int,source:int,package:int,attempt:int} $graph
     * @return array<string, mixed>
     */
    private function row(array $graph, int $issue = 1, string $status = 'ISSUED'): array
    {
        $issued = CarbonImmutable::parse('2026-09-02T00:00:00+00:00');
        $row = [
            'public_id' => (string) Str::ulid(), 'assessment_participant_id' => $graph['attempt'],
            'organization_id' => $graph['organization'], 'participant_id' => $graph['participant'],
            'package_id' => $graph['package'], 'integration_client_id' => $graph['client'],
            'integration_source_id' => $graph['source'], 'source_system' => 'HANDOFF_SOURCE',
            'contract_version' => 'checkout-v2', 'purpose' => 'checkout-handoff',
            'destination' => 'integrated-checkout-session',
            'token_digest' => hash('sha256', $graph['attempt'].'-token-'.$issue),
            'active_marker' => $status === 'ISSUED' ? true : null, 'status' => $status, 'issue_number' => $issue,
            'issue_idempotency_key_digest' => hash('sha256', $graph['attempt'].'-idempotency-'.$issue),
            'request_hash' => hash('sha256', $graph['attempt'].'-request-'.$issue), 'issued_at' => $issued,
            'expires_at' => $issued->addMinutes(10), 'consumed_at' => null, 'revoked_at' => null,
            'expired_at' => null, 'revocation_reason' => null,
        ];
        if ($status === 'CONSUMED') {
            $row['consumed_at'] = $issued->addMinute();
        } elseif ($status === 'REVOKED') {
            $row['revoked_at'] = $issued->addMinute();
            $row['revocation_reason'] = 'REISSUED';
        } elseif ($status === 'EXPIRED') {
            $row['expired_at'] = $issued->addMinutes(10);
        }

        return $row;
    }

    /** @return array<string, int> */
    private function graphCounts(): array
    {
        return [
            'branches' => DB::table('branches')->count(), 'participants' => DB::table('participants')->count(),
            'clients' => DB::table('integration_clients')->count(), 'sources' => DB::table('integration_sources')->count(),
            'packages' => DB::table('packages')->count(), 'attempts' => DB::table('assessment_participants')->count(),
        ];
    }

    private function migrateUp(): void
    {
        $migration = require database_path('migrations/2026_09_02_000100_create_checkout_handoffs.php');
        if (! is_object($migration) || ! method_exists($migration, 'up')) {
            throw new RuntimeException('Checkout handoff migration has no up method.');
        }
        $migration->up();
    }

    private function migrateDown(): void
    {
        $migration = require database_path('migrations/2026_09_02_000100_create_checkout_handoffs.php');
        if (! is_object($migration) || ! method_exists($migration, 'down')) {
            throw new RuntimeException('Checkout handoff migration has no down method.');
        }
        $migration->down();
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

    /** @return array<string, mixed> */
    private function structure(): array
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
            'parent_scope_constraints' => $this->parentScopeConstraints(),
        ];
    }

    /** @return array<int, object> */
    private function parentScopeConstraints(): array
    {
        return DB::select("SELECT conrelid::regclass::text AS table_name, conname,
            pg_get_constraintdef(oid) AS definition FROM pg_constraint
            WHERE conname IN (
                'assessment_attempt_checkout_handoff_scope_unique',
                'integration_sources_checkout_handoff_scope_unique'
            ) ORDER BY conname");
    }
}
