<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use RuntimeException;

final class SelectionLaunchRejected extends RuntimeException
{
    public function __construct(
        public readonly string $publicMessage,
        public readonly int $httpStatus,
    ) {
        parent::__construct($publicMessage);
    }
}
