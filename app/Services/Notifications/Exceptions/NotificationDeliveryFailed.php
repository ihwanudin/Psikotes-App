<?php

declare(strict_types=1);

namespace App\Services\Notifications\Exceptions;

use RuntimeException;
use Throwable;

final class NotificationDeliveryFailed extends RuntimeException
{
    public readonly string $errorCode;

    public function __construct(string $errorCode, ?Throwable $previous = null)
    {
        $this->errorCode = preg_match('/^[a-z0-9_]{1,64}$/', $errorCode)
            ? $errorCode
            : 'notifier_invalid_error_code';

        parent::__construct('Participant notification delivery failed.', previous: $previous);
    }
}
