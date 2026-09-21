<?php

declare(strict_types=1);

namespace App\Actions\AssessmentSessions;

use App\Domain\AssessmentSessions\AssessmentAutosaveReceipt;

final readonly class AssessmentAutosaveResult
{
    /**
     * F2 session-http (2026-09-21): $currentRevision is populated on
     * rejection whenever the session row was already loaded, so the HTTP
     * layer can report it in AUTOSAVE_REVISION_GAP's `details` -- a value
     * that comes only from the participant's own session, never from raw
     * client input. Optional/trailing so it never disturbs the existing
     * positional constructor calls in AutosaveAssessmentAnswers.
     */
    public function __construct(
        public bool $accepted,
        public bool $replayed,
        public ?string $status,
        public ?string $errorCode,
        public ?AssessmentAutosaveReceipt $receipt = null,
        public ?int $currentRevision = null,
    ) {}
}
