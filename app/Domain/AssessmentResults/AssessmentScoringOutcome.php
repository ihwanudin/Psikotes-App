<?php

declare(strict_types=1);

namespace App\Domain\AssessmentResults;

use UnexpectedValueException;

/**
 * ADR-0032 §3 (2026-09-22): what ScoreAssessmentSession returns for a single
 * attempt. Never `scored` AND carrying a reason, never anything-but-`scored`
 * without one -- `assessment_scoring_attempts`' CHECK constraint enforces
 * the same pairing at the database layer.
 */
final readonly class AssessmentScoringOutcome
{
    private function __construct(
        public string $outcome,
        public ?string $reasonCode,
        public ?string $resultPublicId,
    ) {}

    public static function scored(string $resultPublicId): self
    {
        if ($resultPublicId === '') {
            throw self::invalid();
        }

        return new self('scored', null, $resultPublicId);
    }

    public static function failedToScore(string $reasonCode): self
    {
        if ($reasonCode === '') {
            throw self::invalid();
        }

        return new self('failed_to_score', $reasonCode, null);
    }

    private static function invalid(): UnexpectedValueException
    {
        return new UnexpectedValueException('ASSESSMENT_SCORING_OUTCOME_INVALID');
    }
}
