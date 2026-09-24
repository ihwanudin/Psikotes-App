<?php

declare(strict_types=1);

namespace Tests\Feature\Proctoring;

use App\Actions\Proctoring\RecordProctoringPhoto;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Domain\Retention\RetentionPolicy;
use App\Security\RlsContextRunner;
use App\Services\Proctoring\ProctoringSessionMonitors;
use DateTimeImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\OrganizationPaymentTestCase;

final class RecordProctoringPhotoTest extends OrganizationPaymentTestCase
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
        Storage::fake('proctoring');
    }

    public function test_a_valid_photo_is_stored_and_the_cadence_monitor_is_updated(): void
    {
        $participant = $this->participant();
        $session = $this->sessionPublicId($participant);
        $receivedAt = new DateTimeImmutable('2026-09-24T03:30:00+07:00');

        $result = $this->action(fn (): DateTimeImmutable => $receivedAt)->execute(
            $participant,
            $session,
            UploadedFile::fake()->image('capture.jpg', 400, 300),
            'periodic',
            1,
            (string) Str::ulid(),
            new DateTimeImmutable('2026-09-24T03:29:59+07:00'),
        );

        $this->assertTrue($result->accepted);
        $this->assertFalse($result->replayed);
        $this->assertNotNull($result->publicId);

        $photo = DB::table('proctor_photos')->where('public_id', $result->publicId)->first();
        $this->assertNotNull($photo);
        $this->assertSame(400, $photo->width);
        $this->assertSame(300, $photo->height);
        $this->assertSame('not_run', $photo->face_match_status);
        Storage::disk('proctoring')->assertExists($photo->object_key);

        $this->assertDatabaseHas('proctor_session_monitors', [
            'instrument' => 'ist',
            'last_photo_received_at' => $receivedAt->format('Y-m-d H:i:s.uP'),
        ]);
    }

    public function test_replaying_the_same_client_event_id_does_not_store_the_file_twice(): void
    {
        $participant = $this->participant();
        $session = $this->sessionPublicId($participant);
        $clientEventId = (string) Str::ulid();
        $action = $this->action(fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-24T03:30:00+07:00'));

        $first = $action->execute(
            $participant, $session, UploadedFile::fake()->image('a.jpg', 400, 300),
            'periodic', 1, $clientEventId, null,
        );
        $replay = $action->execute(
            $participant, $session, UploadedFile::fake()->image('b.jpg', 400, 300),
            'periodic', 1, $clientEventId, null,
        );

        $this->assertTrue($first->accepted);
        $this->assertFalse($first->replayed);
        $this->assertTrue($replay->accepted);
        $this->assertTrue($replay->replayed);
        $this->assertSame($first->publicId, $replay->publicId);
        $this->assertSame(1, DB::table('proctor_photos')->count());
    }

    public function test_a_different_capture_claiming_the_same_sequence_is_rejected(): void
    {
        $participant = $this->participant();
        $session = $this->sessionPublicId($participant);
        $action = $this->action(fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-24T03:30:00+07:00'));
        $action->execute(
            $participant, $session, UploadedFile::fake()->image('a.jpg', 400, 300),
            'periodic', 1, (string) Str::ulid(), null,
        );

        $result = $action->execute(
            $participant, $session, UploadedFile::fake()->image('b.jpg', 400, 300),
            'periodic', 1, (string) Str::ulid(), null,
        );

        $this->assertFalse($result->accepted);
        $this->assertSame('PROCTORING_SEQUENCE_CONFLICT', $result->errorCode);
        $this->assertSame(1, DB::table('proctor_photos')->count());
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
                $participant, $publicId, UploadedFile::fake()->image('a.jpg', 400, 300),
                'periodic', 1, (string) Str::ulid(), null,
            );
            $this->assertFalse($result->accepted);
            $this->assertSame('SESSION_NOT_FOUND', $result->errorCode);
        }

        $this->assertSame(0, DB::table('proctor_photos')->count());
    }

    private function action(callable $clock): RecordProctoringPhoto
    {
        return new RecordProctoringPhoto(
            $this->app->make(RlsContextRunner::class),
            new ProctoringSessionMonitors,
            new RetentionPolicy,
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
