<?php

declare(strict_types=1);

namespace App\Data\Payments;

use App\Enums\OrderStatus;

final readonly class OrderTransition
{
    public function __construct(
        public OrderStatus $status,
        public bool $changed,
        public bool $unlocksEntitlements,
    ) {}
}
