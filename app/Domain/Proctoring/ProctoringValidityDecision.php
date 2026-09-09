<?php

declare(strict_types=1);

namespace App\Domain\Proctoring;

final readonly class ProctoringValidityDecision
{
    /**
     * @param  list<string>  $markerCodes
     * @param  list<string>  $pendingEvidenceIds
     */
    public function __construct(
        public ProctoringValidity $validity,
        public array $markerCodes,
        public int $uniqueEvidenceCount,
        public int $uniqueAdjudicationCount,
        public bool $humanReviewRequired,
        public bool $pendingAdjudication,
        public array $pendingEvidenceIds,
        public bool $procedureNoteRequired,
        public bool $publicationBlocked,
    ) {}
}
