<?php

declare(strict_types=1);

namespace Tests\Feature\Proctoring;

use App\Actions\Proctoring\RecordProctoringEvent;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Domain\Proctoring\ProctoringLogMapper;
use App\Security\RlsContextRunner;
use App\Services\Proctoring\ProctoringSessionMonitors;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\OrganizationPaymentTestCase;

final class RecordProctoringEventTest extends OrganizationPaymentTestCase
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

    public function test_a_valid_client_observation_event_is_persisted_and_opens_a_cadence_monitor(): void
    {
        $participant = $this->participant();
        $session = $this->sessionPublicId($participant);

        $result = $this->action(fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-24T03:30:00+07:00'))
            ->execute(
                $participant,
                $session,
                'CAMERA_INTERRUPTED',
                'CLIENT_OBSERVATION',
                (string) Str::ulid(),
                new DateTimeImmutable('2026-09-24T03:29:58+07:00'),
                (string) Str::ulid(),
                null,
                ['app_state' => 'background'],
            );

        $this->assertTrue($result->accepted);
        $this->assertFalse($result->replayed);
        $this->assertNotNull($result->publicId);
        $this->assertDatabaseHas('proctor_logs', [
            'public_id' => $result->publicId,
            'event_kind' => 'CAMERA_INTERRUPTED',
            'evidence_source' => 'CLIENT_OBSERVATION',
        ]);
        $this->assertDatabaseHas('proctor_session_monitors', [
            'instrument' => 'ist',
            'expected_capture_min_seconds' => 12,
            'expected_capture_max_seconds' => 20,
        ]);
    }

    public function test_replaying_the_same_evidence_with_identical_payload_does_not_duplicate(): void
    {
        $participant = $this->participant();
        $session = $this->sessionPublicId($participant);
        $evidenceId = (string) Str::ulid();
        $action = $this->action(fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-24T03:30:00+07:00'));

        $first = $action->execute(
            $participant, $session, 'SCREEN_DEPARTURE', 'CLIENT_OBSERVATION', $evidenceId,
            new DateTimeImmutable('2026-09-24T03:29:00+07:00'), null, 4500, null,
        );
        $replay = $action->execute(
            $participant, $session, 'SCREEN_DEPARTURE', 'CLIENT_OBSERVATION', $evidenceId,
            new DateTimeImmutable('2026-09-24T03:29:00+07:00'), null, 4500, null,
        );

        $this->assertTrue($first->accepted);
        $this->assertFalse($first->replayed);
        $this->assertTrue($replay->accepted);
        $this->assertTrue($replay->replayed);
        $this->assertSame($first->publicId, $replay->publicId);
        $this->assertSame(1, DB::table('proctor_logs')->count());
    }

    public function test_same_evidence_id_with_a_different_payload_is_rejected_as_a_mismatch(): void
    {
        $participant = $this->participant();
        $session = $this->sessionPublicId($participant);
        $evidenceId = (string) Str::ulid();
        $action = $this->action(fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-24T03:30:00+07:00'));
        $action->execute(
            $participant, $session, 'SCREEN_DEPARTURE', 'CLIENT_OBSERVATION', $evidenceId,
            new DateTimeImmutable('2026-09-24T03:29:00+07:00'), null, null, null,
        );

        $result = $action->execute(
            $participant, $session, 'CAMERA_INTERRUPTED', 'CLIENT_OBSERVATION', $evidenceId,
            new DateTimeImmutable('2026-09-24T03:29:00+07:00'), null, null, null,
        );

        $this->assertFalse($result->accepted);
        $this->assertSame('PROCTORING_EVIDENCE_MISMATCH', $result->errorCode);
        $this->assertSame(1, DB::table('proctor_logs')->count());
    }

    public function test_a_kind_not_allowed_for_its_evidence_source_is_rejected(): void
    {
        $participant = $this->participant();
        $session = $this->sessionPublicId($participant);

        // FACE_MISMATCH is a CLIENT_OBSERVATION kind (ProctoringEvent's own
        // allowedKinds() table) -- posting it as SERVER_FINDING must fail
        // the domain constructor's own validation, not merely be accepted
        // and stored as an inconsistent row.
        $result = $this->action(fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-24T03:30:00+07:00'))
            ->execute(
                $participant, $session, 'FACE_MISMATCH', 'SERVER_FINDING', (string) Str::ulid(),
                new DateTimeImmutable('2026-09-24T03:29:00+07:00'), null, null, null,
            );

        $this->assertFalse($result->accepted);
        $this->assertSame('INVALID_PROCTORING_SUBMISSION', $result->errorCode);
        $this->assertSame(0, DB::table('proctor_logs')->count());
    }

    public function test_caller_identity_and_ownership_fail_closed_without_existence_leakage(): void
    {
        $owner = $this->participant();
        $other = $this->participant();
        $session = $this->sessionPublicId($owner);
        $action = $this->action(fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-24T03:30:00+07:00'));

        foreach ([
            [$other, $session],
            [0, $session],
            [$owner, (string) Str::ulid()],
        ] as [$participant, $publicId]) {
            $result = $action->execute(
                $participant, $publicId, 'CAMERA_INTERRUPTED', 'CLIENT_OBSERVATION', (string) Str::ulid(),
                new DateTimeImmutable('2026-09-24T03:29:00+07:00'), null, null, null,
            );
            $this->assertFalse($result->accepted);
            $this->assertSame('SESSION_NOT_FOUND', $result->errorCode);
        }

        $this->assertSame(0, DB::table('proctor_logs')->count());
    }

    public function test_a_session_that_is_not_in_progress_or_past_its_deadline_is_rejected(): void
    {
        $participant = $this->participant();
        $created = $this->sessionPublicId($participant, status: 'created');
        $action = $this->action(fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-24T03:30:00+07:00'));

        $result = $action->execute(
            $participant, $created, 'CAMERA_INTERRUPTED', 'CLIENT_OBSERVATION', (string) Str::ulid(),
            new DateTimeImmutable('2026-09-24T03:29:00+07:00'), null, null, null,
        );

        $this->assertFalse($result->accepted);
        $this->assertSame('SESSION_CLOSED', $result->errorCode);

        $pastDeadline = $this->participant();
        $session = $this->sessionPublicId($pastDeadline);
        $lateResult = $this->action(fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-24T04:00:00.000001+07:00'))
            ->execute(
                $pastDeadline, $session, 'CAMERA_INTERRUPTED', 'CLIENT_OBSERVATION', (string) Str::ulid(),
                new DateTimeImmutable('2026-09-24T03:59:00+07:00'), null, null, null,
            );

        $this->assertFalse($lateResult->accepted);
        $this->assertSame('SESSION_CLOSED', $lateResult->errorCode);
    }

    public function test_a_corrupt_dass_session_is_not_exposed_through_proctoring_ingest(): void
    {
        DB::unprepared('DROP TRIGGER test_sessions_contract_insert');
        $participant = $this->participant();
        $session = (string) Str::ulid();
        DB::table('test_sessions')->insert([
            'public_id' => $session, 'participant_id' => $participant, 'test_type' => 'dass21',
            'attempt_no' => 1, 'authorization_id' => (string) Str::ulid(),
            'allocation_intent_id' => (string) Str::ulid(), 'duration_seconds' => 3600,
            'status' => 'in_progress', 'answers_revision' => 0,
            'started_at' => '2026-09-24 03:00:00.000000+07:00',
            'ends_at' => '2026-09-24 04:00:00.000000+07:00',
        ]);

        $result = $this->action(fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-24T03:30:00+07:00'))
            ->execute(
                $participant, $session, 'CAMERA_INTERRUPTED', 'CLIENT_OBSERVATION', (string) Str::ulid(),
                new DateTimeImmutable('2026-09-24T03:29:00+07:00'), null, null, null,
            );

        $this->assertFalse($result->accepted);
        $this->assertSame('SESSION_NOT_FOUND', $result->errorCode);
        $this->assertSame(0, DB::table('proctor_logs')->count());
    }

    private function action(callable $clock): RecordProctoringEvent
    {
        return new RecordProctoringEvent(
            $this->app->make(RlsContextRunner::class),
            new ProctoringSessionMonitors,
            new ProctoringLogMapper,
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
        ?int $attempt = null,
        string $status = 'in_progress',
        string $testType = 'ist',
    ): string {
        $publicId = (string) Str::ulid();
        $started = $status === 'created' ? null : '2026-09-24 03:00:00.000000+07:00';
        $definitionSource = [
            'instrument' => $testType, 'version' => 'synthetic-definition-v1',
            'provenance' => 'synthetic-proctoring-test-only', 'total_duration_seconds' => 3600,
            'subtests' => [['code' => 'SYN', 'duration_seconds' => 3600, 'item_count' => 10]],
            'randomization' => 'fixed', 'seed' => null, 'generator' => null,
        ];
        $definition = SessionDefinition::fromArray([
            ...$definitionSource,
            'checksum' => SessionDefinition::checksumFor($definitionSource),
        ]);
        DB::table('test_sessions')->insert([
            'public_id' => $publicId, 'participant_id' => $participant, 'test_type' => $testType,
            'attempt_no' => $attempt ?? ++$this->attempt,
            'authorization_id' => (string) Str::ulid(), 'allocation_intent_id' => (string) Str::ulid(),
            'duration_seconds' => 3600, 'status' => $status, 'answers_revision' => 0,
            'started_at' => $started, 'ends_at' => $started === null ? null : '2026-09-24 04:00:00.000000+07:00',
            'submitted_at' => $status === 'submitted' ? '2026-09-24 03:59:00.000000+07:00' : null,
            'session_definition_version' => $definition->version,
            'session_definition_provenance' => $definition->provenance,
            'session_definition_checksum' => $definition->checksum,
            'session_definition_payload' => json_encode($definition->toArray(), JSON_THROW_ON_ERROR),
        ]);

        return $publicId;
    }
}
