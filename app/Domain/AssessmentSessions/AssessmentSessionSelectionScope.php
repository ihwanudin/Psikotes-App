<?php

declare(strict_types=1);

namespace App\Domain\AssessmentSessions;

/**
 * Trusted server-side scope derived from an authenticated credential.
 *
 * A direct or legacy request cannot carry a case selector. Only an integrated
 * credential is allowed to bind an exact assessment participant and case.
 */
final readonly class AssessmentSessionSelectionScope
{
    private function __construct(
        public int $participantId,
        public int $organizationId,
        public CaseAuthorizationOrigin $origin,
        public GenericAssessmentInstrument $instrument,
        public ?int $assessmentParticipantId,
        public ?string $trustedCasePublicId,
    ) {
        if ($participantId <= 0 || $organizationId <= 0) {
            throw new InvalidAssessmentSessionState('Participant and organization identities must be positive.');
        }
    }

    public static function forParticipantCredential(
        int $participantId,
        int $organizationId,
        CaseAuthorizationOrigin $origin,
        GenericAssessmentInstrument $instrument,
    ): self {
        if ($origin === CaseAuthorizationOrigin::Integrated) {
            throw new InvalidAssessmentSessionState(
                'Integrated selection requires an exact assessment-participant credential.',
            );
        }

        return new self($participantId, $organizationId, $origin, $instrument, null, null);
    }

    public static function forIntegratedCredential(
        int $participantId,
        int $organizationId,
        int $assessmentParticipantId,
        string $trustedCasePublicId,
        GenericAssessmentInstrument $instrument,
    ): self {
        if ($assessmentParticipantId <= 0 || trim($trustedCasePublicId) === '') {
            throw new InvalidAssessmentSessionState(
                'Integrated selection requires exact assessment-participant and case identities.',
            );
        }

        return new self(
            $participantId,
            $organizationId,
            CaseAuthorizationOrigin::Integrated,
            $instrument,
            $assessmentParticipantId,
            $trustedCasePublicId,
        );
    }
}
