<?php

declare(strict_types=1);

namespace App\Domain\AssessmentSessions;

enum AssessmentSessionSelectionMismatch: string
{
    case AssessmentParticipant = 'ASSESSMENT_PARTICIPANT';
    case CaseIdentity = 'CASE_IDENTITY';
    case Instrument = 'INSTRUMENT';
    case Origin = 'ORIGIN';
    case Participant = 'PARTICIPANT';
    case Tenant = 'TENANT';
}
