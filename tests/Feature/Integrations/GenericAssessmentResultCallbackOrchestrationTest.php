<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Jobs\DispatchGenericAssessmentResultCallback;
use App\Models\AssessmentParticipant;
use App\Models\Branch;
use App\Models\GenericAssessmentResultVersion;
use App\Models\IntegrationClient;
use App\Models\Participant;
use App\Models\TestPackage;
use App\Services\Integrations\GenericAssessmentResultCallbackOrchestrator;
use App\Services\Integrations\GenericAssessmentResultDispatch;
use App\Services\Integrations\GenericAssessmentResultOutbox;
use App\Services\Integrations\GenericAssessmentResultStore;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

final class GenericAssessmentResultCallbackOrchestrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-09-05 09:00:00+00:00');
        config()->set('selection_integration.result_callback_enabled', true);
        config()->set('selection_integration.result_callback_base_url', 'https://seleksi.beasiswajepang.id');
        config()->set('selection_integration.result_callback_secret', 'psychotest-to-selection-secret-32-bytes-minimum');
        config()->set('selection_integration.client_secret', 'selection-to-psychotest-secret-is-distinct');
        Http::preventStrayRequests();
    }

    public function test_selector_is_bounded_and_queues_oldest_exact_latest_results_first(): void
    {
        Queue::fake();
        [, , $oldest] = $this->outbox();
        Date::setTestNow(Date::now()->addSecond());
        [, , $middle] = $this->outbox();
        Date::setTestNow(Date::now()->addSecond());
        $this->outbox();

        $result = app(GenericAssessmentResultCallbackOrchestrator::class)->schedule(2);

        $this->assertSame(['selected' => 2, 'queued' => 2, 'brokerFailures' => 0], $result);
        $jobs = Queue::pushed(DispatchGenericAssessmentResultCallback::class);
        $this->assertSame([$oldest, $middle], $jobs->map(fn (DispatchGenericAssessmentResultCallback $job): string => (string) DB::table('generic_assessment_result_callback_schedules')->where('id', $job->scheduleId)->value('outbox_id'))->all());
        $job = $jobs->first();
        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertInstanceOf(ShouldBeEncrypted::class, $job);
        $this->assertTrue($job->afterCommit);
        $this->assertSame($job->scheduleId, $job->uniqueId());
        $serialized = serialize($job);
        $this->assertStringNotContainsString('99.125', $serialized);
        $this->assertStringNotContainsString('Synthetic participant', $serialized);

        foreach ([0, 101] as $invalidLimit) {
            try {
                app(GenericAssessmentResultCallbackOrchestrator::class)->schedule($invalidLimit);
                $this->fail('An unbounded selector limit was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('ASSESSMENT_RESULT_CALLBACK_SCHEDULE_LIMIT_INVALID', $exception->getMessage());
            }
        }
    }

    public function test_selector_honors_attempt_state_latest_source_and_client_authorization(): void
    {
        Queue::fake();
        [, , $pending] = $this->outbox();
        [, $activeSource, $active] = $this->outbox();
        [, $dueSource, $due] = $this->outbox();
        [, $notDueSource, $notDue] = $this->outbox();
        [, $unknownSource, $unknown] = $this->outbox();
        [$staleAssessment, , $stale] = $this->outbox();
        [$disabledAssessment, , $disabled] = $this->outbox();
        $dispatch = app(GenericAssessmentResultDispatch::class);

        $dispatch->claimExact($active, $activeSource->id, 1, $activeSource->result_checksum, str_repeat('active-', 6));
        $this->complete($due, $dueSource, 'RETRYABLE', 'TRANSIENT_UNAVAILABLE');
        $this->complete($notDue, $notDueSource, 'RETRYABLE', 'RATE_LIMITED');
        $this->complete($unknown, $unknownSource, 'UNKNOWN', 'OUTCOME_UNCERTAIN');
        $this->persist($staleAssessment, 101.25, 2);
        IntegrationClient::query()->whereKey($disabledAssessment->integration_client_id)->update(['enabled' => false]);

        Date::setTestNow('2026-09-05 09:01:01+00:00');
        $result = app(GenericAssessmentResultCallbackOrchestrator::class)->schedule(100);

        $this->assertSame(2, $result['selected']);
        Queue::assertPushed(DispatchGenericAssessmentResultCallback::class, 2);
        $queued = Queue::pushed(DispatchGenericAssessmentResultCallback::class)
            ->map(fn (DispatchGenericAssessmentResultCallback $job): string => (string) DB::table('generic_assessment_result_callback_schedules')->where('id', $job->scheduleId)->value('outbox_id'))->all();
        $this->assertContains($pending, $queued);
        $this->assertContains($due, $queued);
        foreach ([$active, $notDue, $unknown, $stale, $disabled] as $excluded) {
            $this->assertNotContains($excluded, $queued);
        }
    }

    public function test_broker_failure_is_durable_not_sent_and_recoverable_after_bounded_lease(): void
    {
        [, , $outboxId] = $this->outbox();
        config()->set('queue.default', 'missing-broker');

        $failed = app(GenericAssessmentResultCallbackOrchestrator::class)->schedule(1);

        $this->assertSame(['selected' => 1, 'queued' => 0, 'brokerFailures' => 1], $failed);
        $schedule = DB::table('generic_assessment_result_callback_schedules')->sole();
        $this->assertSame('BROKER_FAILED', $schedule->state);
        $this->assertSame(1, (int) $schedule->broker_attempts);
        $this->assertDatabaseCount('generic_assessment_result_dispatch_attempts', 0);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'generic_assessment_result_callback.broker_failed',
            'subject_id' => $outboxId,
        ]);

        config()->set('queue.default', 'sync');
        Queue::fake();
        Date::setTestNow('2026-09-05 09:04:59+00:00');
        $this->assertSame(0, app(GenericAssessmentResultCallbackOrchestrator::class)->schedule(1)['selected']);
        Date::setTestNow('2026-09-05 09:05:00+00:00');
        $recovered = app(GenericAssessmentResultCallbackOrchestrator::class)->schedule(1);
        $this->assertSame(1, $recovered['queued']);
        Queue::assertPushed(DispatchGenericAssessmentResultCallback::class, 1);
        $this->assertDatabaseHas('generic_assessment_result_callback_schedules', [
            'outbox_id' => $outboxId, 'state' => 'QUEUED', 'broker_attempts' => 2,
        ]);
    }

    public function test_job_executes_exact_schedule_with_a_fresh_lease_and_terminal_outcomes_are_not_requeued(): void
    {
        Queue::fake();
        [, , $outboxId] = $this->outbox();
        app(GenericAssessmentResultCallbackOrchestrator::class)->schedule(1);
        /** @var DispatchGenericAssessmentResultCallback $job */
        $job = Queue::pushed(DispatchGenericAssessmentResultCallback::class)->first();
        Http::fake(['https://seleksi.beasiswajepang.id/*' => Http::response(['data' => ['status' => 'ACCEPTED']], 202)]);

        $job->handle(app(GenericAssessmentResultCallbackOrchestrator::class));

        $this->assertDatabaseHas('generic_assessment_result_dispatch_attempts', [
            'outbox_id' => $outboxId, 'attempt_number' => 1, 'outcome' => 'ACKNOWLEDGED',
        ]);
        $this->assertDatabaseHas('generic_assessment_result_callback_schedules', [
            'id' => $job->scheduleId, 'state' => 'COMPLETED',
        ]);
        Queue::fake();
        $this->assertSame(0, app(GenericAssessmentResultCallbackOrchestrator::class)->schedule(100)['selected']);
        Queue::assertNothingPushed();

        $audits = DB::table('audit_logs')->where('action', 'like', 'generic_assessment_result_callback.%')->get();
        $this->assertNotEmpty($audits);
        foreach ($audits as $audit) {
            $encoded = (string) $audit->context;
            $this->assertStringNotContainsString('99.125', $encoded);
            $this->assertStringNotContainsString('Synthetic participant', $encoded);
            $this->assertStringNotContainsString('psychotest-to-selection-secret', $encoded);
            $this->assertStringNotContainsString('seleksi.beasiswajepang.id', $encoded);
        }
    }

    public function test_retryable_job_is_requeued_only_when_due(): void
    {
        Queue::fake();
        [, , $retryOutbox] = $this->outbox();
        app(GenericAssessmentResultCallbackOrchestrator::class)->schedule(1);
        /** @var DispatchGenericAssessmentResultCallback $job */
        $job = Queue::pushed(DispatchGenericAssessmentResultCallback::class)->first();
        Http::fake(['https://seleksi.beasiswajepang.id/*' => Http::response([], 503)]);
        $job->handle(app(GenericAssessmentResultCallbackOrchestrator::class));

        Queue::fake();
        Date::setTestNow('2026-09-05 09:00:59+00:00');
        $job->handle(app(GenericAssessmentResultCallbackOrchestrator::class));
        $this->assertDatabaseHas('generic_assessment_result_callback_schedules', [
            'outbox_id' => $retryOutbox, 'state' => 'PENDING', 'broker_attempts' => 1,
        ]);
        $this->assertDatabaseCount('generic_assessment_result_dispatch_attempts', 1);
        Http::assertSentCount(1);
        $this->assertSame(0, app(GenericAssessmentResultCallbackOrchestrator::class)->schedule(1)['selected']);
        Date::setTestNow('2026-09-05 09:01:00+00:00');
        $this->assertSame(1, app(GenericAssessmentResultCallbackOrchestrator::class)->schedule(1)['queued']);
        Queue::assertPushed(DispatchGenericAssessmentResultCallback::class, 1);
        $this->assertDatabaseHas('generic_assessment_result_callback_schedules', [
            'outbox_id' => $retryOutbox, 'state' => 'QUEUED', 'broker_attempts' => 2,
        ]);

    }

    public function test_broker_failures_exhaust_safely_after_four_bounded_attempts(): void
    {
        [, , $failedOutbox] = $this->outbox();
        config()->set('queue.default', 'missing-broker');
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $result = app(GenericAssessmentResultCallbackOrchestrator::class)->schedule(1);
            $this->assertSame(1, $result['brokerFailures']);
            if ($attempt < 4) {
                Date::setTestNow(Date::now()->addSeconds(300));
            }
        }
        $this->assertDatabaseHas('generic_assessment_result_callback_schedules', [
            'outbox_id' => $failedOutbox, 'state' => 'BROKER_EXHAUSTED', 'broker_attempts' => 4,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'generic_assessment_result_callback.broker_exhausted',
            'subject_id' => $failedOutbox,
        ]);
        Date::setTestNow(Date::now()->addSeconds(300));
        $this->assertSame(0, app(GenericAssessmentResultCallbackOrchestrator::class)->schedule(1)['selected']);
    }

    public function test_accepted_but_lost_jobs_are_terminalized_after_four_recovery_leases_without_starvation(): void
    {
        Queue::fake();
        [, , $lostOutbox] = $this->outbox();
        app(GenericAssessmentResultCallbackOrchestrator::class)->schedule(1);
        for ($attempt = 2; $attempt <= 4; $attempt++) {
            Date::setTestNow(Date::now()->addSeconds(300));
            app(GenericAssessmentResultCallbackOrchestrator::class)->schedule(1);
        }
        [, , $laterOutbox] = $this->outbox();

        Date::setTestNow(Date::now()->addSeconds(300));
        app(GenericAssessmentResultCallbackOrchestrator::class)->schedule(1);
        $this->assertDatabaseHas('generic_assessment_result_callback_schedules', [
            'outbox_id' => $lostOutbox, 'state' => 'BROKER_EXHAUSTED', 'broker_attempts' => 4,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'generic_assessment_result_callback.broker_exhausted', 'subject_id' => $lostOutbox,
        ]);

        $next = app(GenericAssessmentResultCallbackOrchestrator::class)->schedule(1);
        $this->assertSame(1, $next['queued']);
        $this->assertDatabaseHas('generic_assessment_result_callback_schedules', [
            'outbox_id' => $laterOutbox, 'state' => 'QUEUED',
        ]);
    }

    public function test_database_guards_freeze_identity_and_enforce_the_schedule_transition_graph(): void
    {
        Queue::fake();
        [, , $outboxId] = $this->outbox();
        app(GenericAssessmentResultCallbackOrchestrator::class)->schedule(1);
        $schedule = DB::table('generic_assessment_result_callback_schedules')->sole();

        foreach ([
            fn () => DB::table('generic_assessment_result_callback_schedules')->where('id', $schedule->id)->update(['outbox_id' => (string) Str::ulid()]),
            fn () => DB::table('generic_assessment_result_callback_schedules')->where('id', $schedule->id)->update([
                'state' => 'COMPLETED', 'next_dispatch_at' => null, 'completed_at' => Date::now(),
            ]),
            fn () => DB::table('generic_assessment_result_callback_schedules')->where('id', $schedule->id)->update([
                'state' => 'BROKER_EXHAUSTED', 'next_dispatch_at' => null,
                'last_failure_code' => 'BROKER_DISPATCH_FAILED',
            ]),
            fn () => DB::table('generic_assessment_result_callback_schedules')->where('id', $schedule->id)->update([
                'state' => 'PENDING', 'broker_attempts' => 5,
            ]),
            fn () => DB::table('generic_assessment_result_callback_schedules')->where('id', $schedule->id)->delete(),
        ] as $invalidWrite) {
            try {
                DB::transaction($invalidWrite);
                $this->fail('An invalid direct schedule transition was accepted.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertDatabaseHas('generic_assessment_result_callback_schedules', [
            'id' => $schedule->id, 'outbox_id' => $outboxId, 'state' => 'QUEUED',
        ]);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    /** @return array{AssessmentParticipant,GenericAssessmentResultVersion,string} */
    private function outbox(): array
    {
        $assessment = $this->assessment();
        $source = $this->persist($assessment, 99.125, 1);
        $outbox = app(GenericAssessmentResultOutbox::class)->enqueueExact(
            $source->id, $assessment->assessment_attempt_id, 1, $source->result_checksum,
        );

        return [$assessment, $source, (string) $outbox['outboxId']];
    }

    private function complete(string $outboxId, GenericAssessmentResultVersion $source, string $outcome, string $reason): void
    {
        $token = 'outcome-'.$outcome.'-'.$outboxId;
        $claim = app(GenericAssessmentResultDispatch::class)->claimExact(
            $outboxId, $source->id, 1, $source->result_checksum, $token,
        );
        Date::setTestNow(Date::now()->addSecond());
        app(GenericAssessmentResultDispatch::class)->completeExact(
            $outboxId, $source->id, 1, $source->result_checksum,
            (string) $claim['attemptId'], $token, $outcome, $reason,
        );
    }

    private function persist(AssessmentParticipant $assessment, int|float $iq, int $version): GenericAssessmentResultVersion
    {
        app(GenericAssessmentResultStore::class)->persistAuthorizedSnapshot([
            'assessmentAttemptId' => $assessment->assessment_attempt_id,
            'iq' => $iq,
            'engineVersion' => 'ist-2026.09.1',
            'completedAt' => '2026-09-05T10:15:30.123456+07:00',
            'finality' => 'FINALIZED', 'revokedAt' => null, 'resultVersion' => $version,
        ]);

        return GenericAssessmentResultVersion::query()
            ->where('assessment_participant_id', $assessment->id)
            ->where('result_version', $version)->sole();
    }

    private function assessment(): AssessmentParticipant
    {
        $key = (string) Str::ulid();
        $organization = Branch::query()->create([
            'code' => $key, 'ref_code' => $key, 'name' => 'Synthetic result organization',
            'organization_code' => $key, 'display_name' => 'Synthetic result organization',
            'status' => 'ACTIVE', 'is_active' => true,
        ]);
        $participant = Participant::query()->create([
            'branch_id' => $organization->id, 'referral_branch_id' => $organization->id,
            'referral_source' => 'manual', 'source_system' => 'CALLBACK_ORCHESTRATION_TEST',
            'full_name' => 'Synthetic participant', 'phone' => '620000000000',
        ]);
        $client = IntegrationClient::query()->create([
            'organization_id' => $organization->id, 'client_id' => $key,
            'credential_reference' => 'orchestration-test-only', 'enabled' => true,
            'result_delivery_mode' => 'CALLBACK_AND_POLL',
        ]);
        $package = TestPackage::query()->create([
            'code' => 'R'.$key, 'name' => 'Synthetic result package',
            'amount' => 100, 'currency' => 'IDR', 'is_active' => true,
        ]);

        return AssessmentParticipant::query()->create([
            'organization_id' => $organization->id, 'integration_client_id' => $client->id,
            'participant_id' => $participant->id, 'package_id' => $package->id,
            'assessment_attempt_id' => (string) Str::ulid(), 'source_system' => 'CALLBACK_ORCHESTRATION_TEST',
            'external_candidate_id' => $key, 'funding_mode' => 'SPONSORED',
            'assessment_status' => 'UNDER_REVIEW', 'idempotency_key' => $key,
            'request_hash' => hash('sha256', $key),
            'logical_assessment_key' => hash('sha256', 'logical'.$key),
        ]);
    }
}
