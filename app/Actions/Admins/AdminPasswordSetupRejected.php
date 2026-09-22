<?php

declare(strict_types=1);

namespace App\Actions\Admins;

use RuntimeException;

final class AdminPasswordSetupRejected extends RuntimeException
{
    public function __construct(public readonly string $publicMessage, public readonly int $httpStatus)
    {
        parent::__construct($publicMessage);
    }
}
