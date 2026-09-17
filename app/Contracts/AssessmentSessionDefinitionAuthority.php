<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Domain\AssessmentSessions\CaseAuthorization;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\SessionDefinition;

interface AssessmentSessionDefinitionAuthority
{
    public function issueForNewSession(
        GenericAssessmentInstrument $instrument,
        CaseAuthorization $authorization,
        string $sessionPublicId,
    ): SessionDefinition;
}
