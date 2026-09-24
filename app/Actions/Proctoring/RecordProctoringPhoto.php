<?php

declare(strict_types=1);

namespace App\Actions\Proctoring;

use App\Domain\Proctoring\ProctoringInstrument;
use App\Domain\Retention\RetentionDataClass;
use App\Domain\Retention\RetentionPolicy;
use App\Security\RlsContextRunner;
use App\Services\Proctoring\ProctoringSessionMonitors;
use Closure;
use DateTimeImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * F7 (2026-09-24). Backs the "foto multipart" half of
 * `POST /sessions/:id/proctor`. Same ADR-0030 shape as
 * `AutosaveAssessmentAnswers`/`RecordProctoringEvent`: owns its own
 * service-context elevation + transaction, session lock held for the
 * whole operation (including the disk write) so a concurrent retry can
 * never observe a half-committed sequence.
 *
 * Idempotency is on `client_event_id` (unique per session) -- unlike a log
 * event, a photo capture has no natural domain identity of its own to key
 * on, so the client-generated id from `createUlidGenerator()`
 * (`ulid.ts`, already used the same way for autosave's `mutation_id`) is
 * the only thing to dedupe against. A replayed request with the same
 * `client_event_id` returns the already-stored photo's receipt without
 * writing the file twice; `sequence` reuse under a *different*
 * `client_event_id` is rejected as a genuine conflict, not silently
 * accepted -- two different captures must never claim the same ordinal
 * slot in one session.
 */
final class RecordProctoringPhoto
{
    private readonly Closure $clock;

    public function __construct(
        private readonly RlsContextRunner $contexts,
        private readonly ProctoringSessionMonitors $monitors,
        private readonly RetentionPolicy $retention,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now');
    }

    public function execute(
        int $participantId,
        string $sessionPublicId,
        UploadedFile $photo,
        string $captureKind,
        int $sequence,
        string $clientEventId,
        ?DateTimeImmutable $capturedAt,
    ): ProctoringSubmissionResult {
        return $this->contexts->runAsService(fn (): ProctoringSubmissionResult => DB::transaction(
            fn (): ProctoringSubmissionResult => $this->withinTransaction(
                $participantId,
                $sessionPublicId,
                $photo,
                $captureKind,
                $sequence,
                $clientEventId,
                $capturedAt,
            ),
        ));
    }

    private function withinTransaction(
        int $participantId,
        string $sessionPublicId,
        UploadedFile $photo,
        string $captureKind,
        int $sequence,
        string $clientEventId,
        ?DateTimeImmutable $capturedAt,
    ): ProctoringSubmissionResult {
        $session = DB::table('test_sessions')
            ->where('public_id', $sessionPublicId)
            ->where('participant_id', $participantId)
            ->lockForUpdate()
            ->first();

        if ($session === null) {
            return $this->reject('SESSION_NOT_FOUND');
        }

        try {
            $instrument = ProctoringInstrument::fromAssessmentCode((string) $session->test_type);
        } catch (InvalidArgumentException) {
            return $this->reject('SESSION_NOT_FOUND');
        }

        $receivedAt = ($this->clock)();
        if ((string) $session->status !== 'in_progress' || $session->ends_at === null
            || $receivedAt > new DateTimeImmutable((string) $session->ends_at)) {
            return $this->reject('SESSION_CLOSED');
        }

        $testSessionId = (int) $session->id;
        $this->monitors->ensure($testSessionId, $instrument->value, new DateTimeImmutable((string) $session->started_at));

        $existing = DB::table('proctor_photos')
            ->where('test_session_id', $testSessionId)
            ->where('client_event_id', $clientEventId)
            ->first();
        if ($existing !== null) {
            return new ProctoringSubmissionResult(true, true, (string) $existing->public_id);
        }

        $sequenceTaken = DB::table('proctor_photos')
            ->where('test_session_id', $testSessionId)
            ->where('sequence', $sequence)
            ->exists();
        if ($sequenceTaken) {
            return $this->reject('PROCTORING_SEQUENCE_CONFLICT');
        }

        $dimensions = @getimagesize($photo->getRealPath());
        if ($dimensions === false) {
            return $this->reject('INVALID_PROCTORING_SUBMISSION');
        }
        [$width, $height] = $dimensions;

        $disk = (string) config('proctoring.disk', 'proctoring');
        $objectKey = sprintf('proctoring/photos/%s/%s.jpg', $receivedAt->format('Y/m'), (string) Str::ulid());
        $checksum = hash_file('sha256', $photo->getRealPath());
        if ($checksum === false) {
            throw new RuntimeException('Could not checksum the uploaded proctoring photo.');
        }

        $stream = fopen($photo->getRealPath(), 'r');
        if ($stream === false) {
            throw new RuntimeException('Could not read the uploaded proctoring photo.');
        }
        try {
            Storage::disk($disk)->put($objectKey, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $publicId = (string) Str::ulid();
        DB::table('proctor_photos')->insert([
            'public_id' => $publicId,
            'test_session_id' => $testSessionId,
            'instrument' => $instrument->value,
            'capture_kind' => $captureKind,
            'sequence' => $sequence,
            'captured_at' => $capturedAt === null ? null : $this->timestamp($capturedAt),
            'received_at' => $this->timestamp($receivedAt),
            'disk' => $disk,
            'object_key' => $objectKey,
            'mime_type' => 'image/jpeg',
            'size_bytes' => $photo->getSize(),
            'width' => $width,
            'height' => $height,
            'checksum_sha256' => $checksum,
            'face_match_status' => 'not_run',
            'client_event_id' => $clientEventId,
            'retention_expires_at' => $this->timestamp(
                $this->retention->expiresAt(RetentionDataClass::ProctorMedia, $receivedAt),
            ),
            'created_at' => $this->timestamp($receivedAt),
        ]);

        $this->monitors->recordPhotoReceived($testSessionId, $receivedAt);

        return new ProctoringSubmissionResult(true, false, $publicId);
    }

    private function reject(string $errorCode): ProctoringSubmissionResult
    {
        return new ProctoringSubmissionResult(false, false, errorCode: $errorCode);
    }

    private function timestamp(DateTimeImmutable $time): string
    {
        return $time->format('Y-m-d H:i:s.uP');
    }
}
