<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\IdentityMatchResult;
use App\Models\IdentityEvidence;

interface IdentityMatcher
{
    public function name(): string;

    public function compare(
        IdentityEvidence $identityDocument,
        IdentityEvidence $initialSelfie,
    ): IdentityMatchResult;
}
