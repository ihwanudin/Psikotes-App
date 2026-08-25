<?php

declare(strict_types=1);

namespace App\Services\Notifications\Exceptions;

use RuntimeException;

final class NotificationDeliveryFailed extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct('Participant notification delivery failed.');
    }
}
