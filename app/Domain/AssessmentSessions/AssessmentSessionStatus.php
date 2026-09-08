<?php

declare(strict_types=1);

namespace App\Domain\AssessmentSessions;

enum AssessmentSessionStatus: string
{
    case Created = 'created';
    case InProgress = 'in_progress';
    case Submitted = 'submitted';
    case Scored = 'scored';
    case Expired = 'expired';
    case Voided = 'void';
}
