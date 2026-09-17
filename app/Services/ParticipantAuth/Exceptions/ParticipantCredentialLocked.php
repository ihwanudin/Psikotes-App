<?php

declare(strict_types=1);

namespace App\Services\ParticipantAuth\Exceptions;

use RuntimeException;

final class ParticipantCredentialLocked extends RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('Participant credential is temporarily locked.');
    }
}
