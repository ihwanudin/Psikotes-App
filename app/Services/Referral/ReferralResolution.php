<?php

declare(strict_types=1);

namespace App\Services\Referral;

use App\Models\Branch;
use Illuminate\Support\Carbon;

final readonly class ReferralResolution
{
    public function __construct(
        public Branch $branch,
        public string $source,
        public Carbon $expiresAt,
        public string $cookiePayload,
        public bool $shouldSetCookie,
    ) {}
}
