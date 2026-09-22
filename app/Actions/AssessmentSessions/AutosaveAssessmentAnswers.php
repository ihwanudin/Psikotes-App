<?php

declare(strict_types=1);

namespace App\Actions\AssessmentSessions;

use App\Domain\AssessmentSessions\AssessmentAutosaveMutation;
use App\Domain\AssessmentSessions\AssessmentAutosavePolicy;
use App\Domain\AssessmentSessions\AssessmentAutosaveReceipt;
use App\Domain\AssessmentSessions\AssessmentSessionStatus;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Domain\AssessmentSessions\SubtestItemRange;
use App\Domain\AssessmentSessions\TimedSegmentSweep;
use App\Domain\AssessmentSessions\UnsupportedGenericAssessmentInstrument;
use App\Security\RlsContextRunner;
use Closure;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use stdClass;

final class AutosaveAssessmentAnswers
{
    private readonly Closure $clock;

    public function __construct(
        private readonly RlsContextRunner $contexts,
        private readonly AssessmentAutosavePolicy $policy,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now');
    }

    /**
     * @param  array<int, mixed>  $items
     */
    public function execute(
        int $participantId,
        string $sessionPublicId,
        string $mutationId,
        int $revision,
        array $items,
    ): AssessmentAutosaveResult {
        if ($participantId < 1 || ! Str::isUlid($sessionPublicId)) {
            return $this->reject(null, 'SESSION_NOT_FOUND');
        }

        /** @var AssessmentAutosaveResult $result */
        $result = $this->contexts->runAsService(fn (): AssessmentAutosaveResult => DB::transaction(
            fn (): AssessmentAutosaveResult => $this->withinTransaction(
                $participantId,
                $sessionPublicId,
                $mutationId,
                $revision,
                $items,
            ),
        ));

        return $result;
    }

