<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Jobs\DispatchGenericAssessmentResultCallback;
use App\Models\AssessmentCase;
use App\Models\AssessmentParticipant;
use App\Models\Branch;
use App\Models\IntegrationClient;
use App\Models\Participant;
use App\Models\TestPackage;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Integrations\GenericAssessmentResultCallbackOrchestrator;
use App\Services\Integrations\GenericAssessmentResultDispatch;
use App\Services\Integrations\GenericAssessmentResultOutbox;
use App\Services\Integrations\GenericAssessmentResultStore;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

/**
 * RLS-GAP-01..04 remediation (tasks/handoffs/f2/database-rls-coverage-audit.md,
 * Group A -- Lead sign-off 2026-09-21). PostgreSQL proof for
 * database/migrations/2026_09_21_000500_harden_generic_assessment_result_pipeline_rls.php.
 */
final class GenericAssessmentResultPipelineRlsSecurityTest extends TestCase
{
    private const TABLES = [
        'generic_assessment_result_versions',
        'generic_assessment_result_outbox',
        'generic_assessment_result_dispatch_attempts',
        'generic_assessment_result_callback_schedules',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        Date::setTestNow('2026-09-21 09:00:00+00:00');
        config()->set('selection_integration.result_callback_enabled', true);
        config()->set('selection_integration.result_callback_base_url', 'https://seleksi.beasiswajepang.id');
        config()->set('selection_integration.result_callback_secret', 'psychotest-to-selection-secret-32-bytes-minimum');
        config()->set('selection_integration.client_secret', 'selection-to-psychotest-secret-is-distinct');
    }

