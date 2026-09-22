<?php

declare(strict_types=1);

namespace App\Services\AssessmentSessions;

use App\Contracts\AssessmentItemContentAuthority;
use App\Domain\AssessmentSessions\AssessmentItemContent;
use App\Domain\AssessmentSessions\AssessmentItemContentUnavailable;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\SessionDefinition;

/**
 * F2 item-delivery Stage 1 (2026-09-21). Dispatches to a per-instrument
 * reader keyed by GenericAssessmentInstrument::value. Fail-closed by
 * design, not by omission (Lead sign-off, 2026-09-21): an instrument with
 * no registered reader -- including every instrument today, since no real
 * reader exists yet for any of them -- rejects with
 * AssessmentItemContentUnavailable rather than falling through to a
 * permissive default. A session must not be able to start for an
 * instrument whose item content this authority cannot actually produce;
 * silently allowing that would let a participant's timer run against a
 * question screen with nothing to show. Stage 2 only ever ADDS entries to
 * the $readers map bound in AppServiceProvider -- it never changes this
 * class's default.
 */
final readonly class RegistryAssessmentItemContentAuthority implements AssessmentItemContentAuthority
{
    /** @param array<string, AssessmentItemContentAuthority> $readers keyed by GenericAssessmentInstrument::value */
    public function __construct(private array $readers = []) {}

    public function contentFor(
        GenericAssessmentInstrument $instrument,
        SessionDefinition $definition,
        int $participantId,
        ?string $lockedVariant = null,
    ): AssessmentItemContent {
        $reader = $this->readers[$instrument->value] ?? null;
        if ($reader === null) {
            throw new AssessmentItemContentUnavailable(
                "No item content reader is registered for instrument \"{$instrument->value}\".",
            );
        }

        return $reader->contentFor($instrument, $definition, $participantId, $lockedVariant);
    }
}
