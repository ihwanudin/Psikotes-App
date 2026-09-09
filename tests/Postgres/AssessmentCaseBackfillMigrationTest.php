<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Security\RlsContextRunner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AssessmentCaseBackfillMigrationTest extends TestCase
{
    public function test_owner_backfills_mixed_history_and_preserves_security_contract(): void
    {
        $this->asOwner(function (): void {
            DB::beginTransaction();
            try {
                $migration = $this->migration();
                $migration->down();
                DB::statement('ALTER TABLE assessment_cases NO FORCE ROW LEVEL SECURITY');
                DB::statement('ALTER TABLE assessment_participants NO FORCE ROW LEVEL SECURITY');
                $old = $this->graph('old');
                $existing = $this->graph('existing');
                $existingCase = DB::table('assessment_cases')->insertGetId($this->caseRow($existing));
                DB::table('assessment_participants')->where('id', $existing['attempt'])
                    ->update(['assessment_case_id' => $existingCase]);
                $session = $this->createSession($old['participant']);

                $migration->up();
                $migration->up();

                $case = DB::table('assessment_cases')->where('public_id', $old['alias'])->first();
                $this->assertNotNull($case);
                $this->assertSame($old['participant'], $case->participant_id);
                $this->assertSame($old['organization'], $case->organization_id);
                $this->assertSame($old['package'], $case->package_id);
                $this->assertSame('INTEGRATED', $case->origin);
                $this->assertNull($case->intended_field_snapshot);
                $this->assertEquals(
                    DB::table('assessment_participants')->where('id', $old['attempt'])->value('created_at'),
                    $case->created_at,
                );
                $this->assertEquals($case->created_at, $case->updated_at);
                $this->assertSame($case->id, DB::table('assessment_participants')
                    ->where('id', $old['attempt'])->value('assessment_case_id'));
                $this->assertSame($existingCase, DB::table('assessment_participants')
                    ->where('id', $existing['attempt'])->value('assessment_case_id'));
                $this->assertNull(DB::table('test_sessions')->where('id', $session)->value('assessment_case_id'));
                $this->assertTrue($this->columnNotNull('assessment_participants', 'assessment_case_id'));
                $this->assertFalse($this->columnNotNull('test_sessions', 'assessment_case_id'));
                $this->assertConstraint('assessment_cases_integrated_scope_unique', 'u');
                $this->assertConstraint('assessment_participants_case_scope_fk', 'f');
                foreach (['assessment_cases', 'assessment_participants'] as $table) {
                    $security = DB::selectOne(
                        'SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = to_regclass(?)',
                        [$table],
                    );
                    $this->assertTrue($security->relrowsecurity, $table);
                    $this->assertTrue($security->relforcerowsecurity, $table);
                }
                $this->assertSame([
                    'assessment_participants_organization_read', 'assessment_participants_service',
                ], DB::table('pg_policies')->where('tablename', 'assessment_participants')
                    ->orderBy('policyname')->pluck('policyname')->all());

                try {
                    $migration->down();
                    $this->fail('Populated rollback must fail closed.');
                } catch (RuntimeException $exception) {
                    $this->assertSame('Integrated assessment case history prevents rollback.', $exception->getMessage());
                }
                $this->assertTrue($this->columnNotNull('assessment_participants', 'assessment_case_id'));
            } finally {
                DB::rollBack();
            }
        });
    }

    public function test_collision_mismatch_and_late_failure_are_atomic(): void
    {
        foreach (['collision', 'mismatch', 'late', 'malformed', 'timestamp'] as $scenario) {
            $this->asOwner(function () use ($scenario): void {
                DB::beginTransaction();
                try {
                    $migration = $this->migration();
                    $migration->down();
                    DB::statement('ALTER TABLE assessment_cases NO FORCE ROW LEVEL SECURITY');
                    DB::statement('ALTER TABLE assessment_participants NO FORCE ROW LEVEL SECURITY');
                    $first = $this->graph($scenario.'-first');
                    $second = in_array($scenario, ['collision', 'mismatch'], true)
                        ? $this->graph($scenario.'-second')
                        : null;

                    if ($scenario === 'collision') {
                        DB::table('assessment_cases')->insert([
                            ...$this->caseRow($second), 'public_id' => $first['alias'],
                        ]);
                    } elseif ($scenario === 'mismatch') {
                        $case = DB::table('assessment_cases')->insertGetId($this->caseRow($second));
                        DB::table('assessment_participants')->where('id', $first['attempt'])
                            ->update(['assessment_case_id' => $case]);
                    } elseif ($scenario === 'late') {
                        DB::unprepared(<<<'SQL'
                            CREATE FUNCTION synthetic_phase_two_late_failure() RETURNS trigger AS $$
                            BEGIN RAISE EXCEPTION 'synthetic late failure'; END;
                            $$ LANGUAGE plpgsql;
                            CREATE TRIGGER synthetic_phase_two_late_failure
                            BEFORE UPDATE OF assessment_case_id ON assessment_participants
                            FOR EACH ROW EXECUTE FUNCTION synthetic_phase_two_late_failure();
                            SQL);
                    } elseif ($scenario === 'malformed') {
                        DB::table('assessment_participants')->where('id', $first['attempt'])
                            ->update(['assessment_attempt_id' => strtolower($first['alias'])]);
                    } else {
                        DB::table('assessment_participants')->where('id', $first['attempt'])
                            ->update(['created_at' => null]);
                    }

                    $beforeCases = DB::table('assessment_cases')->count();
                    $beforeBound = DB::table('assessment_participants')->whereNotNull('assessment_case_id')->count();
                    try {
                        $migration->up();
                        $this->fail('Malformed history must abort migration.');
                    } catch (RuntimeException $exception) {
                        $this->assertStringStartsWith('Integrated assessment case backfill aborted:', $exception->getMessage());
                    }
                    $this->assertSame($beforeCases, DB::table('assessment_cases')->count(), $scenario);
                    $this->assertSame($beforeBound, DB::table('assessment_participants')
                        ->whereNotNull('assessment_case_id')->count(), $scenario);
                    $this->assertFalse($this->columnNotNull('assessment_participants', 'assessment_case_id'));
                } finally {
                    DB::rollBack();
                }
            });
        }
    }

    public function test_runtime_cannot_bypass_exact_immutable_integrated_identity(): void
    {
        DB::beginTransaction();
        try {
            $identity = DB::selectOne(<<<'SQL'
                SELECT role.rolsuper, role.rolbypassrls
                FROM pg_roles role WHERE role.rolname = current_user
                SQL);
            $this->assertFalse($identity->rolsuper);
            $this->assertFalse($identity->rolbypassrls);

            app(RlsContextRunner::class)->runAsService(function (): void {
                $graph = $this->graph('runtime', false);
                $case = DB::table('assessment_cases')->insertGetId($this->caseRow($graph));
                $attempt = $this->insertAttempt($graph, $graph['alias'], 'runtime', $case);
                DB::table('assessment_participants')->where('id', $attempt)
                    ->update(['assessment_status' => 'READY']);
                $this->assertSame('READY', DB::table('assessment_participants')
                    ->where('id', $attempt)->value('assessment_status'));
                $this->assertSqlState('P0001', fn () => DB::table('assessment_participants')
                    ->where('id', $attempt)->update(['assessment_attempt_id' => (string) Str::ulid()]));

                $wrong = $this->graph('wrong-origin', false);
                $wrongCase = DB::table('assessment_cases')->insertGetId([
                    ...$this->caseRow($wrong), 'origin' => 'DIRECT_PUBLIC',
                ]);
                $this->assertSqlState('23514', fn () => $this->insertAttempt(
                    $wrong, $wrong['alias'], 'wrong-origin', $wrongCase,
                ));
                $this->assertSqlState('23514', fn () => $this->insertAttempt(
                    $wrong, (string) Str::ulid(), 'missing-case', null,
                ));
            });
        } finally {
            DB::rollBack();
        }
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_09_09_000300_backfill_integrated_assessment_cases.php');
    }

    /** @return array{organization:int,participant:int,package:int,client:int,attempt:int,alias:string,created_at:string} */
    private function graph(string $suffix, bool $withAttempt = true): array
    {
        $alias = (string) Str::ulid();
        $organization = DB::table('branches')->insertGetId([
            'code' => $alias, 'ref_code' => $alias, 'name' => 'Synthetic',
            'organization_code' => $alias, 'display_name' => 'Synthetic',
        ]);
        $package = DB::table('packages')->insertGetId([
            'code' => 'PKG-'.$alias, 'name' => 'Synthetic', 'amount' => 0, 'currency' => 'IDR',
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $organization, 'referral_branch_id' => $organization,
            'referral_source' => 'default', 'full_name' => 'Synthetic', 'phone' => '620000000000',
        ]);
        $client = DB::table('integration_clients')->insertGetId([
            'organization_id' => $organization, 'client_id' => 'client-'.$alias,
            'credential_reference' => 'synthetic',
        ]);
        $created_at = '2026-09-09 01:02:03.123456+00';
        $attempt = $withAttempt
            ? $this->insertAttempt(compact('organization', 'participant', 'package', 'client'), $alias, $suffix, null, $created_at)
            : 0;

        return compact('organization', 'participant', 'package', 'client', 'attempt', 'alias', 'created_at');
    }

    /** @param array{organization:int,participant:int,package:int,client:int} $graph */
    private function insertAttempt(
        array $graph,
        string $alias,
        string $suffix,
        ?int $case,
        string $createdAt = '2026-09-09 01:02:03.123456+00',
    ): int {
        return DB::table('assessment_participants')->insertGetId([
            'integration_client_id' => $graph['client'], 'organization_id' => $graph['organization'],
            'participant_id' => $graph['participant'], 'package_id' => $graph['package'],
            'assessment_case_id' => $case, 'assessment_attempt_id' => $alias,
            'source_system' => 'SYNTHETIC', 'external_candidate_id' => 'candidate-'.$suffix,
            'funding_mode' => 'SPONSORED', 'assessment_status' => 'PROVISIONED', 'result_version' => 0,
            'idempotency_key' => 'key-'.$suffix, 'request_hash' => hash('sha256', $suffix),
            'logical_assessment_key' => hash('sha256', 'logical-'.$suffix),
            'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);
    }

    /** @param array{organization:int,participant:int,package:int,alias:string} $graph
     * @return array<string, mixed>
     */
    private function caseRow(array $graph): array
    {
        return [
            'public_id' => $graph['alias'], 'participant_id' => $graph['participant'],
            'organization_id' => $graph['organization'], 'package_id' => $graph['package'],
            'origin' => 'INTEGRATED', 'intended_field_snapshot' => null,
            'created_at' => $graph['created_at'], 'updated_at' => $graph['created_at'],
        ];
    }

    private function createSession(int $participant): int
    {
        return DB::table('test_sessions')->insertGetId([
            'public_id' => (string) Str::ulid(), 'participant_id' => $participant,
            'test_type' => 'ist', 'attempt_no' => random_int(1, 1000000),
            'authorization_id' => (string) Str::ulid(), 'allocation_intent_id' => (string) Str::ulid(),
            'duration_seconds' => 3600, 'status' => 'created', 'answers_revision' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function columnNotNull(string $table, string $column): bool
    {
        return DB::selectOne(<<<'SQL'
            SELECT attnotnull FROM pg_attribute
            WHERE attrelid = to_regclass(?) AND attname = ? AND NOT attisdropped
            SQL, [$table, $column])->attnotnull;
    }

    private function assertConstraint(string $name, string $type): void
    {
        $constraint = DB::table('pg_constraint')->where('conname', $name)->first();
        $this->assertNotNull($constraint, $name);
        $this->assertSame($type, $constraint->contype, $name);
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
        config()->set('database.connections.assessment_case_backfill_owner', [...$config, 'username' => 'org_test_owner']);
        DB::setDefaultConnection('assessment_case_backfill_owner');
        Schema::clearResolvedInstance('db.schema');
        try {
            $this->assertSame('org_test_owner', DB::selectOne('SELECT current_user AS name')->name);
            $callback();
        } finally {
            DB::setDefaultConnection($runtime);
            Schema::clearResolvedInstance('db.schema');
            DB::purge('assessment_case_backfill_owner');
            config()->set('database.connections.assessment_case_backfill_owner', null);
        }
    }
}
