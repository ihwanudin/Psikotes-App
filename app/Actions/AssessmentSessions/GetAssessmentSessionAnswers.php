<?php

declare(strict_types=1);

namespace App\Actions\AssessmentSessions;

use App\Domain\AssessmentSessions\AssessmentSessionStatus;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\UnsupportedGenericAssessmentInstrument;
use App\Security\RlsContextRunner;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * F2 session-answers-readback (2026-09-21). GET /sessions/{id}/answers.
 * Read-only, same as GetAssessmentSession: no write anywhere in this class,
 * runAsService() directly (no assertCleanOuterBoundary()), route must
 * exclude `rls` for the same reason as the other four session routes.
 *
 * Readable exactly when writable (Lead sign-off, 2026-09-21): only while
 * status is 'in_progress' AND the server clock has not passed ends_at.
 * 'created' -> SESSION_NOT_STARTED (nothing to read yet); 'submitted'/
 * 'scored'/'expired'/'void' -> SESSION_CLOSED; in_progress-in-storage but
 * past ends_at -> DEADLINE_EXCEEDED, the same lazy-detection code
 * AssessmentSessionDeadlinePolicy::evaluateAnswerWrite() uses for the
 * identical situation on the write side. This class never seals an
 * overdue session to 'expired' itself -- that stays the write path's job
 * (AutosaveAssessmentAnswers/SubmitAssessmentSession); a read endpoint
 * with a side effect would complicate reasoning about races for no
 * benefit here.
 *
 * revision/answers consistency: session columns and answer rows are read
 * in a single statement (LEFT JOIN), not two separate SELECTs. PostgreSQL
 * READ COMMITTED gives a fresh snapshot per *statement*, not per
 * transaction, so two separate SELECTs could observe a concurrent
 * autosave's commit landing in between them -- an answers_revision that
 * doesn't match the answers actually returned. A single statement is
 * guaranteed one consistent snapshot for its whole execution.
 */
final class GetAssessmentSessionAnswers
{
    private readonly Closure $clock;

    public function __construct(
        private readonly RlsContextRunner $contexts,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now');
    }

    public function execute(int $participantId, string $sessionPublicId): GetAssessmentSessionAnswersResult
    {
        if ($participantId < 1 || ! Str::isUlid($sessionPublicId)) {
            return $this->reject('SESSION_NOT_FOUND');
        }

        /** @var GetAssessmentSessionAnswersResult $result */
        $result = $this->contexts->runAsService(
            fn (): GetAssessmentSessionAnswersResult => $this->load($participantId, $sessionPublicId),
        );

        return $result;
    }

    private function load(int $participantId, string $sessionPublicId): GetAssessmentSessionAnswersResult
    {
        $rows = DB::table('test_sessions as s')
            ->leftJoin('answers as a', 'a.session_id', '=', 's.id')
            ->where('s.public_id', $sessionPublicId)
            ->where('s.participant_id', $participantId)
            ->orderBy('a.item_no')
            ->get(['s.test_type', 's.status', 's.ends_at', 's.answers_revision', 'a.item_no', 'a.value']);

        if ($rows->isEmpty()) {
            return $this->reject('SESSION_NOT_FOUND');
        }

        $first = $rows->first();

        try {
            GenericAssessmentInstrument::fromExternal((string) $first->test_type);
        } catch (UnsupportedGenericAssessmentInstrument) {
            return $this->reject('SESSION_NOT_FOUND');
        }

        $status = AssessmentSessionStatus::tryFrom((string) $first->status)
            ?? throw new RuntimeException('The persisted assessment session status is invalid.');

        if ($status === AssessmentSessionStatus::Created) {
            return $this->reject('SESSION_NOT_STARTED');
        }

        if ($status !== AssessmentSessionStatus::InProgress) {
            return $this->reject('SESSION_CLOSED');
        }

        if ($first->ends_at === null) {
            throw new RuntimeException('An in-progress session requires ends_at.');
        }
        $endsAt = $this->utc(new DateTimeImmutable((string) $first->ends_at));
        if ($this->serverTime() > $endsAt) {
            return $this->reject('DEADLINE_EXCEEDED');
        }

        $answers = [];
        foreach ($rows as $row) {
            if ($row->item_no === null) {
                continue;
            }
            $answers[] = [
                'item_no' => (int) $row->item_no,
                'value' => json_decode((string) $row->value, true, flags: JSON_THROW_ON_ERROR),
            ];
        }

        return new GetAssessmentSessionAnswersResult(
            true,
            null,
            $sessionPublicId,
            (int) $first->answers_revision,
            $answers,
        );
    }

    private function reject(string $errorCode): GetAssessmentSessionAnswersResult
    {
        return new GetAssessmentSessionAnswersResult(false, $errorCode);
    }

    private function serverTime(): DateTimeImmutable
    {
        $time = ($this->clock)();
        if (! $time instanceof DateTimeImmutable) {
            throw new RuntimeException('The assessment session answers read clock must return DateTimeImmutable.');
        }

        return $this->utc($time);
    }

    private function utc(DateTimeImmutable $time): DateTimeImmutable
    {
        return $time->setTimezone(new DateTimeZone('UTC'));
    }
}
