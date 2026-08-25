<?php

declare(strict_types=1);

namespace App\Services\Notifications\Exceptions;

use RuntimeException;
use Throwable;

final class NotificationDeliveryFailed extends RuntimeException
{
    public function __construct(public readonly string $errorCode, ?Throwable $previous = null)
    {
        parent::__construct('Participant notification delivery failed.', previous: $previous);
    }
}
