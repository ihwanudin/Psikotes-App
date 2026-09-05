<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Models\AssessmentParticipant;
use App\Models\Branch;
use App\Models\GenericAssessmentResultVersion;
use App\Models\IntegrationClient;
use App\Models\Participant;
use App\Models\TestPackage;
use App\Services\Integrations\GenericAssessmentResultDispatch;
use App\Services\Integrations\GenericAssessmentResultOutbox;
use App\Services\Integrations\GenericAssessmentResultStore;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

final class GenericAssessmentResultDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-09-05 05:00:00+00:00');
    }

    public function test_claim_creates_a_bounded_processing_attempt_and_safe_audit(): void
    {
        [$assessment, $source, $outboxId] = $this->outbox();
        $token = str_repeat('claim-a-', 6);

        $claim = app(GenericAssessmentResultDispatch::class)->claimExact(
            $outboxId, $source->id, 1, $source->result_checksum, $token,
        );

        $this->assertSame('CLAIMED', $claim['action']);
        $this->assertSame(1, $claim['attemptNumber']);
        $this->assertSame('2026-09-05T05:05:00.000000Z', $claim['leaseExpiresAt']);
        $attempt = DB::table('generic_assessment_result_dispatch_attempts')->sole();
        $this->assertSame(hash('sha256', $token), $attempt->lease_token_hash);
        $this->assertSame('CALLBACK_AND_POLL', $attempt->mode);
        $this->assertSame('PROCESSING', $attempt->outcome);
        $this->assertFalse(property_exists($attempt, 'iq'));

        $audit = DB::table('audit_logs')->where('action', 'generic_assessment_result_dispatch.claimed')->sole();
        $context = json_decode((string) $audit->context, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(hash('sha256', $assessment->assessment_attempt_id), $context['assessmentAttemptReference']);
        $this->assertSame(1, $context['resultVersion']);
        $this->assertSame($source->result_checksum, $context['resultChecksum']);
        $this->assertSame('FINALIZED', $context['finality']);
        $this->assertFalse($context['isRevoked']);
        $this->assertSame(1, $context['attemptNumber']);
        $encoded = json_encode([$attempt, $audit], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($assessment->assessment_attempt_id, (string) $audit->context);
        $this->assertStringNotContainsString($token, $encoded);
        $this->assertStringNotContainsString('99.125', $encoded);
        $this->assertStringNotContainsString('Synthetic participant', $encoded);
    }

    public function test_same_token_replays_before_expiry_and_takeover_requires_a_new_token_after_expiry(): void
    {
        [, $source, $outboxId] = $this->outbox();
        $dispatch = app(GenericAssessmentResultDispatch::class);
        $firstToken = str_repeat('first-token-', 3);
        $secondToken = str_repeat('second-token-', 3);
        $first = $dispatch->claimExact($outboxId, $source->id, 1, $source->result_checksum, $firstToken);

        Date::setTestNow('2026-09-05 05:04:59+00:00');
        $replay = $dispatch->claimExact($outboxId, $source->id, 1, $source->result_checksum, $firstToken);
        $active = $dispatch->claimExact($outboxId, $source->id, 1, $source->result_checksum, $secondToken);
        $this->assertSame('REPLAYED', $replay['action']);
        $this->assertSame($first['attemptId'], $replay['attemptId']);
        $this->assertSame('SKIPPED_LEASE_ACTIVE', $active['action']);

        Date::setTestNow('2026-09-05 05:05:00+00:00');
        $expiredToken = $dispatch->claimExact($outboxId, $source->id, 1, $source->result_checksum, $firstToken);
        $takeover = $dispatch->claimExact($outboxId, $source->id, 1, $source->result_checksum, $secondToken);
        $this->assertSame('SKIPPED_EXPIRED_TOKEN', $expiredToken['action']);
        $this->assertSame('TAKEN_OVER', $takeover['action']);
        $this->assertSame(2, $takeover['attemptNumber']);
        $this->assertDatabaseCount('generic_assessment_result_dispatch_attempts', 2);
        $this->assertSame([
            'generic_assessment_result_dispatch.claimed',
            'generic_assessment_result_dispatch.replayed',
            'generic_assessment_result_dispatch.skipped',
            'generic_assessment_result_dispatch.skipped',
            'generic_assessment_result_dispatch.taken_over',
        ], DB::table('audit_logs')->where('action', 'like', 'generic_assessment_result_dispatch.%')->orderBy('id')->pluck('action')->all());
    }

    public function test_completion_is_idempotent_and_terminal_outcomes_are_not_claimed_again(): void
    {
        [, $source, $outboxId] = $this->outbox();
        $dispatch = app(GenericAssessmentResultDispatch::class);
        $token = str_repeat('complete-token-', 3);
        $claim = $dispatch->claimExact($outboxId, $source->id, 1, $source->result_checksum, $token);

        Date::setTestNow('2026-09-05 05:01:00+00:00');
        $completed = $dispatch->completeExact(
            $outboxId, $source->id, 1, $source->result_checksum,
            $claim['attemptId'], $token, 'ACKNOWLEDGED', null,
        );
        $replay = $dispatch->completeExact(
            $outboxId, $source->id, 1, $source->result_checksum,
            $claim['attemptId'], $token, 'ACKNOWLEDGED', null,
        );
        $skipped = $dispatch->claimExact(
            $outboxId, $source->id, 1, $source->result_checksum, str_repeat('new-token-', 4),
        );

        $this->assertSame('COMPLETED', $completed['action']);
        $this->assertSame('ACKNOWLEDGED', $completed['outcome']);
        $this->assertSame('REPLAYED', $replay['action']);
        $this->assertSame('SKIPPED_TERMINAL', $skipped['action']);
        $this->assertDatabaseCount('generic_assessment_result_dispatch_attempts', 1);
        $this->assertDatabaseHas('generic_assessment_result_dispatch_attempts', [
            'id' => $claim['attemptId'],
            'outcome' => 'ACKNOWLEDGED',
            'next_attempt_at' => null,
            'reason_code' => null,
        ]);
    }

    public function test_retryable_attempts_use_internal_backoff_and_stop_at_the_maximum(): void
    {
        [, $source, $outboxId] = $this->outbox();
        $dispatch = new GenericAssessmentResultDispatch(leaseSeconds: 60, maxAttempts: 2, backoffSeconds: [30]);
        $firstToken = str_repeat('retry-one-', 4);
        $first = $dispatch->claimExact($outboxId, $source->id, 1, $source->result_checksum, $firstToken);
        Date::setTestNow('2026-09-05 05:00:10+00:00');
        $retry = $dispatch->completeExact(
            $outboxId, $source->id, 1, $source->result_checksum,
            $first['attemptId'], $firstToken, 'RETRYABLE', 'TRANSIENT_UNAVAILABLE',
        );
        $this->assertSame('2026-09-05T05:00:40.000000Z', $retry['nextAttemptAt']);

        $notDue = $dispatch->claimExact(
            $outboxId, $source->id, 1, $source->result_checksum, str_repeat('retry-two-', 4),
        );
        $this->assertSame('SKIPPED_NOT_DUE', $notDue['action']);

        Date::setTestNow('2026-09-05 05:00:40+00:00');
        $secondToken = str_repeat('retry-two-', 4);
        $second = $dispatch->claimExact($outboxId, $source->id, 1, $source->result_checksum, $secondToken);
        Date::setTestNow('2026-09-05 05:00:50+00:00');
        $exhausted = $dispatch->completeExact(
            $outboxId, $source->id, 1, $source->result_checksum,
            $second['attemptId'], $secondToken, 'RETRYABLE', 'TRANSIENT_UNAVAILABLE',
        );
        $this->assertNull($exhausted['nextAttemptAt']);
        $this->assertSame('SKIPPED_EXHAUSTED', $dispatch->claimExact(
            $outboxId, $source->id, 1, $source->result_checksum, str_repeat('retry-three-', 3),
        )['action']);
        $this->assertDatabaseCount('generic_assessment_result_dispatch_attempts', 2);
    }

    public function test_unknown_and_permanent_are_durable_terminal_outcomes(): void
    {
        foreach (['UNKNOWN', 'PERMANENT'] as $outcome) {
            [, $source, $outboxId] = $this->outbox();
            $dispatch = app(GenericAssessmentResultDispatch::class);
            $token = str_repeat(strtolower($outcome).'-token-', 3);
            $claim = $dispatch->claimExact($outboxId, $source->id, 1, $source->result_checksum, $token);
            Date::setTestNow(Date::now()->addSecond());
            $dispatch->completeExact(
                $outboxId, $source->id, 1, $source->result_checksum,
                $claim['attemptId'], $token, $outcome, $outcome === 'UNKNOWN' ? 'OUTCOME_UNCERTAIN' : 'REMOTE_REJECTED',
            );

            $skipped = $dispatch->claimExact(
                $outboxId, $source->id, 1, $source->result_checksum, str_repeat('later-token-', 4),
            );
            $this->assertSame('SKIPPED_TERMINAL', $skipped['action']);
            $this->assertDatabaseHas('generic_assessment_result_dispatch_attempts', [
                'id' => $claim['attemptId'],
                'outcome' => $outcome,
            ]);
            if ($outcome === 'UNKNOWN') {
                $audit = DB::table('audit_logs')
                    ->where('action', 'generic_assessment_result_dispatch.skipped')
                    ->orderByDesc('id')->first();
                $this->assertNotNull($audit);
                $this->assertStringContainsString('TERMINAL_OUTCOME', (string) $audit->context);
            }
        }
    }

    public function test_expired_or_mismatched_completion_fails_closed_with_safe_durable_audit(): void
    {
        [$assessment, $source, $outboxId] = $this->outbox();
        $dispatch = new GenericAssessmentResultDispatch(leaseSeconds: 30);
        $token = str_repeat('expiry-token-', 3);
        $claim = $dispatch->claimExact($outboxId, $source->id, 1, $source->result_checksum, $token);
        Date::setTestNow('2026-09-05 05:00:30+00:00');

        try {
            $dispatch->completeExact(
                $outboxId, $source->id, 1, $source->result_checksum,
                $claim['attemptId'], $token, 'ACKNOWLEDGED', null,
            );
            $this->fail('Completion at lease expiry was accepted.');
        } catch (LogicException $exception) {
            $this->assertSame('ASSESSMENT_RESULT_DISPATCH_LEASE_EXPIRED', $exception->getMessage());
        }

        $this->assertDatabaseHas('generic_assessment_result_dispatch_attempts', [
            'id' => $claim['attemptId'],
            'outcome' => 'PROCESSING',
        ]);
        $audit = DB::table('audit_logs')->where('action', 'generic_assessment_result_dispatch.failed')->sole();
        $encoded = (string) $audit->context;
        $this->assertStringContainsString(hash('sha256', $assessment->assessment_attempt_id), $encoded);
        $this->assertStringContainsString('LEASE_EXPIRED', $encoded);
        $this->assertStringNotContainsString($assessment->assessment_attempt_id, $encoded);
        $this->assertStringNotContainsString($token, $encoded);
    }

    public function test_completion_rejects_unbounded_reason_text_without_logging_it(): void
    {
        [, $source, $outboxId] = $this->outbox();
        $dispatch = app(GenericAssessmentResultDispatch::class);
        $token = str_repeat('reason-token-', 3);
        $claim = $dispatch->claimExact($outboxId, $source->id, 1, $source->result_checksum, $token);
        $unsafeReason = 'CONTACT_620000000000';

        try {
            $dispatch->completeExact(
                $outboxId, $source->id, 1, $source->result_checksum,
                $claim['attemptId'], $token, 'RETRYABLE', $unsafeReason,
            );
            $this->fail('An unbounded reason code was accepted.');
        } catch (LogicException $exception) {
            $this->assertSame('ASSESSMENT_RESULT_DISPATCH_COMPLETION_INVALID', $exception->getMessage());
        }

        $this->assertDatabaseHas('generic_assessment_result_dispatch_attempts', [
            'id' => $claim['attemptId'], 'outcome' => 'PROCESSING',
        ]);
        $audit = DB::table('audit_logs')->where('action', 'generic_assessment_result_dispatch.failed')->sole();
        $this->assertStringNotContainsString($unsafeReason, (string) $audit->context);
    }

    public function test_database_guards_freeze_identity_and_reject_invalid_transitions_or_deletes(): void
    {
        [, $source, $outboxId] = $this->outbox();
        $dispatch = app(GenericAssessmentResultDispatch::class);
        $token = str_repeat('guard-token-', 4);
        $claim = $dispatch->claimExact($outboxId, $source->id, 1, $source->result_checksum, $token);

        foreach ([
            fn () => DB::table('generic_assessment_result_dispatch_attempts')->where('id', $claim['attemptId'])->update(['attempt_number' => 2]),
            fn () => DB::table('generic_assessment_result_dispatch_attempts')->where('id', $claim['attemptId'])->update([
                'outcome' => 'ACKNOWLEDGED',
                'completed_at' => '2026-09-05 05:05:00',
            ]),
            fn () => DB::table('generic_assessment_result_dispatch_attempts')->where('id', $claim['attemptId'])->update([
                'outcome' => 'RETRYABLE',
                'completed_at' => '2026-09-05 05:01:00',
                'reason_code' => 'CONTACT_620000000000',
            ]),
            fn () => DB::table('generic_assessment_result_dispatch_attempts')->where('id', $claim['attemptId'])->update([
                'outcome' => 'RETRYABLE',
                'completed_at' => '2026-09-05 05:01:00',
                'reason_code' => null,
            ]),
            fn () => DB::table('generic_assessment_result_dispatch_attempts')->where('id', $claim['attemptId'])->delete(),
        ] as $write) {
            try {
                DB::transaction($write);
                $this->fail('Invalid direct dispatch write was accepted.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertDatabaseHas('generic_assessment_result_dispatch_attempts', [
            'id' => $claim['attemptId'],
            'attempt_number' => 1,
            'outcome' => 'PROCESSING',
        ]);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    public function test_audit_failures_rollback_claim_and_completion_atomically(): void
    {
        [, $source, $outboxId] = $this->outbox();
        $dispatch = app(GenericAssessmentResultDispatch::class);
        DB::unprepared("CREATE TRIGGER reject_dispatch_claim_audit BEFORE INSERT ON audit_logs
            WHEN NEW.action = 'generic_assessment_result_dispatch.claimed'
            BEGIN SELECT RAISE(ABORT, 'synthetic dispatch claim audit failure'); END");

        try {
            $dispatch->claimExact(
                $outboxId, $source->id, 1, $source->result_checksum, str_repeat('audit-claim-', 3),
            );
            $this->fail('Claim without its audit was committed.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('synthetic dispatch claim audit failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('generic_assessment_result_dispatch_attempts', 0);

        DB::unprepared('DROP TRIGGER reject_dispatch_claim_audit');
        $token = str_repeat('audit-complete-', 3);
        $claim = $dispatch->claimExact($outboxId, $source->id, 1, $source->result_checksum, $token);
        DB::unprepared("CREATE TRIGGER reject_dispatch_complete_audit BEFORE INSERT ON audit_logs
            WHEN NEW.action = 'generic_assessment_result_dispatch.completed'
            BEGIN SELECT RAISE(ABORT, 'synthetic dispatch completion audit failure'); END");
        Date::setTestNow('2026-09-05 05:01:00+00:00');

        try {
            $dispatch->completeExact(
                $outboxId, $source->id, 1, $source->result_checksum,
                $claim['attemptId'], $token, 'ACKNOWLEDGED', null,
            );
            $this->fail('Completion without its audit was committed.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('synthetic dispatch completion audit failure', $exception->getMessage());
        }
        $this->assertDatabaseHas('generic_assessment_result_dispatch_attempts', [
            'id' => $claim['attemptId'], 'outcome' => 'PROCESSING',
        ]);
    }

    /** @return array{AssessmentParticipant, GenericAssessmentResultVersion, string} */
    private function outbox(): array
    {
        $assessment = $this->assessment();
        app(GenericAssessmentResultStore::class)->persistAuthorizedSnapshot([
            'assessmentAttemptId' => $assessment->assessment_attempt_id,
            'iq' => 99.125,
            'engineVersion' => 'ist-2026.09.1',
            'completedAt' => '2026-09-05T10:15:30.123456+07:00',
            'finality' => 'FINALIZED',
            'revokedAt' => null,
            'resultVersion' => 1,
        ]);
        $source = GenericAssessmentResultVersion::query()->where('assessment_participant_id', $assessment->id)->sole();
        $outbox = app(GenericAssessmentResultOutbox::class)->enqueueExact(
            $source->id, $assessment->assessment_attempt_id, 1, $source->result_checksum,
        );

        return [$assessment, $source, (string) $outbox['outboxId']];
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
            'referral_source' => 'manual', 'source_system' => 'RESULT_TEST',
            'full_name' => 'Synthetic participant', 'phone' => '620000000000',
        ]);
        $client = IntegrationClient::query()->create([
            'organization_id' => $organization->id, 'client_id' => $key,
            'credential_reference' => 'synthetic-only', 'enabled' => true,
        ]);
        $package = TestPackage::query()->create([
            'code' => 'R'.$key, 'name' => 'Synthetic result package',
            'amount' => 100, 'currency' => 'IDR', 'is_active' => true,
        ]);

        return AssessmentParticipant::query()->create([
            'organization_id' => $organization->id, 'integration_client_id' => $client->id,
            'participant_id' => $participant->id, 'package_id' => $package->id,
            'assessment_attempt_id' => (string) Str::ulid(), 'source_system' => 'RESULT_TEST',
            'external_candidate_id' => $key, 'funding_mode' => 'SPONSORED',
            'assessment_status' => 'UNDER_REVIEW', 'idempotency_key' => $key,
            'request_hash' => hash('sha256', $key),
            'logical_assessment_key' => hash('sha256', 'logical'.$key),
        ]);
    }
}
