<?php

declare(strict_types=1);

namespace App\Domain\AssessmentSessions;

/**
 * F2 S5 (2026-09-21). HTTP-mapping classification for InvalidAssessmentSessionState,
 * scoped only to the ADR-0030 participant start command. Deliberately not
 * AssessmentSessionErrorCode: that enum is shared decision-level vocabulary for three
 * unrelated policies (deadline/allocation/autosave) serving other endpoints, and
 * widening it to also carry HTTP-status meaning would couple those endpoints to this
 * one's response contract. An untyped InvalidAssessmentSessionState (this property
 * null) is an internal invariant violation, not a participant-facing outcome, and
 * must map to a generic 500 -- never guessed at as a conflict.
 */
enum AssessmentSessionStartFailureCode: string
{
    case NotAvailable = 'ASSESSMENT_NOT_AVAILABLE';
    case Conflict = 'ASSESSMENT_START_CONFLICT';
}
