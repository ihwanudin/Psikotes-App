<?php

declare(strict_types=1);

namespace Tests\Feature\AssessmentSessions;

use App\Actions\AssessmentSessions\StartAssessmentSession;
use App\Domain\AssessmentSessions\AssessmentSessionDeadlinePolicy;
use App\Domain\AssessmentSessions\AssessmentSessionStateMachine;
use App\Security\RlsContextRunner;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\OrganizationPaymentTestCase;

final class StartAssessmentSessionTest extends OrganizationPaymentTestCase
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

    public function test_created_session_starts_once_with_server_time_and_exact_persisted_duration(): void
    {
        $participant = $this->participant();
        $session = $this->sessionPublicId($participant, 'created', 3671);
        $runner = $this->app->make(RlsContextRunner::class);
        $observedRole = null;
        $result = $this->action(function () use ($runner, &$observedRole): DateTimeImmutable {
            $observedRole = $runner->current()?->role;

            return new DateTimeImmutable('2026-09-08T03:00:00.123456+07:00');
        })->execute($participant, $session);

        $this->assertTrue($result->accepted);
        $this->assertFalse($result->replayed);
        $this->assertNull($result->errorCode);
        $this->assertSame('service', $observedRole);
        $this->assertSame($session, $result->sessionId);
        $this->assertSame('ist', $result->testType);
        $this->assertSame('in_progress', $result->status);
        $this->assertSame(1, $result->attemptNo);
        $this->assertSame(0, $result->answersRevision);
        $this->assertSame('2026-09-07 20:00:00.123456+00:00', $result->startedAt?->format('Y-m-d H:i:s.uP'));
        $this->assertSame('2026-09-07 21:01:11.123456+00:00', $result->endsAt?->format('Y-m-d H:i:s.uP'));
        $this->assertSame($result->endsAt, $result->writeDeadline);
        $this->assertSame(3671, $result->remainingSeconds);
        $this->assertDatabaseHas('test_sessions', ['public_id' => $session, 'status' => 'in_progress']);
    }

    public function test_in_progress_replay_preserves_original_window_without_writes(): void
    {
        $participant = $this->participant();
        $session = $this->sessionPublicId($participant, 'created', 3600);
        $first = $this->action(fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-08T03:00:00.111111+07:00'))
            ->execute($participant, $session);
        $persisted = DB::table('test_sessions')->where('public_id', $session)->first();

        $replay = $this->action(fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-08T03:05:00.999999+07:00'))
            ->execute($participant, $session);
        $after = DB::table('test_sessions')->where('public_id', $session)->first();

        $this->assertTrue($replay->accepted);
        $this->assertTrue($replay->replayed);
        $this->assertSame($first->startedAt?->format('U.u'), $replay->startedAt?->format('U.u'));
        $this->assertSame($first->endsAt?->format('U.u'), $replay->endsAt?->format('U.u'));
        $this->assertSame($persisted->updated_at, $after->updated_at);
        $this->assertSame(3300, $replay->remainingSeconds);
    }

    public function test_overdue_resume_expires_without_reopening_or_extending(): void
    {
        $participant = $this->participant();
        $session = $this->sessionPublicId($participant, 'in_progress');
        $result = $this->action(fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-08T04:00:00.000001+07:00'))
            ->execute($participant, $session);

        $this->assertFalse($result->accepted);
        $this->assertSame('DEADLINE_EXCEEDED', $result->errorCode);
        $this->assertSame('expired', $result->status);
        $this->assertSame(0, $result->remainingSeconds);
        $this->assertDatabaseHas('test_sessions', [
            'public_id' => $session, 'status' => 'expired',
            'started_at' => '2026-09-08 03:00:00.000000+07:00',
            'ends_at' => '2026-09-08 04:00:00.000000+07:00',
        ]);
    }

    public function test_closed_states_reject_with_canonical_code(): void
    {
        foreach (['submitted', 'scored', 'expired', 'void'] as $status) {
            $participant = $this->participant();
            $session = $this->sessionPublicId($participant, $status);
            $result = $this->action(fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-08T03:30:00+07:00'))
                ->execute($participant, $session);

            $this->assertFalse($result->accepted);
            $this->assertSame('SESSION_CLOSED', $result->errorCode);
            $this->assertSame($status, $result->status);
            if (in_array($status, ['submitted', 'scored'], true)) {
                $this->assertNotNull($result->submittedAt);
            }
        }
    }

    public function test_ownership_missing_identity_and_dass_fail_closed_as_not_found(): void
    {
        $owner = $this->participant();
        $other = $this->participant();
        $session = $this->sessionPublicId($owner, 'created');
        $action = $this->action(fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-08T03:00:00+07:00'));

        foreach ([[$other, $session], [0, $session], [$owner, 'invalid'], [$owner, (string) Str::ulid()]] as [$participant, $publicId]) {
            $result = $action->execute($participant, $publicId);
            $this->assertFalse($result->accepted);
            $this->assertSame('SESSION_NOT_FOUND', $result->errorCode);
        }

        DB::unprepared('DROP TRIGGER test_sessions_contract_insert');
        $dass = $this->sessionPublicId($other, 'created', 3600, 'dass21');
        $result = $action->execute($other, $dass);
        $this->assertFalse($result->accepted);
        $this->assertSame('SESSION_NOT_FOUND', $result->errorCode);
        $this->assertDatabaseHas('test_sessions', ['public_id' => $dass, 'status' => 'created']);
    }

    private function action(callable $clock): StartAssessmentSession
    {
        return new StartAssessmentSession(
            $this->app->make(RlsContextRunner::class),
            new AssessmentSessionStateMachine,
            new AssessmentSessionDeadlinePolicy,
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
        int $duration = 3600,
        string $testType = 'ist',
    ): string {
        $publicId = (string) Str::ulid();
        $startedAt = $status === 'created' ? null : '2026-09-08 03:00:00.000000+07:00';
        DB::table('test_sessions')->insert([
            'public_id' => $publicId, 'participant_id' => $participant, 'test_type' => $testType,
            'attempt_no' => 1, 'authorization_id' => (string) Str::ulid(),
            'allocation_intent_id' => (string) Str::ulid(), 'duration_seconds' => $duration,
            'status' => $status, 'answers_revision' => 0, 'started_at' => $startedAt,
            'ends_at' => $startedAt === null ? null : '2026-09-08 04:00:00.000000+07:00',
            'submitted_at' => in_array($status, ['submitted', 'scored'], true) ? '2026-09-08 04:00:00.000000+07:00' : null,
            'scored_at' => $status === 'scored' ? '2026-09-08 04:00:01.000000+07:00' : null,
            'expired_at' => $status === 'expired' ? '2026-09-08 04:00:00.000001+07:00' : null,
            'voided_at' => $status === 'void' ? '2026-09-08 03:30:00.000000+07:00' : null,
            'void_reason' => $status === 'void' ? 'authorized correction' : null,
        ]);

        return $publicId;
    }
}
