<?php

declare(strict_types=1);

namespace App\Domain\Proctoring;

final readonly class ProctoringValidityDecision
{
    /** @param list<string> $markerCodes */
    public function __construct(
        public ProctoringValidity $validity,
        public array $markerCodes,
        public int $uniqueEvidenceCount,
        public bool $humanReviewRequired,
        public bool $procedureNoteRequired,
        public bool $publicationBlocked,
    ) {}
}
