<?php

declare(strict_types=1);

namespace App\Domain\AssessmentSessions;

enum AssessmentSessionErrorCode: string
{
    case SessionNotStarted = 'SESSION_NOT_STARTED';
    case SessionClosed = 'SESSION_CLOSED';
    case DeadlineExceeded = 'DEADLINE_EXCEEDED';
}
