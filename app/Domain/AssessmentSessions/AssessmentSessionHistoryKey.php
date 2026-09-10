<?php

declare(strict_types=1);

namespace App\Domain\AssessmentSessions;

final readonly class AssessmentSessionHistoryKey
{
    public function __construct(
        public string $casePublicId,
        public GenericAssessmentInstrument $instrument,
    ) {
        if (trim($casePublicId) === '') {
            throw new InvalidAssessmentSessionState('Assessment case identity is required.');
        }
    }

    public function matches(self $other): bool
    {
        return $this->casePublicId === $other->casePublicId
            && $this->instrument === $other->instrument;
    }
}
