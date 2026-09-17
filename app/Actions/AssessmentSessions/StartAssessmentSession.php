<?php

declare(strict_types=1);

namespace App\Actions\AssessmentSessions;

use App\Domain\AssessmentSessions\AssessmentSessionDeadlinePolicy;
use App\Domain\AssessmentSessions\AssessmentSessionStartDecision;
use App\Domain\AssessmentSessions\AssessmentSessionStateMachine;
use App\Domain\AssessmentSessions\AssessmentSessionStatus;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\InvalidAssessmentSessionTransition;
use App\Domain\AssessmentSessions\UnsupportedGenericAssessmentInstrument;
use App\Security\RlsContextRunner;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class StartAssessmentSession
{
    private readonly Closure $clock;

    public function __construct(
        private readonly RlsContextRunner $contexts,
        private readonly AssessmentSessionStateMachine $stateMachine,
        private readonly AssessmentSessionDeadlinePolicy $deadlinePolicy,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now');
    }

    public function execute(int $participantId, string $sessionPublicId): AssessmentSessionStartResult
    {
        if ($participantId < 1 || ! Str::isUlid($sessionPublicId)) {
            return $this->notFound();
        }

        /** @var AssessmentSessionStartResult $result */
        $result = $this->contexts->runAsService(fn (): AssessmentSessionStartResult => DB::transaction(
            fn (): AssessmentSessionStartResult => $this->withinTransaction($participantId, $sessionPublicId),
        ));

        return $result;
    }

    private function withinTransaction(int $participantId, string $sessionPublicId): AssessmentSessionStartResult
    {
        $session = DB::table('test_sessions')
            ->where('public_id', $sessionPublicId)
            ->where('participant_id', $participantId)
            ->lockForUpdate()
            ->first();
        if ($session === null) {
            return $this->notFound();
        }

        try {
            GenericAssessmentInstrument::fromExternal((string) $session->test_type);
        } catch (UnsupportedGenericAssessmentInstrument) {
            return $this->notFound();
        }

        $receivedAt = ($this->clock)();
        if (! $receivedAt instanceof DateTimeImmutable) {
            throw new RuntimeException('The assessment session clock must return DateTimeImmutable.');
        }
        $serverTime = $this->utc($receivedAt);
        $status = AssessmentSessionStatus::tryFrom((string) $session->status)
            ?? throw new RuntimeException('The persisted assessment session status is invalid.');
        $startedAt = $this->date($session->started_at);
        $endsAt = $this->date($session->ends_at);
        $submittedAt = $this->date($session->submitted_at);

        if ($status === AssessmentSessionStatus::InProgress) {
            $deadline = $this->deadlinePolicy->evaluateAnswerWrite($status, $endsAt, $serverTime);
            if (! $deadline->accepted) {
                $updated = DB::table('test_sessions')->where('id', $session->id)
                    ->where('status', AssessmentSessionStatus::InProgress->value)
                    ->update([
                        'status' => AssessmentSessionStatus::Expired->value,
                        'expired_at' => $this->timestamp($receivedAt),
                        'updated_at' => $this->timestamp($receivedAt),
                    ]);
                if ($updated !== 1) {
                    throw new RuntimeException('The overdue assessment session could not be sealed.');
                }

                return $this->result(
                    (string) $session->public_id,
                    (string) $session->test_type,
                    (int) $session->attempt_no,
                    (int) $session->answers_revision,
                    AssessmentSessionStatus::Expired,
                    $startedAt,
                    $endsAt,
                    $submittedAt,
                    $serverTime,
                    false,
                    $deadline->errorCode?->value,
                );
            }
        }

        try {
            $decision = $this->stateMachine->start(
                $status,
                $startedAt,
                $endsAt,
                $serverTime,
                (int) $session->duration_seconds,
            );
        } catch (InvalidAssessmentSessionTransition) {
            return $this->result(
                (string) $session->public_id,
                (string) $session->test_type,
                (int) $session->attempt_no,
                (int) $session->answers_revision,
                $status,
                $startedAt,
                $endsAt,
                $submittedAt,
                $serverTime,
                false,
                'SESSION_CLOSED',
            );
        }

        $replayed = $status === AssessmentSessionStatus::InProgress;
        if (! $replayed) {
            $this->persistStart((int) $session->id, $decision, $serverTime);
        }

        return $this->result(
            (string) $session->public_id,
            (string) $session->test_type,
            (int) $session->attempt_no,
            (int) $session->answers_revision,
            $decision->status,
            $decision->startedAt,
            $decision->endsAt,
            $submittedAt,
            $serverTime,
            $replayed,
        );
    }

    private function persistStart(
        int $sessionId,
        AssessmentSessionStartDecision $decision,
        DateTimeImmutable $serverTime,
    ): void {
        $updated = DB::table('test_sessions')->where('id', $sessionId)
            ->where('status', AssessmentSessionStatus::Created->value)
            ->update([
                'status' => $decision->status->value,
                'started_at' => $this->timestamp($decision->startedAt),
                'ends_at' => $this->timestamp($decision->endsAt),
                'updated_at' => $this->timestamp($serverTime),
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('The assessment session start could not be committed.');
        }
    }

    private function result(
        string $sessionId,
        string $testType,
        int $attemptNo,
        int $answersRevision,
        AssessmentSessionStatus $status,
        ?DateTimeImmutable $startedAt,
        ?DateTimeImmutable $endsAt,
        ?DateTimeImmutable $submittedAt,
        DateTimeImmutable $serverTime,
        bool $replayed,
        ?string $errorCode = null,
    ): AssessmentSessionStartResult {
        return new AssessmentSessionStartResult(
            $errorCode === null,
            $replayed,
            $errorCode,
            $sessionId,
            $testType,
            $status->value,
            $attemptNo,
            $startedAt,
            $endsAt,
            $endsAt,
            $submittedAt,
            $serverTime,
            $status === AssessmentSessionStatus::InProgress && $endsAt !== null
                ? max(0, (int) ceil((float) $endsAt->format('U.u') - (float) $serverTime->format('U.u')))
                : 0,
            $answersRevision,
        );
    }

    private function notFound(): AssessmentSessionStartResult
    {
        $now = ($this->clock)();
        if (! $now instanceof DateTimeImmutable) {
            throw new RuntimeException('The assessment session clock must return DateTimeImmutable.');
        }

        return new AssessmentSessionStartResult(
            false,
            false,
            'SESSION_NOT_FOUND',
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            $this->utc($now),
            0,
            null,
        );
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        return $value === null ? null : $this->utc(new DateTimeImmutable((string) $value));
    }

    private function utc(DateTimeImmutable $time): DateTimeImmutable
    {
        return $time->setTimezone(new DateTimeZone('UTC'));
    }

    private function timestamp(DateTimeImmutable $time): string
    {
        return $time->format('Y-m-d H:i:s.uP');
    }
}
