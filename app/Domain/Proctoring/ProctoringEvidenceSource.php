<?php

declare(strict_types=1);

namespace App\Domain\Proctoring;

enum ProctoringEvidenceSource: string
{
    case ClientObservation = 'CLIENT_OBSERVATION';
    case ServerFinding = 'SERVER_FINDING';
}
