<?php

declare(strict_types=1);

namespace App\Actions\AssessmentSessions;

use App\Contracts\SealsExpiredAssessmentSessions;
use App\Domain\AssessmentSessions\AssessmentSessionStatus;
use App\Security\RlsContextRunner;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * F2 (2026-09-21). Housekeeping-only: finds `in_progress` sessions whose
 * `ends_at` has already passed and seals each to `expired`, via
 * `SealExpiredAssessmentSession` (the same transition autosave/submit use
 * when they discover expiry incidentally while handling an unrelated
 * write). Exists because that discovery is lazy -- it only happens on the
 * NEXT autosave/submit request. A participant who abandons a session and
 * never sends another request leaves it `in_progress` in the database
 * forever, not just "unscored": even the status transition itself never
 * happens without this sweep.
 *
 * Deliberately does NOT score anything (see `SealExpiredAssessmentSession`'s
 * own docblock) -- ADR-0032's Titik A (whether expired sessions may ever be
 * scored) is not decided yet.
 *
 * Each candidate session is sealed in its OWN service-context transaction
 * (`SealExpiredAssessmentSession::seal()`), not one transaction for the
 * whole batch: a failure sealing one session (e.g. a concurrent writer won
 * the race first) is caught and reported, and the sweep continues to the
 * next candidate rather than aborting the batch.
 */
final class SweepExpiredAssessmentSessions
{
    private readonly Closure $clock;

    public function __construct(
        private readonly RlsContextRunner $contexts,
        private readonly SealsExpiredAssessmentSessions $sealer,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now');
    }

    /** @return array{sealed: int, failed: int} */
    public function handle(int $limit): array
    {
        if ($limit < 1) {
            throw new RuntimeException('The expired-session sweep limit must be a positive integer.');
        }

        $now = ($this->clock)();
        if (! $now instanceof DateTimeImmutable) {
            throw new RuntimeException('The expired-session sweep clock must return DateTimeImmutable.');
        }
        $now = $now->setTimezone(new DateTimeZone('UTC'));
        $cutoff = $now->format('Y-m-d H:i:s.uP');

        /** @var list<int> $candidates */
        $candidates = $this->contexts->runAsService(
            fn (): array => DB::table('test_sessions')
                ->where('status', AssessmentSessionStatus::InProgress->value)
                ->where('ends_at', '<', $cutoff)
                ->orderBy('id')
                ->limit($limit)
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all(),
        );

        $sealed = 0;
        $failed = 0;
        foreach ($candidates as $sessionId) {
            try {
                $this->sealer->seal($sessionId, $now);
                $sealed++;
            } catch (Throwable $exception) {
                $failed++;
                report($exception);
            }
        }

        return ['sealed' => $sealed, 'failed' => $failed];
    }
}
