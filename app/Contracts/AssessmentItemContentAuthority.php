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
 * $participantId and $lockedVariant (item-delivery Stage 2 continuation,
 * 2026-09-21, RMIB gender-track selection): most readers (Kraepelin, PAPI)
 * ignore both -- content is identical for every participant. A reader MUST
 * NOT return any participant identity data in the produced
 * AssessmentItemContent; $participantId exists only to let a reader pick
 * which pre-existing content variant to serve, never to personalize the
 * content itself beyond that selection.
 *
 * $lockedVariant carries an ALREADY-DECIDED variant (e.g. persisted on the
 * session at allocation time) and, when non-null, a reader MUST use it
 * as-is and MUST NOT consult $participantId or any live participant state
 * -- this is what makes a read stable even if the participant's profile
 * changes after the session started. $lockedVariant is null exactly once
 * per session: the very first call, at allocation, before anything has
 * been persisted yet. In that one call a reader MAY resolve a variant
 * fresh from $participantId and report what it picked via
 * AssessmentItemContent::$resolvedVariant, for the caller to persist and
 * pass back as $lockedVariant on every later call.
 *
 * $currentSegmentCode (added item-delivery segment-awareness stage, per
 * tasks/handoffs/f2/item-delivery-segment-awareness-deferred.md): the
 * caller's own fresh TimedSegmentSweep result for the live session, e.g.
 * "ME_MEMORIZE"/"ME_ANSWER" -- null only when there is no live session to
 * sweep (AllocateAndStartAssessmentSession's pre-start deliverability
 * check, whose return value is discarded; see that call site's own
 * comment). A reader for an instrument with no multi-phase subtest ignores
 * this entirely, exactly like Kraepelin's reader does today. Independent
 * axis from $participantId/$lockedVariant -- a reader may need either,
 * both, or neither.
 */
interface AssessmentItemContentAuthority
{
    /** @throws AssessmentItemContentUnavailable */
    public function contentFor(
        GenericAssessmentInstrument $instrument,
        SessionDefinition $definition,
        int $participantId,
        ?string $lockedVariant = null,
        ?string $currentSegmentCode = null,
    ): AssessmentItemContent;
}
