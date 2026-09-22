<?php

declare(strict_types=1);

namespace App\Actions\AssessmentSessions;

use App\Contracts\SealsExpiredAssessmentSessions;
use App\Domain\AssessmentSessions\AssessmentSessionStatus;
use App\Security\RlsContextRunner;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

/**
 * F2 (2026-09-21). The ONE place that transitions an overdue `in_progress`
 * session to `expired`. Extracted from `AutosaveAssessmentAnswers` and
 * `SubmitAssessmentSession`, which used to carry byte-identical copies of
 * this exact block -- a sweep command needed a third caller, and Lead's
 * explicit instruction was to eliminate the duplication rather than add a
 * third copy.
 *
 * This class does NOT decide whether a session is overdue -- callers do
 * that (via `AssessmentSessionDeadlinePolicy` for autosave/submit, or a
 * direct `ends_at < now()` query for the sweep command) and hand this
 * class only a session id already known to be `in_progress` and past due.
 * `sealWithinTransaction()` re-checks `status = 'in_progress'` in its own
 * `UPDATE ... WHERE` anyway (never trusts the caller's read), so a
 * concurrent writer that already transitioned the row loses this update
 * with a clear failure rather than corrupting anything.
 *
 * Deliberately does NOT invoke any scoring/sealing pipeline -- this is
 * purely the `in_progress -> expired` status transition, the same status
 * write autosave/submit already performed inline. Whether (and how) an
 * expired session eventually gets scored is ADR-0032's open question
 * (Titik A), not decided here. See `hookForFutureOrchestrator()` below for
 * the documented, currently-empty extension point.
 */
final class SealExpiredAssessmentSession implements SealsExpiredAssessmentSessions
{
    public function __construct(private readonly RlsContextRunner $contexts) {}

    /**
     * For callers that already own a service-context transaction
     * (`AutosaveAssessmentAnswers`, `SubmitAssessmentSession`) -- does not
     * open a new one itself, so the seal is part of the caller's existing
     * atomic unit of work.
     */
    public function sealWithinTransaction(int $sessionId, DateTimeImmutable $sealedAt): void
    {
        if ($this->contexts->current()?->role !== 'service' || DB::transactionLevel() < 1) {
            throw new LogicException('SEAL_EXPIRED_ASSESSMENT_SESSION_CONTEXT_REQUIRED');
        }

        $updated = DB::table('test_sessions')->where('id', $sessionId)
            ->where('status', AssessmentSessionStatus::InProgress->value)
            ->update([
                'status' => AssessmentSessionStatus::Expired->value,
                'expired_at' => $this->timestamp($sealedAt),
                'updated_at' => $this->timestamp($sealedAt),
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('The overdue assessment session could not be sealed.');
        }

        $this->hookForFutureOrchestrator($sessionId, $sealedAt);
    }

    /**
     * For callers with no existing transaction (the sweep command, one
     * call per candidate session) -- owns its own service-context
     * transaction, matching the `execute()` pattern every other
     * session-write action in this lane already uses.
     */
    public function seal(int $sessionId, DateTimeImmutable $sealedAt): void
    {
        $this->contexts->runAsService(fn () => DB::transaction(
            fn () => $this->sealWithinTransaction($sessionId, $sealedAt),
        ));
    }

    /**
     * Documented empty hook (ADR-0032, Titik A -- not yet answered by the
     * project owner/psychologist): if expired sessions are ever approved
     * for scoring, this is where a caller would be notified that a
     * session just sealed to `expired`, INSIDE the same transaction as
     * the seal itself (per ADR-0032 §1's atomicity requirement: a scoring
     * attempt and its outcome, success or failure, must land in the same
     * transaction as the status transition that triggered it -- never a
     * separate one that could leave the two inconsistent). Deliberately a
     * no-op today; do not wire a real call here without ADR-0032's Titik
     * A/B being answered first.
     */
    private function hookForFutureOrchestrator(int $sessionId, DateTimeImmutable $sealedAt): void
    {
        // Intentionally empty.
    }

    /**
     * Deliberately does NOT normalize to UTC -- neither original inline
     * block did (`AutosaveAssessmentAnswers`/`SubmitAssessmentSession`
     * both just format the clock's value as-is, preserving whatever
     * offset it already carries). This matters beyond style: the SQLite
     * `test_sessions_contract_update` trigger's `expired_at > ends_at`
     * check is a lexicographic STRING comparison, not a real datetime
     * comparison -- it only holds if `expired_at` is written in the same
     * offset `ends_at` was stored in. An earlier draft of this class
     * force-converted to UTC here and broke that comparison across a day
     * boundary (`AutosaveAssessmentAnswersTest`'s +07:00 fixture caught
     * it); the fix was to match the original behavior exactly, not to
     * "correct" it.
     */
    private function timestamp(DateTimeImmutable $time): string
    {
        return $time->format('Y-m-d H:i:s.uP');
    }
}
