<?php

declare(strict_types=1);

namespace App\Actions\Proctoring;

use App\Domain\Proctoring\ProctoringEvent;
use App\Domain\Proctoring\ProctoringEventKind;
use App\Domain\Proctoring\ProctoringEvidenceSource;
use App\Domain\Proctoring\ProctoringInstrument;
use App\Domain\Proctoring\ProctoringLogMapper;
use App\Security\RlsContextRunner;
use App\Services\Proctoring\ProctoringSessionMonitors;
use Closure;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use stdClass;
use ValueError;

/**
 * F7 (2026-09-24). Backs the "log event" half of `POST /sessions/:id/proctor`.
 * Same ADR-0030 shape as `AutosaveAssessmentAnswers`: owns its own
 * service-context elevation + transaction, does not sit behind the `rls`
 * middleware group.
 *
 * Idempotency is domain-level, on `evidence_id` (per
 * `ProctoringEvent::hasSamePayload()` -- "same evidence_id must mean the
 * same payload"), not on `client_event_id` alone: a resubmit of the exact
 * same observation with a different HTTP-retry id is still a replay, not
 * a new event.
 */
final class RecordProctoringEvent
{
    private readonly Closure $clock;

    public function __construct(
        private readonly RlsContextRunner $contexts,
        private readonly ProctoringSessionMonitors $monitors,
        private readonly ProctoringLogMapper $mapper,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now');
    }

    /** @param array<string, mixed>|null $metadata */
    public function execute(
        int $participantId,
        string $sessionPublicId,
        string $eventKind,
        string $evidenceSource,
        string $evidenceId,
        DateTimeImmutable $occurredAt,
        ?string $clientEventId,
        ?int $durationMs,
        ?array $metadata,
    ): ProctoringSubmissionResult {
        return $this->contexts->runAsService(fn (): ProctoringSubmissionResult => DB::transaction(
            fn (): ProctoringSubmissionResult => $this->withinTransaction(
                $participantId,
                $sessionPublicId,
                $eventKind,
                $evidenceSource,
                $evidenceId,
                $occurredAt,
                $clientEventId,
                $durationMs,
                $metadata,
            ),
        ));
    }

    /** @param array<string, mixed>|null $metadata */
    private function withinTransaction(
        int $participantId,
        string $sessionPublicId,
        string $eventKind,
        string $evidenceSource,
        string $evidenceId,
        DateTimeImmutable $occurredAt,
        ?string $clientEventId,
        ?int $durationMs,
        ?array $metadata,
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

        try {
            $event = new ProctoringEvent(
                evidenceId: $evidenceId,
                instrument: $instrument,
                kind: ProctoringEventKind::from($eventKind),
                source: ProctoringEvidenceSource::from($evidenceSource),
            );
        } catch (ValueError|InvalidArgumentException) {
            return $this->reject('INVALID_PROCTORING_SUBMISSION');
        }

        $this->monitors->ensure(
            (int) $session->id,
            $instrument->value,
            new DateTimeImmutable((string) $session->started_at),
        );

        $existing = $this->existingRow((int) $session->id, $evidenceId);
        if ($existing !== null) {
            $existingEvent = $this->mapper->eventFromRow($existing);
            if (! $existingEvent->hasSamePayload($event)) {
                return $this->reject('PROCTORING_EVIDENCE_MISMATCH');
            }

            return new ProctoringSubmissionResult(true, true, (string) $existing->public_id);
        }

        $publicId = (string) Str::ulid();
        DB::table('proctor_logs')->insert([
            'public_id' => $publicId,
            'test_session_id' => $session->id,
            'instrument' => $instrument->value,
            'event_kind' => $event->kind->value,
            'evidence_source' => $event->source->value,
            'evidence_id' => $event->evidenceId,
            'client_event_id' => $clientEventId,
            'occurred_at' => $this->timestamp($occurredAt),
            'received_at' => $this->timestamp($receivedAt),
            'duration_ms' => $durationMs,
            'metadata' => $metadata === null ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at' => $this->timestamp($receivedAt),
        ]);

        return new ProctoringSubmissionResult(true, false, $publicId);
    }

    private function existingRow(int $testSessionId, string $evidenceId): ?stdClass
    {
        return DB::table('proctor_logs')
            ->where('test_session_id', $testSessionId)
            ->where('evidence_id', $evidenceId)
            ->first();
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
