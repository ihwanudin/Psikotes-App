<?php

declare(strict_types=1);

namespace App\Actions\AssessmentSessions;

use App\Actions\AssessmentResults\ScoreAssessmentSession;
use App\Contracts\SealsExpiredAssessmentSessions;
use App\Domain\AssessmentSessions\AssessmentSessionStatus;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\UnsupportedGenericAssessmentInstrument;
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
 * ADR-0032 §1(b) (2026-09-23, psychologist P4): now also scores the session
 * (IST/PAPI/RMIB; Kraepelin has its own pipeline), inside the SAME
 * transaction as the status write -- `scoreIfSupported()` below, which
 * replaces what used to be a documented-empty extension point. This makes
 * every caller of `sealWithinTransaction()` (autosave/submit's lazy
 * discovery of an overdue session, and the sweep command via `seal()`,
 * which wraps this in its own transaction) score consistently, not just
 * the dedicated sweep.
 */
final class SealExpiredAssessmentSession implements SealsExpiredAssessmentSessions
{
    public function __construct(
        private readonly RlsContextRunner $contexts,
        private readonly ScoreAssessmentSession $scorer,
    ) {}

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

        $this->scoreIfSupported($sessionId);
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
     * ADR-0032 §1's atomicity requirement: a scoring attempt and its
     * outcome (success or failure) must land in the SAME transaction as
     * the status transition that triggered it -- this runs right after the
     * UPDATE above, inside the caller's transaction, never a separate one
     * that could leave the two inconsistent.
     *
     * Kraepelin is skipped (its own pipeline, out of ADR-0032's scope).
     * `test_type` is re-read here rather than threaded through by every
     * caller -- it never changes for a session's lifetime, so an unlocked
     * read is safe, and it keeps "which instruments get scored on expiry"
     * a decision this one class owns rather than something each of the
     * three callers has to remember to pass correctly.
     */
    private function scoreIfSupported(int $sessionId): void
    {
        $testType = DB::table('test_sessions')->where('id', $sessionId)->value('test_type');
        if (! is_string($testType)) {
            throw new RuntimeException('The sealed assessment session is missing its test_type.');
        }

        try {
            $instrument = GenericAssessmentInstrument::fromExternal($testType);
        } catch (UnsupportedGenericAssessmentInstrument) {
            return;
        }

        if (ScoreAssessmentSession::scores($instrument)) {
            $this->scorer->execute($sessionId, $instrument);
        }
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
