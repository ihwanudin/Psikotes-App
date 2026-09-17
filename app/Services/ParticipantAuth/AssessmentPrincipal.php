<?php

declare(strict_types=1);

namespace App\Services\ParticipantAuth;

use InvalidArgumentException;

/** Server-created scope after authenticating an assessment-purpose token, never a checkout token. */
final readonly class AssessmentPrincipal
{
    public function __construct(public int $participantId, public int $organizationId, public int $assessmentParticipantId)
    {
        if (min($participantId, $organizationId, $assessmentParticipantId) < 1) {
            throw new InvalidArgumentException('Invalid assessment principal.');
        }
    }
}
