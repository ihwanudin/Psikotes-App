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

    /**
     * ADR-0032 PR3 (2026-09-23): distinct from `failedToScore()` -- a
     * predictable, non-bug rejection where scoring was attempted and
     * definitively refused by the instrument's own rules (today: RMIB's P3
     * tiered rule, 2+ defective rank groups), not one where the answer set
     * itself was too incomplete to attempt scoring at all. Same nullability
     * pairing as `failedToScore()`, enforced by the same
     * `assessment_scoring_attempts` CHECK constraint.
     */
    public static function notScorable(string $reasonCode): self
    {
        if ($reasonCode === '') {
            throw self::invalid();
        }

        return new self('not_scorable', $reasonCode, null);
    }

    private static function invalid(): UnexpectedValueException
    {
        return new UnexpectedValueException('ASSESSMENT_SCORING_OUTCOME_INVALID');
    }
}