    protected function tearDown(): void
    {
        Date::setTestNow();
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_runtime_has_forced_service_only_rls_and_least_privilege(): void
    {
        foreach (self::TABLES as $table) {
            // UPDATE is granted on all four, including the two "append-only"
            // tables -- their own trigger (from each table's original
            // creation migration) is what actually rejects every UPDATE
            // unconditionally, not GRANT/RLS. The grant exists only because
            // GenericAssessmentResultStore/Outbox take a lockForUpdate() read
            // on their own table before inserting the next row, and
            // PostgreSQL requires UPDATE privilege for that locking read even
            // though no UPDATE statement ever follows it. See
            // test_append_only_tables_still_reject_update_via_their_own_trigger()
            // for the proof that the trigger, not the grant, is doing the
            // real work.
            $this->assertTableIsServiceOnly($table, ['SELECT', 'INSERT', 'UPDATE'], ['DELETE', 'TRUNCATE', 'TRIGGER', 'REFERENCES']);
            $this->assertPolicyNames($table, ["{$table}_service_select", "{$table}_service_insert", "{$table}_service_update"]);
        }
    }

    public function test_append_only_tables_still_reject_update_via_their_own_trigger(): void
    {
        [, $sourceId] = $this->fixtureThroughFullPipeline();

        // Exception caught OUTSIDE runAsService()/run(), not inside its
        // closure -- see the comment in
        // test_participant_context_cannot_read_or_write_any_of_the_four_tables_directly
        // for why: under this test class's outer DB::beginTransaction(),
        // run()'s own transaction() call is a SAVEPOINT, and only letting
        // the exception propagate out of it (so transaction()'s own catch
        // issues ROLLBACK TO SAVEPOINT before rethrowing) avoids a second,
        // masking 25P02 from trying to RELEASE an aborted savepoint.
        try {
            app(RlsContextRunner::class)->runAsService(
                fn () => DB::table('generic_assessment_result_versions')->where('id', $sourceId)->update(['iq' => 1.0]),
            );
            $this->fail('Expected the append-only trigger to reject the update.');
        } catch (QueryException $exception) {
            // Not a 42501 permission-denied -- the UPDATE grant lets the
            // statement reach the table; the trigger added by
            // 2026_09_05_000100_create_generic_assessment_result_versions.php
            // is what actually raises this exception.
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }
    }

    public function test_participant_context_cannot_read_or_write_any_of_the_four_tables_directly(): void
    {
        [, $sourceId, , $outboxId] = $this->fixtureThroughFullPipeline();
        // A dispatch_attempts row must actually exist before the UPDATE-denial
        // check below means anything -- an UPDATE whose WHERE clause matches
        // zero rows "succeeds" (0 rows affected) without ever consulting the
        // UPDATE policy, which would make the assertion pass for the wrong
        // reason. Claim a real attempt through the same production path the
        // orchestrator itself uses.
        app(RlsContextRunner::class)->runAsService(function () use ($sourceId, $outboxId): void {
            $checksum = (string) DB::table('generic_assessment_result_versions')->where('id', $sourceId)->value('result_checksum');
            app(GenericAssessmentResultDispatch::class)->claimExact($outboxId, $sourceId, 1, $checksum, str_repeat('lease-', 6));
        });

        app(RlsContextRunner::class)->run(new RlsContext('participant', 1, 1), function () {
            foreach (self::TABLES as $table) {
                $this->assertSame(0, DB::table($table)->count(), $table);
            }
        });

        // Each fallible statement gets its OWN run() call, and the SQLSTATE
        // assertion wraps the WHOLE run() call rather than nesting inside
        // its closure. setUp()'s own outer DB::beginTransaction() means
        // run()'s internal transaction() call is a SAVEPOINT, not a real
        // top-level transaction -- and unlike a top-level COMMIT (which
        // PostgreSQL silently converts to a ROLLBACK if the transaction is
        // aborted), RELEASE SAVEPOINT on an aborted savepoint raises its own
        // error (25P02) instead of recovering. Catching the query exception
        // INSIDE the closure would let run()'s transaction() wrapper think
        // the callback succeeded and try to RELEASE an already-poisoned
        // savepoint. Letting the exception propagate OUT of run() instead
        // means transaction()'s own catch block issues the correct ROLLBACK
        // TO SAVEPOINT before rethrowing, which is what the outer
        // assertSqlState() here actually catches.
        $this->assertSqlState('42501', fn () => app(RlsContextRunner::class)->run(
            new RlsContext('participant', 1, 1),
            fn () => DB::table('generic_assessment_result_versions')->insert([
                'id' => (string) Str::ulid(), 'assessment_participant_id' => 1, 'assessment_attempt_id' => (string) Str::ulid(),
                'result_version' => 1, 'iq' => 100.0, 'iq_canonical' => '100', 'engine_version' => 'x',
                'completed_at' => now(), 'finality' => 'FINALIZED', 'result_checksum' => str_repeat('a', 64), 'created_at' => now(),
            ]),
        ));
        // UPDATE is not like INSERT here: RLS's UPDATE policy is a USING
        // (visibility) check on EXISTING rows, not a WITH CHECK on a new
        // one -- a row that fails it is simply filtered out, same as for
        // SELECT, so the UPDATE affects 0 rows and returns normally with NO
        // exception (unlike the INSERT WITH CHECK failure just above, which
        // has no "hide it instead" option and genuinely raises 42501).
        // Proving denial here means asserting zero rows changed, then
        // confirming under a service context that the row's real value is
        // still what claimExact() set it to.
        $affected = app(RlsContextRunner::class)->run(
            new RlsContext('participant', 1, 1),
            fn () => DB::table('generic_assessment_result_dispatch_attempts')
                ->where('outbox_id', $outboxId)->update(['outcome' => 'ACKNOWLEDGED']),
        );
        $this->assertSame(0, $affected);
        $outcome = app(RlsContextRunner::class)->runAsService(
            fn () => DB::table('generic_assessment_result_dispatch_attempts')->where('outbox_id', $outboxId)->value('outcome'),
        );
        $this->assertSame('PROCESSING', $outcome);
    }

    public function test_the_real_command_orchestrator_and_job_path_writes_all_four_tables_under_postgres(): void
    {
        // The job is dispatched afterCommit (see the SQLite orchestration
        // test's own assertion of that). queue.default=sync would normally
        // run it inline once the dispatching DB::transaction() commits, but
        // this test (like every other test in this file) isolates itself
        // with an outer DB::beginTransaction()/rollBack() that never
        // actually commits -- so an afterCommit callback registered inside
        // it would never fire, and the job would silently never run. Queue::fake()
        // plus pulling the job and calling handle() directly sidesteps that
        // entirely, matching the SQLite suite's own
        // test_job_executes_exact_schedule_with_a_fresh_lease_and_terminal_outcomes_are_not_requeued.
        $queue = Queue::fake();
        Http::fake(['https://seleksi.beasiswajepang.id/*' => Http::response(['data' => ['status' => 'ACCEPTED']], 202)]);
        [, , , $outboxId] = $this->fixtureThroughFullPipeline();

        $exitCode = Artisan::call('integrations:dispatch-generic-result-callbacks', ['--limit' => 1]);
        $this->assertSame(Command::SUCCESS, $exitCode);

        $job = $queue->pushed(DispatchGenericAssessmentResultCallback::class)->first();
        $this->assertInstanceOf(DispatchGenericAssessmentResultCallback::class, $job);
        $job->handle(app(GenericAssessmentResultCallbackOrchestrator::class));

        $row = app(RlsContextRunner::class)->runAsService(fn () => DB::table('generic_assessment_result_dispatch_attempts')
            ->where('outbox_id', $outboxId)->first());
        $this->assertNotNull($row);
        $this->assertSame('ACKNOWLEDGED', $row->outcome);
        $schedule = app(RlsContextRunner::class)->runAsService(fn () => DB::table('generic_assessment_result_callback_schedules')
            ->where('outbox_id', $outboxId)->first());
        $this->assertNotNull($schedule);
        $this->assertSame('COMPLETED', $schedule->state);
    }

    public function test_migration_up_down_up_cycle_round_trips_cleanly(): void
    {
        $this->asOwner(function (): void {
            $migration = require database_path('migrations/2026_09_21_000500_harden_generic_assessment_result_pipeline_rls.php');

            DB::beginTransaction();
            try {
                $migration->down();
                foreach (self::TABLES as $table) {
                    $this->assertFalse(DB::selectOne(
                        "SELECT relrowsecurity FROM pg_class WHERE oid = '{$table}'::regclass",
                    )->relrowsecurity, $table);
                }
                $migration->up();
                foreach (self::TABLES as $table) {
                    $this->assertTrue(DB::selectOne(
                        "SELECT relforcerowsecurity FROM pg_class WHERE oid = '{$table}'::regclass",
                    )->relforcerowsecurity, $table);
                }
            } finally {
                DB::rollBack();
            }
        });
    }

    /**
     * @param  list<string>  $expectedGranted
     * @param  list<string>  $expectedDenied
     */
    private function assertTableIsServiceOnly(string $table, array $expectedGranted, array $expectedDenied): void
    {
        $identity = DB::selectOne(
            "SELECT relrowsecurity, relforcerowsecurity, pg_get_userbyid(relowner) AS table_owner FROM pg_class WHERE oid = '{$table}'::regclass",
        );
        $this->assertTrue($identity->relrowsecurity, $table);
        $this->assertTrue($identity->relforcerowsecurity, $table);
        $this->assertNotSame('psikotes_runtime', $identity->table_owner, $table);

        foreach ($expectedGranted as $privilege) {
            $this->assertTrue((bool) DB::scalar(
                "SELECT has_table_privilege('psikotes_runtime', ?, ?)",
                [$table, $privilege],
            ), "{$table}: {$privilege}");
        }
        foreach ($expectedDenied as $privilege) {
            $this->assertFalse((bool) DB::scalar(
                "SELECT has_table_privilege('psikotes_runtime', ?, ?)",
                [$table, $privilege],
            ), "{$table}: {$privilege}");
        }
    }

    /** @param list<string> $expected */
    private function assertPolicyNames(string $table, array $expected): void
    {
        $policies = DB::table('pg_policies')
            ->where('schemaname', 'public')->where('tablename', $table)
            ->orderBy('policyname')->get();
        sort($expected);
        $this->assertSame($expected, $policies->pluck('policyname')->all(), $table);
        foreach ($policies as $policy) {
            $this->assertSame('{psikotes_runtime}', $policy->roles, $table);
            $this->assertStringContainsString(
                "app_private.app_role() = 'service'",
                ($policy->qual ?? '').($policy->with_check ?? ''),
                $table,
            );
        }
    }

    /** @return array{AssessmentParticipant, string, string, string} */
    private function fixtureThroughFullPipeline(): array
    {
        return app(RlsContextRunner::class)->runAsService(function (): array {
            $assessment = $this->assessment();

            app(GenericAssessmentResultStore::class)->persistAuthorizedSnapshot([
                'assessmentAttemptId' => $assessment->assessment_attempt_id,
                'iq' => 99.125, 'engineVersion' => 'ist-2026.09.1',
                'completedAt' => '2026-09-21T09:00:00+00:00',
                'finality' => 'FINALIZED', 'revokedAt' => null, 'resultVersion' => 1,
            ]);
            $source = DB::table('generic_assessment_result_versions')
                ->where('assessment_participant_id', $assessment->id)->where('result_version', 1)->sole();

            $outbox = app(GenericAssessmentResultOutbox::class)->enqueueExact(
                $source->id, $assessment->assessment_attempt_id, 1, $source->result_checksum,
            );

            return [$assessment, (string) $source->id, $assessment->assessment_attempt_id, (string) $outbox['outboxId']];
        });
    }

    private function assessment(): AssessmentParticipant
    {
        $key = (string) Str::ulid();
        $organization = Branch::query()->create([
            'code' => $key, 'ref_code' => $key, 'name' => 'RLS pipeline synthetic',
            'organization_code' => $key, 'display_name' => 'RLS pipeline synthetic',
            'status' => 'ACTIVE', 'is_active' => true,
        ]);
        $participant = Participant::query()->create([
            'branch_id' => $organization->id, 'referral_branch_id' => $organization->id,
            'referral_source' => 'manual', 'source_system' => 'RLS_PIPELINE_TEST',
            'full_name' => 'RLS pipeline synthetic participant', 'phone' => '620000000000',
        ]);
        $client = IntegrationClient::query()->create([
            'organization_id' => $organization->id, 'client_id' => $key,
            'credential_reference' => 'rls-pipeline-test-only', 'enabled' => true,
            'result_delivery_mode' => 'CALLBACK_AND_POLL',
        ]);
        $package = TestPackage::query()->create([
            'code' => 'R'.$key, 'name' => 'RLS pipeline synthetic package',
            'amount' => 100, 'currency' => 'IDR', 'is_active' => true,
        ]);

        $assessmentAttemptId = (string) Str::ulid();
        $case = AssessmentCase::query()->create([
            'public_id' => $assessmentAttemptId,
            'participant_id' => $participant->id,
            'organization_id' => $organization->id,
            'package_id' => $package->id,
            'origin' => 'INTEGRATED',
            'intended_field_snapshot' => null,
        ]);

        return AssessmentParticipant::query()->create([
            'assessment_case_id' => $case->id,
            'organization_id' => $organization->id, 'integration_client_id' => $client->id,
            'participant_id' => $participant->id, 'package_id' => $package->id,
            'assessment_attempt_id' => $assessmentAttemptId, 'source_system' => 'RLS_PIPELINE_TEST',
            'external_candidate_id' => $key, 'funding_mode' => 'SPONSORED',
            'assessment_status' => 'UNDER_REVIEW', 'idempotency_key' => $key,
            'request_hash' => hash('sha256', $key),
            'logical_assessment_key' => hash('sha256', 'logical'.$key),
        ]);
    }

    private function assertSqlState(string $state, callable $operation): void
    {
        try {
            $operation();
            $this->fail("Expected SQLSTATE {$state}.");
        } catch (QueryException $exception) {
            $this->assertSame($state, $exception->errorInfo[0] ?? null, $exception->getMessage());
        }
    }

    private function asOwner(callable $callback): void
    {
        $runtime = DB::getDefaultConnection();
        $config = config('database.connections.'.$runtime);
        config()->set('database.connections.generic_result_pipeline_owner', [
            ...$config,
            'username' => 'org_test_owner',
        ]);
        DB::setDefaultConnection('generic_result_pipeline_owner');
        Schema::clearResolvedInstance('db.schema');

        try {
            $this->assertSame('org_test_owner', DB::selectOne('SELECT current_user AS name')->name);
            $callback();
        } finally {
            DB::setDefaultConnection($runtime);
            Schema::clearResolvedInstance('db.schema');
            DB::purge('generic_result_pipeline_owner');
            config()->set('database.connections.generic_result_pipeline_owner', null);
        }
    }
}
