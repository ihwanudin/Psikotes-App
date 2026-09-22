<?php

declare(strict_types=1);

namespace App\Actions\AssessmentSessions;

use App\Domain\AssessmentSessions\AssessmentSessionStatus;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Domain\AssessmentSessions\TimedSegmentSweep;
use App\Domain\AssessmentSessions\TimedSegmentTransitionPolicy;
use App\Domain\AssessmentSessions\UnsupportedGenericAssessmentInstrument;
use App\Security\RlsContextRunner;
use Closure;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

/**
 * F2 timed-segments stage 4 (2026-09-22). POST /sessions/:id/subtest/next.
 * Locks the test_sessions row (lockForUpdate, same reasoning as F5's
 * ReportSigningService::sign() and every other session write path here):
 * two near-simultaneous calls for the same session must serialize, not
 * both read the same stored segment state and both decide independently.
 *
 * Sweeps to the scheduled current segment state first (TimedSegmentSweep,
 * fresh from whatever is currently persisted -- never trusts a stale
 * stored index), THEN asks TimedSegmentTransitionPolicy what this specific
 * call means from there, all under the one row lock -- the sweep and the
 * decision must see the same locked snapshot, or a concurrent call could
 * observe a half-applied state.
 *
 * Idempotency note (Lead's explicit requirement, 2026-09-22): with
 * allow_early_finish=false -- the only configuration any real segment data
 * uses today, P10 still open -- two near-simultaneous calls are already
 * idempotent by construction: the first advances/starts, the row lock
 * serializes the second behind it, and the second's own fresh sweep+policy
 * evaluation correctly rejects (INVALID_SESSION_TRANSITION) rather than
 * repeating the first call's effect, because the now-current segment is
 * either still waiting on a reading gap it already started (no-op-shaped,
 * but confirming an already-started segment is not the same call) or mid-
 * timed-window with nothing to advance into. A true allow_early_finish=true
 * segment could see two genuine, indistinguishable "next" requests both
 * legitimately advance (once serialized) -- this endpoint carries no
 * client-supplied idempotency key the way autosave's mutation_id does, so
 * that specific double-advance is a real open question for whenever P10
 * actually ships allow_early_finish=true data, not solved here.
 */
final class SubtestNext
{
    private readonly Closure $clock;

    public function __construct(
        private readonly RlsContextRunner $contexts,
        private readonly TimedSegmentTransitionPolicy $policy,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now');
    }

    public function execute(int $participantId, string $sessionPublicId): SubtestNextResult
    {
        if ($participantId < 1 || ! Str::isUlid($sessionPublicId)) {
            return $this->reject(null, 'SESSION_NOT_FOUND');
        }

        /** @var SubtestNextResult $result */
        $result = $this->contexts->runAsService(fn (): SubtestNextResult => DB::transaction(
            fn (): SubtestNextResult => $this->withinTransaction($participantId, $sessionPublicId),
        ));

        return $result;
    }

    private function withinTransaction(int $participantId, string $sessionPublicId): SubtestNextResult
    {
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

        $now = ($this->clock)();
        if (! $now instanceof DateTimeImmutable) {
            throw new RuntimeException('The subtest/next clock must return DateTimeImmutable.');
        }

        $definition = $this->storedDefinition($session);
        $sessionEndsAt = $session->ends_at === null ? null : new DateTimeImmutable((string) $session->ends_at);
        $sessionStartedAt = $session->started_at === null
            ? throw new RuntimeException('An in-progress session requires started_at.')
            : new DateTimeImmutable((string) $session->started_at);

        $swept = (new TimedSegmentSweep)->evaluate(
            $definition->segments,
            $session->current_segment_index === null ? null : (int) $session->current_segment_index,
            $session->current_segment_became_current_at === null ? null : new DateTimeImmutable((string) $session->current_segment_became_current_at),
            $session->current_segment_started_at === null ? null : new DateTimeImmutable((string) $session->current_segment_started_at),
            $sessionStartedAt,
            $now,
        );

        $decision = $this->policy->decide($status, $sessionEndsAt, $now, $definition->segments, $swept);

        if (! $decision->accepted) {
            if ($decision->status === AssessmentSessionStatus::Expired && $status === AssessmentSessionStatus::InProgress) {
                $updated = DB::table('test_sessions')->where('id', $session->id)
                    ->where('status', AssessmentSessionStatus::InProgress->value)
                    ->update([
                        'status' => AssessmentSessionStatus::Expired->value,
                        'expired_at' => $this->timestamp($now),
                        'updated_at' => $this->timestamp($now),
                    ]);
                if ($updated !== 1) {
                    throw new RuntimeException('The expired assessment session could not be sealed.');
                }
            }

            return $this->reject($decision->status->value, $decision->errorCode?->value);
        }

        $updated = DB::table('test_sessions')
            ->where('id', $session->id)
            ->where('status', AssessmentSessionStatus::InProgress->value)
            ->update([
                'current_segment_index' => $decision->index,
                'current_segment_became_current_at' => $this->timestamp($decision->becameCurrentAt),
                'current_segment_started_at' => $decision->startedAt === null ? null : $this->timestamp($decision->startedAt),
                'updated_at' => $this->timestamp($now),
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('The timed segment transition could not be committed.');
        }

        return new SubtestNextResult(
            true,
            $status->value,
            null,
            $decision->index,
            $decision->becameCurrentAt === null ? null : $this->rfc3339($decision->becameCurrentAt),
            $decision->startedAt === null ? null : $this->rfc3339($decision->startedAt),
        );
    }

    private function storedDefinition(object $session): SessionDefinition
    {
        $definitionPayload = $session->session_definition_payload ?? null;
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

    private function reject(?string $status, ?string $errorCode): SubtestNextResult
    {
        if ($errorCode === null) {
            throw new RuntimeException('A rejected subtest/next requires an error code.');
        }

        return new SubtestNextResult(false, $status, $errorCode);
    }

    private function timestamp(DateTimeImmutable $time): string
    {
        return $time->format('Y-m-d H:i:s.uP');
    }

    private function rfc3339(DateTimeImmutable $time): string
    {
        return $time->format('Y-m-d\TH:i:s.up');
    }
}