    /**
     * @param  array<int, mixed>  $items
     */
    private function withinTransaction(
        int $participantId,
        string $sessionPublicId,
        string $mutationId,
        int $revision,
        array $items,
    ): AssessmentAutosaveResult {
        $session = DB::table('test_sessions')
            ->where('public_id', $sessionPublicId)
            ->where('participant_id', $participantId)
            ->lockForUpdate()
            ->first();

        if ($session === null) {
            return $this->reject(null, 'SESSION_NOT_FOUND');
        }

        try {
            GenericAssessmentInstrument::fromExternal((string) $session->test_type);
        } catch (UnsupportedGenericAssessmentInstrument) {
            return $this->reject(null, 'SESSION_NOT_FOUND');
        }

        $status = AssessmentSessionStatus::tryFrom((string) $session->status)
            ?? throw new RuntimeException('The persisted assessment session status is invalid.');

        if (! Str::isUlid($mutationId) || ! array_is_list($items)) {
            return $this->reject($status->value, 'INVALID_ANSWER_BATCH', (int) $session->answers_revision);
        }

        $receivedAt = ($this->clock)();
        if (! $receivedAt instanceof DateTimeImmutable) {
            throw new RuntimeException('The assessment autosave clock must return DateTimeImmutable.');
        }

        $definition = $this->storedDefinition($session->session_definition_payload);
        $currentSubtestRange = $this->currentSubtestItemRange($session, $definition, $receivedAt);

        $existing = $this->loadMutation((int) $session->id, $mutationId);
        $decision = $this->policy->decide(
            $status,
            $session->ends_at === null ? null : new DateTimeImmutable((string) $session->ends_at),
            $receivedAt,
            $sessionPublicId,
            (int) $session->answers_revision,
            $mutationId,
            $revision,
            $items,
            $existing,
            array_sum(array_column($definition->subtests, 'item_count')),
            $currentSubtestRange?->start,
            $currentSubtestRange?->end,
        );

        if (! $decision->accepted) {
            if ($decision->status === AssessmentSessionStatus::Expired
                && $status === AssessmentSessionStatus::InProgress) {
                $updated = DB::table('test_sessions')->where('id', $session->id)
                    ->where('status', AssessmentSessionStatus::InProgress->value)
                    ->update([
                        'status' => AssessmentSessionStatus::Expired->value,
                        'expired_at' => $this->timestamp($receivedAt),
                        'updated_at' => $this->timestamp($receivedAt),
                    ]);
                if ($updated !== 1) {
                    throw new RuntimeException('The expired assessment session could not be sealed.');
                }
            }

            return $this->reject($decision->status->value, $decision->errorCode?->value, (int) $session->answers_revision);
        }

        if (! $decision->shouldPersist) {
            return new AssessmentAutosaveResult(
                true,
                true,
                $decision->status->value,
                null,
                $decision->receipt,
            );
        }

        $receipt = $decision->receipt;
        if ($receipt === null || $decision->canonicalHash === null) {
            throw new RuntimeException('An accepted assessment autosave requires a receipt and request hash.');
        }

        $this->persistReceipt((int) $session->id, $receipt, $decision->canonicalHash);
        $this->persistAnswers((int) $session->id, $receipt, $items);

        $updated = DB::table('test_sessions')
            ->where('id', $session->id)
            ->where('status', AssessmentSessionStatus::InProgress->value)
            ->where('answers_revision', (int) $session->answers_revision)
            ->update([
                'answers_revision' => $receipt->revision,
                'updated_at' => $this->timestamp($receipt->receivedAt),
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('The assessment autosave revision could not be committed.');
        }

        return new AssessmentAutosaveResult(
            true,
            false,
            $decision->status->value,
            null,
            $receipt,
        );
    }

    /**
     * The session's true SessionDefinition, read from the exact
     * session_definition_payload snapshot the server itself stored at
     * start time (S3) -- never from the client, and never re-derived from
     * a live catalog lookup that could drift from what this specific
     * session was actually issued.
     */
    private function storedDefinition(mixed $definitionPayload): SessionDefinition
    {
        if (! is_string($definitionPayload)) {
            throw new RuntimeException('The persisted assessment session definition snapshot is missing.');
        }
        try {
            $decoded = json_decode($definitionPayload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The persisted assessment session definition snapshot is invalid.', previous: $exception);
        }
        if (! is_array($decoded)) {
            throw new RuntimeException('The persisted assessment session definition snapshot is invalid.');
        }

        return SessionDefinition::fromArray($decoded);
    }

    /**
     * F2 timed-segments stage 5 (2026-09-22). null only when the session
     * has never started (started_at null) -- the deadline check inside
     * AssessmentAutosavePolicy::decide() already rejects that case
     * (SESSION_NOT_STARTED) before the new range check would ever run, so
     * null here is safe. Compute-only, fresh on every request -- never
     * trusts a stored index, the same reason TimedSegmentSweep exists.
     */
    private function currentSubtestItemRange(
        stdClass $session,
        SessionDefinition $definition,
        DateTimeImmutable $receivedAt,
    ): ?SubtestItemRange {
        if ($session->started_at === null) {
            return null;
        }

        $swept = (new TimedSegmentSweep)->evaluate(
            $definition->segments,
            $session->current_segment_index === null ? null : (int) $session->current_segment_index,
            $session->current_segment_became_current_at === null ? null : new DateTimeImmutable((string) $session->current_segment_became_current_at),
            $session->current_segment_started_at === null ? null : new DateTimeImmutable((string) $session->current_segment_started_at),
            new DateTimeImmutable((string) $session->started_at),
            $receivedAt,
        );

        return $definition->currentSubtestItemRange($swept->index);
    }

    private function loadMutation(int $sessionId, string $mutationId): ?AssessmentAutosaveMutation
    {
        $row = DB::table('assessment_autosave_mutations')
            ->where('session_id', $sessionId)
            ->where('mutation_id', $mutationId)
            ->first();
        if ($row === null) {
            return null;
        }

        try {
            $itemNumbers = json_decode((string) $row->accepted_item_numbers, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The stored autosave receipt is invalid.', previous: $exception);
        }
        if (! is_array($itemNumbers) || ! array_is_list($itemNumbers)) {
            throw new RuntimeException('The stored autosave receipt is invalid.');
        }
        foreach ($itemNumbers as $itemNumber) {
            if (! is_int($itemNumber) || $itemNumber < 1) {
                throw new RuntimeException('The stored autosave receipt is invalid.');
            }
        }

        return new AssessmentAutosaveMutation(
            (string) $row->request_hash,
            new AssessmentAutosaveReceipt(
                (string) $row->mutation_id,
                (int) $row->revision,
                $itemNumbers,
                new DateTimeImmutable((string) $row->received_at),
            ),
        );
    }

    private function persistReceipt(int $sessionId, AssessmentAutosaveReceipt $receipt, string $hash): void
    {
        DB::table('assessment_autosave_mutations')->insert([
            'session_id' => $sessionId,
            'mutation_id' => $receipt->mutationId,
            'revision' => $receipt->revision,
            'request_hash' => $hash,
            'accepted_item_numbers' => json_encode($receipt->acceptedItemNumbers, JSON_THROW_ON_ERROR),
            'received_at' => $this->timestamp($receipt->receivedAt),
            'created_at' => $this->timestamp($receipt->receivedAt),
        ]);
    }

    /** @param array<int, mixed> $items */
    private function persistAnswers(int $sessionId, AssessmentAutosaveReceipt $receipt, array $items): void
    {
        $timestamp = $this->timestamp($receipt->receivedAt);
        $rows = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! isset($item['item_no']) || ! array_key_exists('value', $item)) {
                throw new RuntimeException('The accepted answer batch is invalid.');
            }
            $rows[] = [
                'session_id' => $sessionId,
                'item_no' => $item['item_no'],
                'value' => json_encode($item['value'], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                'revision' => $receipt->revision,
                'answered_at' => $timestamp,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        DB::table('answers')->upsert(
            $rows,
            ['session_id', 'item_no'],
            ['value', 'revision', 'answered_at', 'updated_at'],
        );
    }

    private function reject(?string $status, ?string $errorCode, ?int $currentRevision = null): AssessmentAutosaveResult
    {
        if ($errorCode === null) {
            throw new RuntimeException('A rejected assessment autosave requires an error code.');
        }

        return new AssessmentAutosaveResult(false, false, $status, $errorCode, currentRevision: $currentRevision);
    }

    private function timestamp(DateTimeImmutable $time): string
    {
        return $time->format('Y-m-d H:i:s.uP');
    }
}
