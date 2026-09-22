<?php

declare(strict_types=1);

namespace App\Contracts;

use DateTimeImmutable;

/**
 * F2 (2026-09-21). The one method `SweepExpiredAssessmentSessions` depends
 * on from `SealExpiredAssessmentSession` -- extracted so a test proving
 * "one session's sealing failure does not stop the sweep from continuing
 * to the next candidate" can inject a genuine, deterministic failure for
 * exactly one session id, without needing true concurrency to reproduce a
 * race synchronously. Real PostgreSQL concurrent-failure evidence (a sweep
 * racing a participant's own last autosave) is a separate, additional
 * test -- this contract exists for the single-process isolation proof, not
 * as a replacement for it.
 */
interface SealsExpiredAssessmentSessions
{
    public function seal(int $sessionId, DateTimeImmutable $sealedAt): void;
}
