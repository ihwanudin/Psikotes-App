<?php

declare(strict_types=1);

namespace App\Domain\Proctoring;

enum ProctoringAdjudicatedFindingKind: string
{
    case SignalDismissed = 'SIGNAL_DISMISSED';
    case SubstitutionConfirmed = 'SUBSTITUTION_CONFIRMED';
    case AssistanceConfirmed = 'ASSISTANCE_CONFIRMED';
}
