<?php

declare(strict_types=1);

namespace App\Domain\Dass;

/** Matches `dass.assessments.status`'s CHECK constraint exactly (`2026_08_25_000300_create_isolated_dass_schema.php`). */
enum DassAssessmentStatus: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Declined = 'declined';
    case Withdrawn = 'withdrawn';
}
