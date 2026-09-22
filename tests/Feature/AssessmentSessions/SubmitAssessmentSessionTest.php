<?php

declare(strict_types=1);

namespace Tests\Feature\AssessmentSessions;

use App\Actions\AssessmentResults\ScoreAssessmentSession;
use App\Actions\AssessmentSessions\SubmitAssessmentSession;
use App\Domain\AssessmentSessions\AssessmentSessionSubmitPolicy;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Security\RlsContextRunner;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\OrganizationPaymentTestCase;

final class SubmitAssessmentSessionTest extends OrganizationPaymentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $command = $this->artisan('migrate', ['--force' => true]);
        if (is_int($command)) {
            $this->fail('The migration command did not return a test command wrapper.');
        }
        $command->assertExitCode(0);
    }

    public function test_submit_at_exact_deadline_seals_owned_session_with_server_timestamp(): void
    {
        $participant = $this->participant();
        // ADR-0032 (2026-09-22): kraepelin, deliberately -- this test is
        // about submit's own status/timestamp mechanics, not scoring, and
        // kraepelin is the one supported test_type ScoreAssessmentSession
        // skips (its own pipeline, out of ADR-0032's scope), so it needs no
        // session_definition/answers fixture to reach the accepted branch.
        $session = $this->sessionPublicId($participant, 'in_progress', 7, 'kraepelin');
        $runner = $this->app->make(RlsContextRunner::class);
        $role = null;
        $transaction = 0;
        $result = $this->action(function () use ($runner, &$role, &$transaction): DateTimeImmutable {
            $role = $runner->current()?->role;
            $transaction = DB::transactionLevel();

            return new DateTimeImmutable('2026-09-08T04:00:00.000000+07:00');
        })->execute($participant, $session);

        $this->assertTrue($result->accepted);
        $this->assertFalse($result->replayed);
        $this->assertNull($result->errorCode);
        $this->assertSame('service', $role);
        $this->assertGreaterThan(0, $transaction);
        $this->assertSame($session, $result->sessionId);
        $this->assertSame('submitted', $result->status);
        $this->assertSame(7, $result->answersRevision);
        $this->assertSame('2026-09-07 21:00:00.000000+00:00', $result->submittedAt?->format('Y-m-d H:i:s.uP'));
        $this->assertDatabaseHas('test_sessions', [
            'public_id' => $session, 'status' => 'submitted', 'answers_revision' => 7,
            'submitted_at' => '2026-09-08 04:00:00.000000+07:00',
        ]);
        $this->assertDatabaseMissing('test_sessions', ['public_id' => $session, 'status' => 'scored']);
    }

    public function test_submit_one_microsecond_late_seals_expired_without_submission(): void
    {
        $participant = $this->participant();
        $session = $this->sessionPublicId($participant, 'in_progress', 3);
        $result = $this->action(fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-08T04:00:00.000001+07:00'))
            ->execute($participant, $session);

        $this->assertFalse($result->accepted);
        $this->assertFalse($result->replayed);
        $this->assertSame('DEADLINE_EXCEEDED', $result->errorCode);
        $this->assertSame('expired', $result->status);
        $this->assertNull($result->submittedAt);
        $this->assertDatabaseHas('test_sessions', [
            'public_id' => $session, 'status' => 'expired', 'answers_revision' => 3,
            'submitted_at' => null, 'expired_at' => '2026-09-08 04:00:00.000001+07:00',
        ]);
    }

    public function test_submitted_and_scored_replay_exact_state_without_timestamp_drift(): void
    {
        foreach (['submitted', 'scored'] as $status) {
            $participant = $this->participant();
            $session = $this->sessionPublicId($participant, $status, 5);
            $before = DB::table('test_sessions')->where('public_id', $session)->first();
            $result = $this->action(fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-09T12:00:00+07:00'))
                ->execute($participant, $session);
            $after = DB::table('test_sessions')->where('public_id', $session)->first();

            $this->assertTrue($result->accepted);
            $this->assertTrue($result->replayed);
            $this->assertSame($status, $result->status);
            $this->assertSame('2026-09-07 20:59:00.654321+00:00', $result->submittedAt?->format('Y-m-d H:i:s.uP'));
            $this->assertSame($before->submitted_at, $after->submitted_at);
            $this->assertSame($before->updated_at, $after->updated_at);
        }
    }

    public function test_created_expired_and_void_states_fail_closed(): void
    {
        foreach ([
            ['created', 'SESSION_NOT_STARTED'],
            ['expired', 'SESSION_CLOSED'],
            ['void', 'SESSION_CLOSED'],
        ] as [$status, $code]) {
            $participant = $this->participant();
            $session = $this->sessionPublicId($participant, $status);
            $result = $this->action(fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-08T03:30:00+07:00'))
                ->execute($participant, $session);

            $this->assertFalse($result->accepted);
            $this->assertSame($code, $result->errorCode);
            $this->assertSame($status, $result->status);
        }
    }

    public function test_cross_participant_missing_and_dass_sessions_do_not_leak(): void
    {
        $owner = $this->participant();
        $foreign = $this->participant();
        $session = $this->sessionPublicId($owner, 'in_progress');
        $action = $this->action(fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-08T03:30:00+07:00'));

        foreach ([[$foreign, $session], [0, $session], [$owner, 'invalid'], [$owner, (string) Str::ulid()]] as [$participant, $publicId]) {
            $result = $action->execute($participant, $publicId);
            $this->assertFalse($result->accepted);
            $this->assertSame('SESSION_NOT_FOUND', $result->errorCode);
        }

        DB::unprepared('DROP TRIGGER test_sessions_contract_insert');
        $dass = $this->sessionPublicId($foreign, 'in_progress', 0, 'dass21');
        $result = $action->execute($foreign, $dass);
        $this->assertFalse($result->accepted);
        $this->assertSame('SESSION_NOT_FOUND', $result->errorCode);
        $this->assertDatabaseHas('test_sessions', ['public_id' => $dass, 'status' => 'in_progress']);
    }

    public function test_persistence_failure_rolls_back_without_partial_seal(): void
    {
        $participant = $this->participant();
        $session = $this->sessionPublicId($participant, 'in_progress', 4);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER reject_submit_for_rollback
            BEFORE UPDATE ON test_sessions
            WHEN NEW.status = 'submitted'
            BEGIN SELECT RAISE(ABORT, 'synthetic submit persistence failure'); END
            SQL);

        try {
            $this->action(fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-08T03:30:00+07:00'))
                ->execute($participant, $session);
            $this->fail('The synthetic persistence failure should escape the action.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseHas('test_sessions', [
            'public_id' => $session, 'status' => 'in_progress',
            'submitted_at' => null, 'answers_revision' => 4,
        ]);
    }

    public function test_an_incomplete_papi_submit_still_commits_with_a_failed_to_score_attempt_row(): void
    {
        // ADR-0032 §1(a)/§3 -- the test explicitly required before undrafting:
        // an incomplete submit under PAPI's still-strict all-or-nothing
        // policy must NOT roll back the submit. status='submitted' and one
        // failed_to_score audit row must both land, atomically, so the
        // participant is never stuck retrying while their timer runs out.
        $participant = $this->participant();
        $key = (string) Str::ulid();
        $definitionSource = [
            'instrument' => 'papi', 'version' => 'synthetic-v1', 'provenance' => 'synthetic-test-only',
            'total_duration_seconds' => 3600,
            'subtests' => [
                ['code' => 'A', 'duration_seconds' => 1200, 'item_count' => 1],
                ['code' => 'B', 'duration_seconds' => 2400, 'item_count' => 2],
            ],
            'randomization' => 'fixed', 'seed' => null, 'generator' => null,
        ];
        $definition = [...$definitionSource, 'checksum' => SessionDefinition::checksumFor($definitionSource)];
        $case = DB::table('assessment_cases')->insertGetId([
            'public_id' => (string) Str::ulid(), 'participant_id' => $participant,
            'organization_id' => DB::table('participants')->where('id', $participant)->value('branch_id'),
            'package_id' => null, 'origin' => 'DIRECT_PUBLIC',
            'created_at' => '2026-09-08 03:00:00.000000+07:00', 'updated_at' => '2026-09-08 03:00:00.000000+07:00',
        ]);
        $sessionId = DB::table('test_sessions')->insertGetId([
            'public_id' => (string) Str::ulid(), 'participant_id' => $participant,
            'assessment_case_id' => $case, 'test_type' => 'papi',
            'attempt_no' => 1, 'authorization_id' => 'synthetic-auth-'.$key,
            'allocation_intent_id' => 'synthetic-allocation-'.$key, 'duration_seconds' => 3600,
            'status' => 'in_progress', 'answers_revision' => 0,
            'started_at' => '2026-09-08 03:00:00.000000+07:00', 'ends_at' => '2026-09-08 04:00:00.000000+07:00',
            'session_definition_version' => $definition['version'],
            'session_definition_provenance' => $definition['provenance'],
            'session_definition_checksum' => $definition['checksum'],
            'session_definition_payload' => json_encode($definition, JSON_THROW_ON_ERROR),
        ]);
        $publicId = (string) DB::table('test_sessions')->where('id', $sessionId)->value('public_id');
        // Only 2 of the 3 defined items answered -- incomplete under PAPI's policy.
        DB::table('assessment_autosave_mutations')->insert([
            'session_id' => $sessionId, 'mutation_id' => (string) Str::ulid(), 'revision' => 1,
            'request_hash' => hash('sha256', $key), 'accepted_item_numbers' => json_encode([1, 2], JSON_THROW_ON_ERROR),
            'received_at' => '2026-09-08 03:10:00.000000+07:00', 'created_at' => '2026-09-08 03:10:00.000000+07:00',
        ]);
        foreach ([1, 2] as $itemNo) {
            DB::table('answers')->insert([
                'session_id' => $sessionId, 'item_no' => $itemNo, 'value' => json_encode('A', JSON_THROW_ON_ERROR),
                'revision' => 1, 'answered_at' => '2026-09-08 03:10:00.123456+07:00',
                'created_at' => '2026-09-08 03:10:00.123456+07:00', 'updated_at' => '2026-09-08 03:10:00.123456+07:00',
            ]);
        }
        DB::table('test_sessions')->where('id', $sessionId)->update(['answers_revision' => 1]);

        $result = $this->action(fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-08T03:30:00+07:00'))
            ->execute($participant, $publicId);

        $this->assertTrue($result->accepted);
        $this->assertSame('submitted', $result->status);
        $this->assertDatabaseHas('test_sessions', ['id' => $sessionId, 'status' => 'submitted']);
        $this->assertDatabaseMissing('generic_instrument_results', ['session_id' => $sessionId]);
        $attempt = DB::table('assessment_scoring_attempts')->where('session_id', $sessionId)->sole();
        $this->assertSame('failed_to_score', $attempt->outcome);
        $this->assertSame('INCOMPLETE_ANSWERS', $attempt->reason_code);
        $this->assertNull($attempt->result_public_id);
        $this->assertSame('papi', $attempt->instrument_code);
    }

    private function action(callable $clock): SubmitAssessmentSession
    {
        return new SubmitAssessmentSession(
            $this->app->make(RlsContextRunner::class),
            new AssessmentSessionSubmitPolicy,
            $this->app->make(ScoreAssessmentSession::class),
            $clock(...),
        );
    }

    private function participant(): int
    {
        $key = (string) Str::ulid();
        $branch = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => 'Synthetic',
            'organization_code' => $key, 'display_name' => 'Synthetic',
        ]);

        return DB::table('participants')->insertGetId([
            'branch_id' => $branch, 'referral_branch_id' => $branch, 'referral_source' => 'default',
            'full_name' => 'Synthetic', 'phone' => '620000000000',
        ]);
    }

    private function sessionPublicId(
        int $participant,
        string $status,
        int $revision = 0,
        string $testType = 'ist',
    ): string {
        $publicId = (string) Str::ulid();
        $started = $status === 'created' ? null : '2026-09-08 03:00:00.000000+07:00';
        DB::table('test_sessions')->insert([
            'public_id' => $publicId, 'participant_id' => $participant, 'test_type' => $testType,
            'attempt_no' => 1, 'authorization_id' => (string) Str::ulid(),
            'allocation_intent_id' => (string) Str::ulid(), 'duration_seconds' => 3600,
            'status' => $status, 'answers_revision' => $revision,
            'started_at' => $started, 'ends_at' => $started === null ? null : '2026-09-08 04:00:00.000000+07:00',
            'submitted_at' => in_array($status, ['submitted', 'scored'], true) ? '2026-09-08 03:59:00.654321+07:00' : null,
            'scored_at' => $status === 'scored' ? '2026-09-08 04:00:01.000000+07:00' : null,
            'expired_at' => $status === 'expired' ? '2026-09-08 04:00:00.000001+07:00' : null,
            'voided_at' => $status === 'void' ? '2026-09-08 03:30:00.000000+07:00' : null,
            'void_reason' => $status === 'void' ? 'authorized correction' : null,
        ]);

        return $publicId;
    }
}
