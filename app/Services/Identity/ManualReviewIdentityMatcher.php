<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Contracts\IdentityMatcher;
use App\Data\IdentityMatchResult;
use App\Models\IdentityEvidence;

final class ManualReviewIdentityMatcher implements IdentityMatcher
{
    public function name(): string
    {
        return 'manual-review';
    }

    public function compare(
        IdentityEvidence $identityDocument,
        IdentityEvidence $initialSelfie,
    ): IdentityMatchResult {
        return IdentityMatchResult::pending();
    }
}
