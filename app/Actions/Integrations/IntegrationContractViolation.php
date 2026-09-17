<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use RuntimeException;

final class IntegrationContractViolation extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $httpStatus = 403)
    {
        parent::__construct($errorCode);
    }
}
