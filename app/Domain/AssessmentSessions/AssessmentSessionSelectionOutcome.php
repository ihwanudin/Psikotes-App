<?php

declare(strict_types=1);

namespace App\Domain\AssessmentSessions;

final readonly class AssessmentSessionSelectionOutcome
{
    /**
     * @param  list<AssessmentSessionSelectionMismatch>  $mismatches
     */
    private function __construct(
        public AssessmentSessionSelectionKind $kind,
        public ?AssessmentSessionSelectionCandidate $candidate,
        public array $mismatches,
    ) {}

    public static function replay(AssessmentSessionSelectionCandidate $candidate): self
    {
        return new self(AssessmentSessionSelectionKind::Replay, $candidate, []);
    }

    public static function selected(AssessmentSessionSelectionCandidate $candidate): self
    {
        return new self(AssessmentSessionSelectionKind::Selected, $candidate, []);
    }

    public static function unavailable(): self
    {
        return new self(AssessmentSessionSelectionKind::Unavailable, null, []);
    }

    public static function historyAmbiguous(): self
    {
        return new self(AssessmentSessionSelectionKind::HistoryAmbiguous, null, []);
    }

    public static function selectionAmbiguous(): self
    {
        return new self(AssessmentSessionSelectionKind::SelectionAmbiguous, null, []);
    }

    /** @param list<AssessmentSessionSelectionMismatch> $mismatches */
    public static function scopeRejected(array $mismatches): self
    {
        return new self(AssessmentSessionSelectionKind::ScopeRejected, null, $mismatches);
    }
}
