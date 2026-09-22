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
 *
 * $currentSegmentCode (added item-delivery segment-awareness stage, per
 * tasks/handoffs/f2/item-delivery-segment-awareness-deferred.md): the
 * caller's own fresh TimedSegmentSweep result for the live session, e.g.
 * "ME_MEMORIZE"/"ME_ANSWER" -- null only when there is no live session to
 * sweep (AllocateAndStartAssessmentSession's pre-start deliverability
 * check, whose return value is discarded; see that call site's own
 * comment). A reader for an instrument with no multi-phase subtest ignores
 * this entirely, exactly like Kraepelin's reader does today.
 */
interface AssessmentItemContentAuthority
{
    /** @throws AssessmentItemContentUnavailable */
    public function contentFor(
        GenericAssessmentInstrument $instrument,
        SessionDefinition $definition,
        ?string $currentSegmentCode = null,
    ): AssessmentItemContent;
}
