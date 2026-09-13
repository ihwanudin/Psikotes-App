<?php

declare(strict_types=1);

namespace App\Domain\AssessmentSessions;

final readonly class AssessmentSessionCandidateProjection
{
    /** @param list<AssessmentSessionSelectionCandidate> $candidates */
    public function __construct(
        public AssessmentSessionSelectionScope $scope,
        public array $candidates,
    ) {}
}
