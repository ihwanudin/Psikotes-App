<?php

declare(strict_types=1);

namespace App\Actions\AssessmentSessions;

use App\Domain\AssessmentSessions\AssessmentSessionStatus;
use App\Domain\AssessmentSessions\AssessmentSessionSubmitPolicy;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\UnsupportedGenericAssessmentInstrument;
use App\Security\RlsContextRunner;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class SubmitAssessmentSession
{
    private readonly Closure $clock;

    public function __construct(
        private readonly RlsContextRunner $contexts,
        private readonly AssessmentSessionSubmitPolicy $policy,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now');
    }

    public function execute(int $participantId, string $sessionPublicId): AssessmentSessionSubmitResult
    {
        if ($participantId < 1 || ! Str::isUlid($sessionPublicId)) {
            return $this->notFound();
        }

        /** @var AssessmentSessionSubmitResult $result */
        $result = $this->contexts->runAsService(fn (): AssessmentSessionSubmitResult => DB::transaction(
            fn (): AssessmentSessionSubmitResult => $this->withinTransaction($participantId, $sessionPublicId),
        ));

        return $result;
    }

    private function withinTransaction(int $participantId, string $sessionPublicId): AssessmentSessionSubmitResult
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
            throw new RuntimeException('The assessment submit clock must return DateTimeImmutable.');
        }
        $serverTime = $this->utc($receivedAt);
        $status = AssessmentSessionStatus::tryFrom((string) $session->status)
            ?? throw new RuntimeException('The persisted assessment session status is invalid.');
        $endsAt = $this->date($session->ends_at);
        $submittedAt = $this->date($session->submitted_at);
        $decision = $this->policy->decide($status, $endsAt, $serverTime);

        if ($decision->replayed) {
            if ($submittedAt === null) {
                throw new RuntimeException('A submitted assessment session requires submitted_at evidence.');
            }

            return $this->result($sessionPublicId, $decision->status, $submittedAt,
                (int) $session->answers_revision, $serverTime, true);
        }

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
                    throw new RuntimeException('The overdue assessment session could not be sealed.');
                }
            }

            return $this->result(
                $sessionPublicId,
                $decision->status,
                $submittedAt,
                (int) $session->answers_revision,
                $serverTime,
                false,
                $decision->errorCode?->value,
            );
        }

        $updated = DB::table('test_sessions')->where('id', $session->id)
            ->where('status', AssessmentSessionStatus::InProgress->value)
            ->update([
                'status' => AssessmentSessionStatus::Submitted->value,
                'submitted_at' => $this->timestamp($receivedAt),
                'updated_at' => $this->timestamp($receivedAt),
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('The assessment session submission could not be committed.');
        }

        return $this->result(
            $sessionPublicId,
            AssessmentSessionStatus::Submitted,
            $serverTime,
            (int) $session->answers_revision,
            $serverTime,
            false,
        );
    }

    private function result(
        string $sessionId,
        AssessmentSessionStatus $status,
        ?DateTimeImmutable $submittedAt,
        int $answersRevision,
        DateTimeImmutable $serverTime,
        bool $replayed,
        ?string $errorCode = null,
    ): AssessmentSessionSubmitResult {
        return new AssessmentSessionSubmitResult(
            $errorCode === null,
            $replayed,
            $errorCode,
            $sessionId,
            $status->value,
            $submittedAt,
            $answersRevision,
            $serverTime,
        );
    }

    private function notFound(): AssessmentSessionSubmitResult
    {
        $now = ($this->clock)();
        if (! $now instanceof DateTimeImmutable) {
            throw new RuntimeException('The assessment submit clock must return DateTimeImmutable.');
        }

        return new AssessmentSessionSubmitResult(
            false,
            false,
            'SESSION_NOT_FOUND',
            null,
            null,
            null,
            null,
            $this->utc($now),
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
