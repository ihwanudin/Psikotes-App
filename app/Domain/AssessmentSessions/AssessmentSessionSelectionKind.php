<?php

declare(strict_types=1);

namespace App\Domain\AssessmentSessions;

enum AssessmentSessionSelectionKind: string
{
    case Replay = 'REPLAY';
    case Selected = 'SELECTED';
    case Unavailable = 'UNAVAILABLE';
    case HistoryAmbiguous = 'HISTORY_AMBIGUOUS';
    case SelectionAmbiguous = 'SELECTION_AMBIGUOUS';
    case ScopeRejected = 'SCOPE_REJECTED';
}
