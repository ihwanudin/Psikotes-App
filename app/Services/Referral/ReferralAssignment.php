<?php

declare(strict_types=1);

namespace App\Services\Referral;

use App\Models\Branch;

final readonly class ReferralAssignment
{
    public function __construct(
        public Branch $branch,
        public string $source,
    ) {}
}
