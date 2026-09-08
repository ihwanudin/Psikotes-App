<?php

declare(strict_types=1);

namespace App\Domain\Review;

use DomainException;
use InvalidArgumentException;

final class ReportReviewStateMachine
{
    /** @var list<string> */
    private const STATES = [
        'DRAFT_SCORED',
        'DRAFT_NARRATED',
        'UNDER_REVIEW',
        'REVISED',
        'SIGNED',
        'PUBLISHED',
        'REVOKED',
        'VOID',
    ];

    /** @var array<string, list<string>> */
    private const STANDARD_TRANSITIONS = [
        'DRAFT_SCORED' => ['DRAFT_NARRATED'],
        'DRAFT_NARRATED' => ['UNDER_REVIEW'],
        'UNDER_REVIEW' => ['REVISED', 'SIGNED'],
        'REVISED' => ['UNDER_REVIEW'],
        'SIGNED' => ['PUBLISHED'],
        'PUBLISHED' => ['REVOKED'],
        'REVOKED' => [],
        'VOID' => [],
    ];

    /**
     * @return array{
     *     from_state: string,
     *     to_state: string,
     *     transitioned: true,
     *     terminal: bool,
     *     provenance: array{
     *         transition_kind: 'STANDARD'|'INVALIDATION',
     *         invalidity_declared: bool
     *     }
     * }
     */
    public function transition(string $fromState, string $toState, bool $invalidityDeclared = false): array
    {
        $this->validateState($fromState);
        $this->validateState($toState);

        if ($invalidityDeclared && $toState !== 'VOID') {
            throw new InvalidArgumentException('Invalidity declaration is only valid for a transition to VOID.');
        }

        $isInvalidation = $toState === 'VOID';
        $allowed = $isInvalidation
            ? $invalidityDeclared && ! in_array($fromState, ['REVOKED', 'VOID'], true)
            : in_array($toState, self::STANDARD_TRANSITIONS[$fromState], true);

        if (! $allowed) {
            throw new DomainException("Report cannot transition from {$fromState} to {$toState}.");
        }

        return [
            'from_state' => $fromState,
            'to_state' => $toState,
            'transitioned' => true,
            'terminal' => in_array($toState, ['REVOKED', 'VOID'], true),
            'provenance' => [
                'transition_kind' => $isInvalidation ? 'INVALIDATION' : 'STANDARD',
                'invalidity_declared' => $invalidityDeclared,
            ],
        ];
    }

    private function validateState(string $state): void
    {
        if (! in_array($state, self::STATES, true)) {
            throw new InvalidArgumentException("Unknown report review state: {$state}.");
        }
    }
}
