<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Domain\AssessmentSessions\AssessmentItemContent;
use App\Domain\AssessmentSessions\AssessmentItemContentUnavailable;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\SessionDefinition;

/**
 * Same shape reused for two roles: the top-level authority a caller depends
 * on, and a per-instrument reader registered into
 * RegistryAssessmentItemContentAuthority -- a reader is itself just an
 * AssessmentItemContentAuthority for exactly one instrument. This avoids a
 * second interface for what would otherwise be an identical method shape.
 */
interface AssessmentItemContentAuthority
{
    /** @throws AssessmentItemContentUnavailable */
    public function contentFor(
        GenericAssessmentInstrument $instrument,
        SessionDefinition $definition,
    ): AssessmentItemContent;
}
