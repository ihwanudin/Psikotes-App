<?php

declare(strict_types=1);

namespace App\Domain\AssessmentSessions;

use RuntimeException;
use Throwable;

/**
 * F2 S5 (2026-09-21). Thrown by StartParticipantAssessmentSession::execute() only
 * when its bounded retry loop exhausts all attempts on a genuinely retryable
 * PostgreSQL SQLSTATE (40001/40P01). Keeping this classification inside the command
 * (the one place that already knows the SQLSTATE retry policy) means the HTTP layer
 * maps this one exception type to 503 ASSESSMENT_START_TEMPORARILY_UNAVAILABLE
 * without ever inspecting a QueryException's errorInfo itself -- the same leak
 * Correction A's typed-code work is about, one layer up.
 */
final class AssessmentSessionStartRetriesExhausted extends RuntimeException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct('ASSESSMENT_SESSION_START_RETRIES_EXHAUSTED', previous: $previous);
    }
}
