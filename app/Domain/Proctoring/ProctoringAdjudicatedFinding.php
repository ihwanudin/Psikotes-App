<?php

declare(strict_types=1);

namespace App\Domain\Proctoring;

use InvalidArgumentException;

final readonly class ProctoringAdjudicatedFinding
{
    public function __construct(
        public string $findingId,
        public string $sourceEvidenceId,
        public ProctoringAdjudicatedFindingKind $kind,
        public string $adjudicatorId,
        public string $adjudicationToken,
    ) {
        foreach ([$findingId, $sourceEvidenceId, $adjudicatorId] as $identifier) {
            if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,99}\z/D', $identifier)) {
                throw new InvalidArgumentException('Proctoring adjudication identity is invalid.');
            }
        }

        if (! preg_match('/\A[!-~]{1,255}\z/D', $adjudicationToken)) {
            throw new InvalidArgumentException('Proctoring adjudication token is invalid.');
        }
    }

    public function supports(ProctoringEvent $event): bool
    {
        return match ($this->kind) {
            ProctoringAdjudicatedFindingKind::SignalDismissed => in_array($event->kind, [
                ProctoringEventKind::FaceMismatch,
                ProctoringEventKind::SecondFaceDetected,
                ProctoringEventKind::AudioAssistanceDetected,
            ], true),
            ProctoringAdjudicatedFindingKind::SubstitutionConfirmed => $event->kind === ProctoringEventKind::FaceMismatch,
            ProctoringAdjudicatedFindingKind::AssistanceConfirmed => in_array($event->kind, [
                ProctoringEventKind::SecondFaceDetected,
                ProctoringEventKind::AudioAssistanceDetected,
            ], true),
        };
    }

    public function hasSamePayload(self $other): bool
    {
        return $this->findingId === $other->findingId
            && $this->sourceEvidenceId === $other->sourceEvidenceId
            && $this->kind === $other->kind
            && $this->adjudicatorId === $other->adjudicatorId
            && $this->adjudicationToken === $other->adjudicationToken;
    }

    public function confirmsV3(): bool
    {
        return $this->kind !== ProctoringAdjudicatedFindingKind::SignalDismissed;
    }
}
