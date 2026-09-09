<?php

declare(strict_types=1);

namespace App\Domain\AssessmentSessions;

enum CaseAuthorizationOrigin: string
{
    case Integrated = 'INTEGRATED';
    case DirectPublic = 'DIRECT_PUBLIC';
    case LegacySelection = 'LEGACY_SELECTION';
}
