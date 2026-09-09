<?php

declare(strict_types=1);

namespace App\Domain\Retention;

enum RetentionDataClass: string
{
    case PsychotestRaw = 'PSYCHOTEST_RAW';
    case Hpp = 'HPP';
    case InternalReport = 'INTERNAL_REPORT';
    case DassResponse = 'DASS_RESPONSE';
    case DassResult = 'DASS_RESULT';
    case ProctorMedia = 'PROCTOR_MEDIA';
    case Audit = 'AUDIT';
}
