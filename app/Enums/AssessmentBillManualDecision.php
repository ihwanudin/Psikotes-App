<?php

declare(strict_types=1);

namespace App\Enums;

enum AssessmentBillManualDecision: string
{
    case Approve = 'APPROVE';
    case Reject = 'REJECT';
}
