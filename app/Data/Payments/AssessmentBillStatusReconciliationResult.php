<?php

declare(strict_types=1);

namespace App\Data\Payments;

final readonly class AssessmentBillStatusReconciliationResult
{
    public function __construct(
        public int $scanned,
        public int $checked,
        public int $applied,
        public int $ignored,
        public int $failed,
    ) {}
}
