<?php

declare(strict_types=1);

namespace App\Actions\AssessmentSessions;

use App\Contracts\AssessmentItemContentAuthority;
use App\Domain\AssessmentSessions\AssessmentItemContentUnavailable;
use App\Domain\AssessmentSessions\AssessmentSessionStatus;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Domain\AssessmentSessions\TimedSegmentSweep;
use App\Domain\AssessmentSessions\UnsupportedGenericAssessmentInstrument;
use App\Security\RlsContextRunner;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use stdClass;

/**
 * F2 item-delivery (2026-09-21). GET /sessions/{id}/items. Read-only, same
 * boundary as every other session-http action: no write anywhere in this
 * class, runAsService() directly (no assertCleanOuterBoundary()), route
 * must exclude `rls`.
 *
 * Readable exactly when writable -- same rule as GetAssessmentSessionAnswers,
 * same reasoning: only while status is 'in_progress' AND the server clock
 * has not passed ends_at. 'created' -> SESSION_NOT_STARTED; 'submitted'/
 * 'scored'/'expired'/'void' -> SESSION_CLOSED; in_progress-in-storage but
 * past ends_at -> DEADLINE_EXCEEDED, matching AssessmentSessionDeadlinePolicy's
 * own split. Never writes -- no expiry sealing happens here.
 *
 * The session's SessionDefinition is reconstructed from its own immutable
 * stored snapshot (session_definition_payload), the same way
 * AllocateAndStartAssessmentSession::replay() does it -- never re-derived
 * from the catalog. The item-content authority IS consulted fresh on every
 * read (unlike replay(), which never calls it again after start): content
 * is not cached or assumed still available just because start succeeded,
 * so a reader that becomes unavailable after a session started still
 * fails this read closed rather than serving something stale or guessed.
 *
 * $item_content_variant (RMIB gender-track selection, 2026-09-21): read
 * verbatim from the session row and passed to contentFor() as
 * $lockedVariant on EVERY read -- this class never resolves a variant
 * itself and never re-derives it from current participant state. A reader
 * with a variant axis (RMIB) must use exactly this locked value, so a
 * profile change after the session started (e.g. a corrected gender) never
 * changes what a participant sees mid-test.
 *
 * $currentSegmentCode (item-delivery segment-awareness, per
 * tasks/handoffs/f2/item-delivery-segment-awareness-deferred.md): computed
 * fresh via TimedSegmentSweep on every read (see currentSegmentCode()
 * below), never persisted or trusted from a stored index -- an independent
 * axis from $lockedVariant above, a reader may need either, both, or
 * neither.
 */
final class GetAssessmentSessionItems
{
    private readonly Closure $clock;

    public function __construct(
        private readonly RlsContextRunner $contexts,
        private readonly AssessmentItemContentAuthority $itemContent,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now');
    }

    public function execute(int $participantId, string $sessionPublicId): GetAssessmentSessionItemsResult
    {
        if ($participantId < 1 || ! Str::isUlid($sessionPublicId)) {
            return $this->reject('SESSION_NOT_FOUND');
        }

        /** @var GetAssessmentSessionItemsResult $result */
        $result = $this->contexts->runAsService(
            fn (): GetAssessmentSessionItemsResult => $this->load($participantId, $sessionPublicId),
        );

        return $result;
    }

    private function load(int $participantId, string $sessionPublicId): GetAssessmentSessionItemsResult
    {
        $session = DB::table('test_sessions')
            ->where('public_id', $sessionPublicId)
            ->where('participant_id', $participantId)
            ->first();
        if ($session === null) {
            return $this->reject('SESSION_NOT_FOUND');
        }

        try {
            $instrument = GenericAssessmentInstrument::fromExternal((string) $session->test_type);
        } catch (UnsupportedGenericAssessmentInstrument) {
            return $this->reject('SESSION_NOT_FOUND');
        }

        $status = AssessmentSessionStatus::tryFrom((string) $session->status)
            ?? throw new RuntimeException('The persisted assessment session status is invalid.');

        if ($status === AssessmentSessionStatus::Created) {
            return $this->reject('SESSION_NOT_STARTED');
        }

        if ($status !== AssessmentSessionStatus::InProgress) {
            return $this->reject('SESSION_CLOSED');
        }

        if ($session->ends_at === null) {
            throw new RuntimeException('An in-progress session requires ends_at.');
        }
        $endsAt = $this->utc(new DateTimeImmutable((string) $session->ends_at));
        $serverTime = $this->serverTime();
        if ($serverTime > $endsAt) {
            return $this->reject('DEADLINE_EXCEEDED');
        }

        $definition = $this->storedDefinition($session);
        if ($definition->instrument !== $instrument) {
            throw new RuntimeException('Stored session definition instrument disagrees with the session.');
        }

        $lockedVariant = $session->item_content_variant ?? null;
        if ($lockedVariant !== null && ! is_string($lockedVariant)) {
            throw new RuntimeException('The persisted item content variant is invalid.');
        }
        $currentSegmentCode = $this->currentSegmentCode($session, $definition, $serverTime);

        try {
            $content = $this->itemContent->contentFor(
                $instrument,
                $definition,
                $participantId,
                $lockedVariant,
                $currentSegmentCode,
            );
        } catch (AssessmentItemContentUnavailable) {
            return $this->reject('ASSESSMENT_ITEM_CONTENT_UNAVAILABLE');
        }
        if ($content->instrument !== $instrument) {
            throw new RuntimeException('Item content authority returned the wrong instrument.');
        }

        return new GetAssessmentSessionItemsResult(true, null, $sessionPublicId, $content);
    }

    /**
     * F2 item-delivery segment-awareness (per
     * tasks/handoffs/f2/item-delivery-segment-awareness-deferred.md).
     * Compute-only, fresh on every request -- never trusts a stored index,
     * the same reason TimedSegmentSweep exists and the same pattern
     * AutosaveAssessmentAnswers/SubtestNext/GetAssessmentSession's own
     * resource already follow. started_at is guaranteed non-null here: the
     * caller already rejected anything but an in_progress session above.
     */
    private function currentSegmentCode(stdClass $session, SessionDefinition $definition, DateTimeImmutable $now): string
    {
        if ($session->started_at === null) {
            throw new RuntimeException('An in-progress session requires started_at.');
        }

        $swept = (new TimedSegmentSweep)->evaluate(
            $definition->segments,
            $session->current_segment_index === null ? null : (int) $session->current_segment_index,
            $session->current_segment_became_current_at === null ? null : new DateTimeImmutable((string) $session->current_segment_became_current_at),
            $session->current_segment_started_at === null ? null : new DateTimeImmutable((string) $session->current_segment_started_at),
            new DateTimeImmutable((string) $session->started_at),
            $now,
        );

        return $definition->segments[$swept->index]->code;
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

    private function reject(string $errorCode): GetAssessmentSessionItemsResult
    {
        return new GetAssessmentSessionItemsResult(false, $errorCode);
    }

    private function serverTime(): DateTimeImmutable
    {
        $time = ($this->clock)();
        if (! $time instanceof DateTimeImmutable) {
            throw new RuntimeException('The assessment session items read clock must return DateTimeImmutable.');
        }

        return $this->utc($time);
    }

    private function utc(DateTimeImmutable $time): DateTimeImmutable
    {
        return $time->setTimezone(new DateTimeZone('UTC'));
    }
}
