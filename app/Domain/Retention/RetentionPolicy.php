<?php

declare(strict_types=1);

namespace App\Domain\Retention;

use DateInterval;
use DateTimeImmutable;

final class RetentionPolicy
{
    public function expiresAt(
        RetentionDataClass $dataClass,
        DateTimeImmutable $anchor,
    ): DateTimeImmutable {
        return match ($dataClass) {
            RetentionDataClass::PsychotestRaw,
            RetentionDataClass::Hpp,
            RetentionDataClass::InternalReport,
            RetentionDataClass::Audit => $this->addYearsWithoutOverflow($anchor, 5),
            RetentionDataClass::DassResponse,
            RetentionDataClass::DassResult => $this->addYearsWithoutOverflow($anchor, 2),
            RetentionDataClass::ProctorMedia => $anchor->add(new DateInterval('P90D')),
        };
    }

    private function addYearsWithoutOverflow(DateTimeImmutable $anchor, int $years): DateTimeImmutable
    {
        $targetYear = (int) $anchor->format('Y') + $years;
        $month = (int) $anchor->format('n');
        $day = (int) $anchor->format('j');

        if (! checkdate($month, $day, $targetYear)) {
            $day = 28;
        }

        return $anchor->setDate($targetYear, $month, $day);
    }
}
