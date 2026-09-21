<?php

declare(strict_types=1);

namespace App\Actions\AssessmentSessions;

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
 * F2 session-http (2026-09-21). GET /sessions/{id} (resume). Read-only:
 * no write anywhere in this class. A session that does not exist and a
 * session that belongs to a different participant are structurally
 * indistinguishable here (same WHERE clause, same empty result) -- not a
 * behaviour this class has to remember to preserve, a query shape that
 * cannot produce anything else. Same reasoning as
 * AutosaveAssessmentAnswers/SubmitAssessmentSession: no
 * assertCleanOuterBoundary(), just runAsService() directly, which itself
 * throws if a participant RLS context is already active -- see
 * RlsContextRunner::runAsService(). The route must exclude `rls` for the
 * same reason as those two.
 */
final class GetAssessmentSession
{
    private readonly Closure $clock;

    public function __construct(
        private readonly RlsContextRunner $contexts,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now');
    }

    public function execute(int $participantId, string $sessionPublicId): GetAssessmentSessionResult
    {
        if ($participantId < 1 || ! Str::isUlid($sessionPublicId)) {
            return new GetAssessmentSessionResult(false);
        }

        /** @var GetAssessmentSessionResult $result */
        $result = $this->contexts->runAsService(
            fn (): GetAssessmentSessionResult => $this->load($participantId, $sessionPublicId),
        );

        return $result;
    }

    private function load(int $participantId, string $sessionPublicId): GetAssessmentSessionResult
    {
        $session = DB::table('test_sessions')
            ->where('public_id', $sessionPublicId)
            ->where('participant_id', $participantId)
            ->first();
        if ($session === null) {
            return new GetAssessmentSessionResult(false);
        }

        try {
            $instrument = GenericAssessmentInstrument::fromExternal((string) $session->test_type);
        } catch (UnsupportedGenericAssessmentInstrument) {
            return new GetAssessmentSessionResult(false);
        }

        $status = AssessmentSessionStatus::tryFrom((string) $session->status)
            ?? throw new RuntimeException('The persisted assessment session status is invalid.');

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
        $definition = SessionDefinition::fromArray($decoded);

        $serverTime = $this->serverTime();
        $endsAt = $this->date($session->ends_at);
        $remainingSeconds = 0;
        if ($status === AssessmentSessionStatus::InProgress && $endsAt !== null) {
            $remainingSeconds = max(0, (int) ceil(
                (float) $endsAt->format('U.u') - (float) $serverTime->format('U.u'),
            ));
        }

        return new GetAssessmentSessionResult(true, new AssessmentSessionSnapshot(
            $sessionPublicId,
            $instrument,
            $status,
            (int) $session->attempt_no,
            (int) $session->answers_revision,
            $this->date($session->started_at),
            $endsAt,
            $this->date($session->submitted_at),
            $serverTime,
            $remainingSeconds,
            $definition,
        ));
    }

    private function serverTime(): DateTimeImmutable
    {
        $time = ($this->clock)();
        if (! $time instanceof DateTimeImmutable) {
            throw new RuntimeException('The assessment session read clock must return DateTimeImmutable.');
        }

        return $this->utc($time);
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        return $value === null ? null : $this->utc(new DateTimeImmutable((string) $value));
    }

    private function utc(DateTimeImmutable $time): DateTimeImmutable
    {
        return $time->setTimezone(new DateTimeZone('UTC'));
    }
}
