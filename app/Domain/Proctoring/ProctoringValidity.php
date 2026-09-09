<?php

declare(strict_types=1);

namespace App\Domain\Proctoring;

enum ProctoringValidity: string
{
    case V1 = 'V1';
    case V2 = 'V2';
    case V3 = 'V3';
}
