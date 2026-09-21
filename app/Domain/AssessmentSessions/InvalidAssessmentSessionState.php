<?php

declare(strict_types=1);

namespace App\Domain\AssessmentSessions;

use DomainException;
use Throwable;

/**
 * $httpFailureCode is set ONLY at the small set of throw sites that are genuinely
 * participant-facing conflicts (ambiguous history/candidates, replay/attempt
 * conflicts, no retest authority, a stale replay past its deadline). Every other
 * throw site leaves it null, meaning an internal invariant violation -- the HTTP
 * mapper must default null to a generic 500, never guess a 409/403 from the message
 * string. See tasks/handoffs/f2/s5-http-cutover-classification.md for the full,
 * site-by-site classification (F2 S5, 2026-09-21).
 */
final class InvalidAssessmentSessionState extends DomainException
{
    public function __construct(
        string $message,
        public readonly ?AssessmentSessionStartFailureCode $httpFailureCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
