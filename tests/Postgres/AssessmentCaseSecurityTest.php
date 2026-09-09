<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AssessmentCaseSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_runtime_is_nonowner_and_has_forced_service_only_least_privilege(): void
    {
        $identity = DB::selectOne(<<<'SQL'
            SELECT current_user AS name, role.rolsuper, role.rolbypassrls,
                pg_get_userbyid(class.relowner) AS table_owner,
                class.relrowsecurity, class.relforcerowsecurity
            FROM pg_roles role CROSS JOIN pg_class class
            WHERE role.rolname = current_user AND class.oid = 'assessment_cases'::regclass
            SQL);
        $this->assertSame('psikotes_runtime', $identity->name);
        $this->assertFalse($identity->rolsuper);
        $this->assertFalse($identity->rolbypassrls);
        $this->assertNotSame('psikotes_runtime', $identity->table_owner);
        $this->assertTrue($identity->relrowsecurity);
        $this->assertTrue($identity->relforcerowsecurity);

        foreach (['SELECT', 'INSERT', 'UPDATE'] as $privilege) {
            $this->assertTrue(DB::selectOne(
                "SELECT has_table_privilege('psikotes_runtime', 'assessment_cases', ?) AS allowed",
                [$privilege],
            )->allowed, $privilege);
        }
        foreach (['DELETE', 'TRUNCATE', 'TRIGGER', 'REFERENCES'] as $privilege) {
            $this->assertFalse(DB::selectOne(
                "SELECT has_table_privilege('psikotes_runtime', 'assessment_cases', ?) AS allowed",
                [$privilege],
            )->allowed, $privilege);
        }

        $policies = DB::table('pg_policies')->where('schemaname', 'public')
            ->where('tablename', 'assessment_cases')->orderBy('policyname')->get();
        $this->assertSame([
            'assessment_cases_service_insert',
            'assessment_cases_service_read',
            'assessment_cases_service_update',
        ], $policies->pluck('policyname')->all());
        foreach ($policies as $policy) {
            $this->assertSame('{psikotes_runtime}', $policy->roles);
            $this->assertStringContainsString("app_private.app_role() = 'service'", ($policy->qual ?? '').($policy->with_check ?? ''));
        }

        $indexes = DB::table('pg_indexes')->where('schemaname', 'public')
            ->where('tablename', 'assessment_cases')->pluck('indexname')->all();
        $this->assertContains('assessment_cases_package_idx', $indexes);
    }

    public function test_missing_and_nonservice_contexts_are_denied_while_service_can_bind_once(): void
    {
        $graph = app(RlsContextRunner::class)->runAsService(fn (): array => $this->graph());
        $row = $this->caseRow($graph);
        DB::select("SELECT set_config('app.role', '', true), set_config('app.branch_id', '', true),
            set_config('app.participant_id', '', true)");

        $this->assertSame(0, DB::table('assessment_cases')->count());
        $this->assertSqlState('42501', fn () => DB::table('assessment_cases')->insert($row));
        foreach (['super_admin', 'psychologist', 'branch_admin', 'staff', 'participant'] as $role) {
            $context = match ($role) {
                'branch_admin', 'staff' => new RlsContext($role, $graph['branch']),
                'participant' => new RlsContext($role, $graph['branch'], $graph['participant']),
                default => new RlsContext($role),
            };
            app(RlsContextRunner::class)->run($context, function () use ($row): void {
                $this->assertSame(0, DB::table('assessment_cases')->count());
                $this->assertSqlState('42501', fn () => DB::table('assessment_cases')->insert($row));
            });
        }

        app(RlsContextRunner::class)->runAsService(function () use ($graph, $row): void {
            $id = DB::table('assessment_cases')->insertGetId($row);
            DB::table('assessment_cases')->where('id', $id)->update([
                'package_id' => $graph['package'], 'intended_field_snapshot' => 'UMUM',
                'updated_at' => '2026-09-09 01:00:01+00',
            ]);
            $this->assertSame(1, DB::table('assessment_cases')->where('id', $id)->count());
            $this->assertSqlState('P0001', fn () => DB::table('assessment_cases')->where('id', $id)->update([
                'intended_field_snapshot' => 'KAIGO', 'updated_at' => '2026-09-09 01:00:02+00',
            ]));
            $this->assertSqlState('P0001', fn () => DB::table('assessment_cases')->where('id', $id)->update([
                'origin' => 'INTEGRATED', 'updated_at' => '2026-09-09 01:00:02+00',
            ]));
            $this->assertSqlState('P0001', fn () => DB::table('assessment_cases')->where('id', $id)->update([
                'updated_at' => '2026-09-09 01:00:01+00',
            ]));
            $this->assertSqlState('42501', fn () => DB::table('assessment_cases')->where('id', $id)->delete());
        });
    }

    public function test_checks_foreign_keys_required_exact_links_and_nullable_sessions_are_authoritative(): void
    {
        app(RlsContextRunner::class)->runAsService(function (): void {
            $graph = $this->graph();
            $otherOrganization = $this->createBranch();
            foreach ([
                ['public_id' => strtolower((string) Str::ulid())],
                ['origin' => 'SELF_PAY'],
                ['participant_id' => 999999],
                ['organization_id' => 999999],
                ['organization_id' => $otherOrganization],
                ['package_id' => 999999],
                ['intended_field_snapshot' => 'UNKNOWN'],
                ['created_at' => null],
                ['updated_at' => null],
            ] as $override) {
                $column = array_key_first($override);
                $this->assertSqlState(
                    in_array($column, ['participant_id', 'organization_id', 'package_id'], true)
                        ? '23503'
                        : (in_array($column, ['created_at', 'updated_at'], true) ? '23502' : '23514'),
                    fn () => DB::table('assessment_cases')->insert([
                        ...$this->caseRow($graph), 'public_id' => (string) Str::ulid(), ...$override,
                    ]),
                );
            }

            $firstAlias = (string) Str::ulid();
            $secondAlias = (string) Str::ulid();
            $first = DB::table('assessment_cases')->insertGetId([
                ...$this->caseRow($graph), 'public_id' => $firstAlias, 'package_id' => $graph['package'],
                'origin' => 'INTEGRATED',
            ]);
            $second = DB::table('assessment_cases')->insertGetId([
                ...$this->caseRow($graph), 'public_id' => $secondAlias, 'package_id' => $graph['package'],
                'origin' => 'INTEGRATED',
            ]);
            $attemptOne = $this->assessmentParticipant($graph, 'one', $firstAlias, $first);
            $attemptTwo = $this->assessmentParticipant($graph, 'two', $secondAlias, $second);
            $session = $this->createTestSession($graph['participant'], $first);
            $this->assertSame($first, DB::table('assessment_participants')->where('id', $attemptOne)->value('assessment_case_id'));
            $this->assertSame($first, DB::table('test_sessions')->where('id', $session)->value('assessment_case_id'));
            $this->assertSqlState('P0001', fn () => DB::table('test_sessions')->where('id', $session)
                ->update(['assessment_case_id' => $second]));
            $this->assertSqlState('P0001', fn () => DB::table('assessment_participants')->where('id', $attemptTwo)
                ->update(['assessment_case_id' => $first]));
            $this->assertSqlState('P0001', fn () => DB::table('assessment_participants')->where('id', $attemptTwo)
                ->update(['assessment_case_id' => 999999]));
        });
    }

    public function test_existing_parent_rls_policies_are_preserved(): void
    {
        $attemptPolicies = DB::table('pg_policies')->where('tablename', 'assessment_participants')
            ->orderBy('policyname')->pluck('policyname')->all();
        $sessionPolicies = DB::table('pg_policies')->where('tablename', 'test_sessions')
            ->orderBy('policyname')->pluck('policyname')->all();

        $this->assertSame([
            'assessment_participants_organization_read',
            'assessment_participants_service',
        ], $attemptPolicies);
        $this->assertSame([
            'test_sessions_participant_update',
            'test_sessions_read',
            'test_sessions_service_delete',
            'test_sessions_service_insert',
            'test_sessions_service_update',
        ], $sessionPolicies);
    }

    public function test_owner_empty_roundtrip_and_populated_rollback_are_fail_closed(): void
    {
        $this->asOwner(function (): void {
            $migration = require database_path('migrations/2026_09_09_000200_create_assessment_cases.php');
            $phaseTwo = require database_path('migrations/2026_09_09_000300_backfill_integrated_assessment_cases.php');
            $sessionCase = require database_path('migrations/2026_09_09_000400_harden_test_session_case_identity.php');
            $legacySelectionCase = require database_path('migrations/2026_09_09_000500_bind_legacy_selection_assessment_cases.php');
            DB::beginTransaction();
            try {
                $legacySelectionCase->down();
                $sessionCase->down();
                $phaseTwo->down();
                $migration->down();
                $this->assertFalse(Schema::hasTable('assessment_cases'));
                $this->assertTrue(Schema::hasTable('assessment_participants'));
                $this->assertTrue(Schema::hasTable('test_sessions'));
                $migration->up();
                $phaseTwo->up();
                $sessionCase->up();
                $legacySelectionCase->up();
            } finally {
                DB::rollBack();
            }

            DB::beginTransaction();
            try {
                DB::statement('SET LOCAL row_security = off');
                $legacySelectionCase->down();
                $sessionCase->down();
                $phaseTwo->down();
                $graph = $this->graph();
                DB::table('assessment_cases')->insert($this->caseRow($graph));
                try {
                    $migration->down();
                    $this->fail('Populated rollback must be refused.');
                } catch (RuntimeException $exception) {
                    $this->assertSame('Assessment case history or bindings prevent rollback.', $exception->getMessage());
                }
                $this->assertTrue(Schema::hasTable('assessment_cases'));
                $this->assertTrue(DB::selectOne("SELECT relforcerowsecurity FROM pg_class WHERE oid = 'assessment_cases'::regclass")->relforcerowsecurity);
            } finally {
                DB::rollBack();
            }
        });
    }

    /** @return array{branch:int,package:int,participant:int,client:int} */
    private function graph(): array
    {
        $key = (string) Str::ulid();
        $branch = $this->createBranch($key);
        $package = DB::table('packages')->insertGetId([
            'code' => 'PKG-'.$key, 'name' => 'Synthetic', 'amount' => 0,
            'currency' => 'IDR', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $branch, 'referral_branch_id' => $branch, 'referral_source' => 'default',
            'package_id' => $package, 'source_system' => 'DIRECT_PUBLIC',
            'full_name' => 'Synthetic', 'phone' => '620000000000',
        ]);
        $client = DB::table('integration_clients')->insertGetId([
            'organization_id' => $branch, 'client_id' => 'client-'.$key,
            'credential_reference' => 'synthetic', 'enabled' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return compact('branch', 'package', 'participant', 'client');
    }

    private function createBranch(?string $key = null): int
    {
        $key ??= (string) Str::ulid();

        return DB::table('branches')->insertGetId([
            'code' => $key, 'name' => 'Synthetic', 'ref_code' => $key,
            'organization_code' => $key, 'display_name' => 'Synthetic',
        ]);
    }

    /** @param array{branch:int,package:int,participant:int,client:int} $graph
     * @return array<string,mixed>
     */
    private function caseRow(array $graph): array
    {
        return [
            'public_id' => (string) Str::ulid(), 'participant_id' => $graph['participant'],
            'organization_id' => $graph['branch'], 'package_id' => null,
            'origin' => 'DIRECT_PUBLIC', 'intended_field_snapshot' => null,
            'created_at' => '2026-09-09 01:00:00+00', 'updated_at' => '2026-09-09 01:00:00+00',
        ];
    }

    /** @param array{branch:int,package:int,participant:int,client:int} $graph */
    private function assessmentParticipant(
        array $graph,
        string $candidate,
        string $alias,
        int $case,
    ): int {
        return DB::table('assessment_participants')->insertGetId([
            'integration_client_id' => $graph['client'], 'organization_id' => $graph['branch'],
            'participant_id' => $graph['participant'], 'package_id' => $graph['package'],
            'assessment_case_id' => $case, 'assessment_attempt_id' => $alias, 'source_system' => 'SYNTHETIC',
            'external_candidate_id' => $candidate, 'funding_mode' => 'SPONSORED',
            'assessment_status' => 'READY', 'result_version' => 0,
            'idempotency_key' => 'key-'.$candidate, 'request_hash' => hash('sha256', $candidate),
            'logical_assessment_key' => hash('sha256', 'logical-'.$candidate),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function createTestSession(int $participant, ?int $case = null): int
    {
        return DB::table('test_sessions')->insertGetId([
            'public_id' => (string) Str::ulid(), 'participant_id' => $participant,
            'assessment_case_id' => $case,
            'test_type' => 'ist', 'attempt_no' => random_int(1, 1000000),
            'authorization_id' => (string) Str::ulid(), 'allocation_intent_id' => (string) Str::ulid(),
            'duration_seconds' => 3600, 'status' => 'created', 'answers_revision' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
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

    private function asOwner(callable $callback): void
    {
        $runtime = DB::getDefaultConnection();
        $config = config('database.connections.'.$runtime);
        config()->set('database.connections.assessment_case_owner', [...$config, 'username' => 'org_test_owner']);
        DB::setDefaultConnection('assessment_case_owner');
        Schema::clearResolvedInstance('db.schema');

        try {
            $this->assertSame('org_test_owner', DB::selectOne('SELECT current_user AS name')->name);
            $callback();
        } finally {
            DB::setDefaultConnection($runtime);
            Schema::clearResolvedInstance('db.schema');
            DB::purge('assessment_case_owner');
            config()->set('database.connections.assessment_case_owner', null);
        }
    }
}
