<?php

declare(strict_types=1);

namespace App\Domain\AssessmentSessions;

enum CaseAuthorizationGrantKind: string
{
    case AssessmentEntitlement = 'assessment_entitlement';
    case Entitlement = 'entitlement';
}
