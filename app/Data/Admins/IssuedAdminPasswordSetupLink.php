<?php

declare(strict_types=1);

namespace App\Data\Admins;

use Carbon\CarbonInterface;

final readonly class IssuedAdminPasswordSetupLink
{
    public function __construct(
        public string $url,
        public CarbonInterface $expiresAt,
    ) {}
}
