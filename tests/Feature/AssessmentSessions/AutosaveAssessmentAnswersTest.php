<?php

declare(strict_types=1);

namespace Tests\Feature\AssessmentSessions;

use App\Actions\AssessmentSessions\AutosaveAssessmentAnswers;
use App\Actions\AssessmentSessions\SealExpiredAssessmentSession;
use App\Domain\AssessmentSessions\AssessmentAutosavePolicy;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Security\RlsContextRunner;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\OrganizationPaymentTestCase;

final class AutosaveAssessmentAnswersTest extends OrganizationPaymentTestCase
{
    private int $attempt = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $command = $this->artisan('migrate', ['--force' => true]);
        if (is_int($command)) {
            $this->fail('The migration command did not return a test command wrapper.');
        }
        $command->assertExitCode(0);
    }

    public function test_it_persists_an_atomic_autosave_in_database_guard_order(): void
    {
        $participant = $this->participant();
        $session = $this->sessionPublicId($participant);
        $mutation = (string) Str::ulid();
        $runner = $this->app->make(RlsContextRunner::class);
        $observedContext = null;
        $observedTransactionLevel = 0;

        $result = $this->action(function () use ($runner, &$observedContext, &$observedTransactionLevel): DateTimeImmutable {
            $observedContext = $runner->current()?->role;
            $observedTransactionLevel = DB::transactionLevel();

            return new DateTimeImmutable('2026-09-08T03:30:00.123456+07:00');
        })->execute($participant, $session, $mutation, 1, [
            ['item_no' => 2, 'value' => ['z' => 2, 'a' => 1]],
            ['item_no' => 1, 'value' => 'A'],
        ]);

        $this->assertTrue($result->accepted);
        $this->assertFalse($result->replayed);
        $this->assertNull($result->errorCode);
        $this->assertSame('in_progress', $result->status);
        $this->assertNotNull($result->receipt);
        $this->assertSame($mutation, $result->receipt->mutationId);
        $this->assertSame([1, 2], $result->receipt->acceptedItemNumbers);
        $this->assertSame('service', $observedContext);
        $this->assertGreaterThan(0, $observedTransactionLevel);
        $this->assertDatabaseHas('test_sessions', ['public_id' => $session, 'answers_revision' => 1]);
        $this->assertDatabaseHas('assessment_autosave_mutations', [
            'mutation_id' => $mutation, 'revision' => 1,
        ]);
        $this->assertSame(2, DB::table('answers')->count());
        $this->assertSame([1, 2], DB::table('answers')->orderBy('item_no')->pluck('item_no')->all());
    }

    public function test_identical_replay_returns_the_exact_stored_receipt_without_writes(): void
    {
        $participant = $this->participant();
        $session = $this->sessionPublicId($participant);
        $mutation = (string) Str::ulid();
        $items = [['item_no' => 1, 'value' => ['choice' => 'A']]];
        $first = $this->action(fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-08T03:30:00.111111+07:00'))
            ->execute($participant, $session, $mutation, 1, $items);

        $answerUpdatedAt = DB::table('answers')->value('updated_at');
        $replay = $this->action(fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-08T04:30:00.999999+07:00'))
            ->execute($participant, $session, $mutation, 1, $items);

        $this->assertTrue($replay->accepted);
        $this->assertTrue($replay->replayed);
        $this->assertSame($first->receipt?->receivedAt->format('Y-m-d H:i:s.uP'), $replay->receipt?->receivedAt->format('Y-m-d H:i:s.uP'));
        $this->assertSame($first->receipt?->acceptedItemNumbers, $replay->receipt?->acceptedItemNumbers);
        $this->assertSame(1, DB::table('assessment_autosave_mutations')->count());
        $this->assertSame(1, DB::table('answers')->count());
        $this->assertSame($answerUpdatedAt, DB::table('answers')->value('updated_at'));
    }

    public function test_mutation_payload_mismatch_is_rejected_without_writes(): void
    {
        $participant = $this->participant();
        $session = $this->sessionPublicId($participant);
        $mutation = (string) Str::ulid();
        $action = $this->action(fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-08T03:30:00+07:00'));
        $action->execute($participant, $session, $mutation, 1, [['item_no' => 1, 'value' => 'A']]);

        $result = $action->execute($participant, $session, $mutation, 1, [['item_no' => 1, 'value' => 'B']]);

        $this->assertFalse($result->accepted);
        $this->assertSame('MUTATION_PAYLOAD_MISMATCH', $result->errorCode);
        $this->assertSame(1, DB::table('assessment_autosave_mutations')->count());
        $this->assertSame('"A"', DB::table('answers')->value('value'));
    }

    public function test_caller_identity_and_ownership_fail_closed_without_existence_leakage(): void
    {
        $owner = $this->participant();
        $other = $this->participant();
        $session = $this->sessionPublicId($owner);
        $action = $this->action(fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-08T03:30:00+07:00'));

        foreach ([
            [$other, $session],
            [0, $session],
            [$owner, 'not-a-public-id'],
            [$owner, (string) Str::ulid()],
        ] as [$participant, $publicId]) {
            $result = $action->execute($participant, $publicId, (string) Str::ulid(), 1, [['item_no' => 1, 'value' => 'A']]);
            $this->assertFalse($result->accepted);
            $this->assertSame('SESSION_NOT_FOUND', $result->errorCode);
        }

        $this->assertSame(0, DB::table('answers')->count());
        $this->assertSame(0, DB::table('assessment_autosave_mutations')->count());
    }

    public function test_corrupt_dass_session_is_not_exposed_through_generic_autosave(): void
    {
        DB::unprepared('DROP TRIGGER test_sessions_contract_insert');
        $participant = $this->participant();
        $session = (string) Str::ulid();
        DB::table('test_sessions')->insert([
            'public_id' => $session, 'participant_id' => $participant, 'test_type' => 'dass21',
            'attempt_no' => 1, 'authorization_id' => (string) Str::ulid(),
            'allocation_intent_id' => (string) Str::ulid(), 'duration_seconds' => 3600,
            'status' => 'in_progress', 'answers_revision' => 0,
            'started_at' => '2026-09-08 03:00:00.000000+07:00',
            'ends_at' => '2026-09-08 04:00:00.000000+07:00',
        ]);

        $result = $this->action(fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-08T03:30:00+07:00'))
            ->execute($participant, $session, (string) Str::ulid(), 1, [['item_no' => 1, 'value' => 'A']]);

        $this->assertFalse($result->accepted);
        $this->assertSame('SESSION_NOT_FOUND', $result->errorCode);
        $this->assertSame(0, DB::table('answers')->count());
    }

    public function test_exact_deadline_is_accepted_but_later_receipt_expires_without_answer_writes(): void
    {
        $participant = $this->participant();
        $atBoundary = $this->sessionPublicId($participant);
        $accepted = $this->action(fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-08T04:00:00.000000+07:00'))
            ->execute($participant, $atBoundary, (string) Str::ulid(), 1, [['item_no' => 1, 'value' => 'A']]);
        $this->assertTrue($accepted->accepted);

        $lateParticipant = $this->participant();
        $late = $this->sessionPublicId($lateParticipant);
        $rejected = $this->action(fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-08T04:00:00.000001+07:00'))
            ->execute($lateParticipant, $late, (string) Str::ulid(), 1, [['item_no' => 1, 'value' => 'A']]);

        $this->assertFalse($rejected->accepted);
        $this->assertSame('DEADLINE_EXCEEDED', $rejected->errorCode);
        $this->assertSame('expired', $rejected->status);
        $this->assertDatabaseHas('test_sessions', [
            'public_id' => $late, 'status' => 'expired', 'answers_revision' => 0,
        ]);
        $this->assertSame(1, DB::table('answers')->count());
        $this->assertSame(1, DB::table('assessment_autosave_mutations')->count());
    }

    public function test_state_revision_and_batch_rejections_are_machine_readable(): void
    {
        $action = $this->action(fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-08T03:30:00+07:00'));

        $cases = [
            ['created', 1, [['item_no' => 1, 'value' => 'A']], 'SESSION_NOT_STARTED'],
            ['submitted', 1, [['item_no' => 1, 'value' => 'A']], 'SESSION_CLOSED'],
            ['in_progress', 0, [['item_no' => 1, 'value' => 'A']], 'INVALID_ANSWER_BATCH'],
            ['in_progress', 2, [['item_no' => 1, 'value' => 'A']], 'AUTOSAVE_REVISION_GAP'],
            ['in_progress', 1, [], 'INVALID_ANSWER_BATCH'],
        ];

        foreach ($cases as [$status, $revision, $items, $code]) {
            $participant = $this->participant();
            $session = $this->sessionPublicId($participant, null, $status);
            $result = $action->execute($participant, $session, (string) Str::ulid(), $revision, $items);
            $this->assertFalse($result->accepted);
            $this->assertSame($code, $result->errorCode);
        }
    }

    public function test_stale_revision_and_invalid_mutation_identity_are_rejected(): void
    {
        $participant = $this->participant();
        $session = $this->sessionPublicId($participant);
        $action = $this->action(fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-08T03:30:00+07:00'));
        $action->execute($participant, $session, (string) Str::ulid(), 1, [['item_no' => 1, 'value' => 'A']]);

        $stale = $action->execute($participant, $session, (string) Str::ulid(), 1, [['item_no' => 1, 'value' => 'A']]);
        $invalid = $action->execute($participant, $session, 'not-a-mutation-id', 2, [['item_no' => 1, 'value' => 'B']]);

        $this->assertFalse($stale->accepted);
        $this->assertSame('AUTOSAVE_STALE_REVISION', $stale->errorCode);
        $this->assertFalse($invalid->accepted);
        $this->assertSame('INVALID_ANSWER_BATCH', $invalid->errorCode);
        $this->assertSame(1, DB::table('assessment_autosave_mutations')->count());
    }

    public function test_database_failure_rolls_back_the_receipt_and_all_answers(): void
    {
        $participant = $this->participant();
        $publicId = $this->sessionPublicId($participant);
        $sessionId = (int) DB::table('test_sessions')->where('public_id', $publicId)->value('id');
        DB::table('answers')->insert([
            'session_id' => $sessionId, 'item_no' => 1, 'value' => json_encode('old'),
            'revision' => 1, 'answered_at' => '2026-09-08 03:00:00.000000+07:00',
        ]);

        try {
            $this->action(fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-08T03:30:00+07:00'))
                ->execute($participant, $publicId, (string) Str::ulid(), 1, [
                    ['item_no' => 2, 'value' => 'new'], ['item_no' => 1, 'value' => 'new'],
                ]);
            $this->fail('The database guard should reject a non-increasing answer revision.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, DB::table('assessment_autosave_mutations')->count());
        $this->assertSame(1, DB::table('answers')->count());
        $this->assertDatabaseHas('answers', ['session_id' => $sessionId, 'item_no' => 1, 'revision' => 1]);
        $this->assertDatabaseHas('test_sessions', ['id' => $sessionId, 'answers_revision' => 0]);
    }

    public function test_item_no_beyond_the_sessions_real_item_count_is_rejected(): void
    {
        $participant = $this->participant();
        $definitionSource = [
            'instrument' => 'ist', 'version' => 'synthetic-definition-v1',
            'provenance' => 'synthetic-autosave-bound-test-only', 'total_duration_seconds' => 3600,
            'subtests' => [['code' => 'SYN', 'duration_seconds' => 3600, 'item_count' => 2]],
            'randomization' => 'fixed', 'seed' => null, 'generator' => null,
        ];
        $definition = SessionDefinition::fromArray([
            ...$definitionSource, 'checksum' => SessionDefinition::checksumFor($definitionSource),
        ]);
        $publicId = (string) Str::ulid();
        DB::table('test_sessions')->insert([
            'public_id' => $publicId, 'participant_id' => $participant, 'test_type' => 'ist',
            'attempt_no' => ++$this->attempt,
            'authorization_id' => (string) Str::ulid(), 'allocation_intent_id' => (string) Str::ulid(),
            'duration_seconds' => 3600, 'status' => 'in_progress', 'answers_revision' => 0,
            'started_at' => '2026-09-08 03:00:00.000000+07:00', 'ends_at' => '2026-09-08 04:00:00.000000+07:00',
            'session_definition_version' => $definition->version,
            'session_definition_provenance' => $definition->provenance,
            'session_definition_checksum' => $definition->checksum,
            'session_definition_payload' => json_encode($definition->toArray(), JSON_THROW_ON_ERROR),
        ]);

        $result = $this->action(fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-08T03:30:00+07:00'))
            ->execute($participant, $publicId, (string) Str::ulid(), 1, [['item_no' => 3, 'value' => 'A']]);

        $this->assertFalse($result->accepted);
        $this->assertSame('INVALID_ANSWER_BATCH', $result->errorCode);
        $this->assertSame(0, DB::table('answers')->count());
    }

    /**
     * F2 timed-segments stage 5 (2026-09-22). An item_no that exists in the
     * instrument overall (unlike the flat-bound test above) but belongs to
     * a DIFFERENT subtest than the one the timed-segment sweep currently
     * resolves to must be rejected with the new, more specific code.
     */
    public function test_item_no_outside_the_current_segments_subtest_is_rejected(): void
    {
        $participant = $this->participant();
        $publicId = $this->twoSubtestSessionPublicId($participant);

        $result = $this->action(fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-08T03:30:00+07:00'))
            ->execute($participant, $publicId, (string) Str::ulid(), 1, [['item_no' => 25, 'value' => 'A']]);

        $this->assertFalse($result->accepted);
        $this->assertSame('ITEM_OUTSIDE_CURRENT_SEGMENT', $result->errorCode);
        $this->assertSame(0, DB::table('answers')->count());
    }

    public function test_item_no_inside_the_current_segments_subtest_is_accepted(): void
    {
        $participant = $this->participant();
        $publicId = $this->twoSubtestSessionPublicId($participant);

        $result = $this->action(fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-08T03:30:00+07:00'))
            ->execute($participant, $publicId, (string) Str::ulid(), 1, [['item_no' => 5, 'value' => 'A']]);

        $this->assertTrue($result->accepted);
        $this->assertSame(1, DB::table('answers')->count());
    }

    /** SE: items 1-20, current segment. WA: items 21-40, not yet reached. */
    private function twoSubtestSessionPublicId(int $participant): string
    {
        $definitionSource = [
            'instrument' => 'ist', 'version' => 'synthetic-definition-v1',
            'provenance' => 'synthetic-autosave-segment-range-test-only', 'total_duration_seconds' => 3600,
            'subtests' => [
                ['code' => 'SE', 'duration_seconds' => 1800, 'item_count' => 20],
                ['code' => 'WA', 'duration_seconds' => 1800, 'item_count' => 20],
            ],
            'randomization' => 'fixed', 'seed' => null, 'generator' => null,
        ];
        $definition = SessionDefinition::fromArray([
            ...$definitionSource, 'checksum' => SessionDefinition::checksumFor($definitionSource),
        ]);
        $publicId = (string) Str::ulid();
        DB::table('test_sessions')->insert([
            'public_id' => $publicId, 'participant_id' => $participant, 'test_type' => 'ist',
            'attempt_no' => ++$this->attempt,
            'authorization_id' => (string) Str::ulid(), 'allocation_intent_id' => (string) Str::ulid(),
            'duration_seconds' => 3600, 'status' => 'in_progress', 'answers_revision' => 0,
            'started_at' => '2026-09-08 03:00:00.000000+07:00', 'ends_at' => '2026-09-08 04:00:00.000000+07:00',
            'current_segment_index' => 0,
            'current_segment_became_current_at' => '2026-09-08 03:00:00.000000+07:00',
            'current_segment_started_at' => '2026-09-08 03:00:00.000000+07:00',
            'session_definition_version' => $definition->version,
            'session_definition_provenance' => $definition->provenance,
            'session_definition_checksum' => $definition->checksum,
            'session_definition_payload' => json_encode($definition->toArray(), JSON_THROW_ON_ERROR),
        ]);

        return $publicId;
    }

    private function action(callable $clock): AutosaveAssessmentAnswers
    {
        return new AutosaveAssessmentAnswers(
            $this->app->make(RlsContextRunner::class),
            new AssessmentAutosavePolicy,
            new SealExpiredAssessmentSession($this->app->make(RlsContextRunner::class)),
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

    private function sessionPublicId(int $participant, ?int $attempt = null, string $status = 'in_progress'): string
    {
        $publicId = (string) Str::ulid();
        $started = $status === 'created' ? null : '2026-09-08 03:00:00.000000+07:00';
        // F2 session-http (2026-09-21): a real S3-allocated session always has
        // this snapshot; AutosaveAssessmentAnswers now reads it to bound
        // item_no against the session's real item count. item_count=10 is
        // generous headroom above every item_no this file's tests use (1-2).
        $definitionSource = [
            'instrument' => 'ist', 'version' => 'synthetic-definition-v1',
            'provenance' => 'synthetic-autosave-test-only', 'total_duration_seconds' => 3600,
            'subtests' => [['code' => 'SYN', 'duration_seconds' => 3600, 'item_count' => 10]],
            'randomization' => 'fixed', 'seed' => null, 'generator' => null,
        ];
        $definition = SessionDefinition::fromArray([
            ...$definitionSource, 'checksum' => SessionDefinition::checksumFor($definitionSource),
        ]);
        DB::table('test_sessions')->insert([
            'public_id' => $publicId, 'participant_id' => $participant, 'test_type' => 'ist',
            'attempt_no' => $attempt ?? ++$this->attempt,
            'authorization_id' => (string) Str::ulid(), 'allocation_intent_id' => (string) Str::ulid(),
            'duration_seconds' => 3600, 'status' => $status, 'answers_revision' => 0,
            'started_at' => $started, 'ends_at' => $started === null ? null : '2026-09-08 04:00:00.000000+07:00',
            'submitted_at' => $status === 'submitted' ? '2026-09-08 03:59:00.000000+07:00' : null,
            'session_definition_version' => $definition->version,
            'session_definition_provenance' => $definition->provenance,
            'session_definition_checksum' => $definition->checksum,
            'session_definition_payload' => json_encode($definition->toArray(), JSON_THROW_ON_ERROR),
        ]);

        return $publicId;
    }
}
