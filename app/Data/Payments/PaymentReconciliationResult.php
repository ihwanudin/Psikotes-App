<?php

declare(strict_types=1);

namespace App\Data\Payments;

final readonly class PaymentReconciliationResult
{
    public function __construct(
        public int $checked,
        public int $applied,
        public int $failed,
    ) {}
}
