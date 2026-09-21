<?php

declare(strict_types=1);

namespace App\Actions\AssessmentSessions;

use App\Contracts\AssessmentItemContentAuthority;
use App\Domain\AssessmentSessions\AssessmentItemContentUnavailable;
use App\Domain\AssessmentSessions\AssessmentSessionStatus;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\SessionDefinition;
use App\Domain\AssessmentSessions\UnsupportedGenericAssessmentInstrument;
use App\Security\RlsContextRunner;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

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
        if ($this->serverTime() > $endsAt) {
            return $this->reject('DEADLINE_EXCEEDED');
        }

        $definition = $this->storedDefinition($session);
        if ($definition->instrument !== $instrument) {
            throw new RuntimeException('Stored session definition instrument disagrees with the session.');
        }

        try {
            $content = $this->itemContent->contentFor($instrument, $definition);
        } catch (AssessmentItemContentUnavailable) {
            return $this->reject('ASSESSMENT_ITEM_CONTENT_UNAVAILABLE');
        }
        if ($content->instrument !== $instrument) {
            throw new RuntimeException('Item content authority returned the wrong instrument.');
        }

        return new GetAssessmentSessionItemsResult(true, null, $sessionPublicId, $content);
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
