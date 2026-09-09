<?php

declare(strict_types=1);

namespace App\Domain\Proctoring;

use InvalidArgumentException;

final readonly class ProctoringEvent
{
    public function __construct(
        public string $evidenceId,
        public ProctoringInstrument $instrument,
        public ProctoringEventKind $kind,
        public ProctoringEvidenceSource $source,
    ) {
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,99}\z/D', $evidenceId)) {
            throw new InvalidArgumentException('Proctoring evidence ID is invalid.');
        }

        if (! in_array($kind, self::allowedKinds($source), true)) {
            throw new InvalidArgumentException('Proctoring event kind is invalid for its evidence source.');
        }
    }

    public function hasSamePayload(self $other): bool
    {
        return $this->evidenceId === $other->evidenceId
            && $this->instrument === $other->instrument
            && $this->kind === $other->kind
            && $this->source === $other->source;
    }

    /** @return list<ProctoringEventKind> */
    private static function allowedKinds(ProctoringEvidenceSource $source): array
    {
        if ($source === ProctoringEvidenceSource::ClientObservation) {
            return [
                ProctoringEventKind::CameraPermissionDenied,
                ProctoringEventKind::CameraUnavailable,
                ProctoringEventKind::CameraInterrupted,
                ProctoringEventKind::ScreenDeparture,
                ProctoringEventKind::FaceMismatch,
                ProctoringEventKind::SecondFaceDetected,
                ProctoringEventKind::AudioAssistanceDetected,
            ];
        }

        return [
            ProctoringEventKind::NetworkInterrupted,
            ProctoringEventKind::UnreasonableTiming,
            ProctoringEventKind::IdentityFailure,
            ProctoringEventKind::SubtestIncomplete,
            ProctoringEventKind::InvalidResponsePatternConfirmed,
        ];
    }
}
