<?php

declare(strict_types=1);

namespace App\Domain\AssessmentSessions;

final readonly class CaseAuthorization
{
    public function __construct(
        public int $caseId,
        public string $casePublicId,
        public int $participantId,
        public int $organizationId,
        public ?int $packageId,
        public CaseAuthorizationOrigin $origin,
        public GenericAssessmentInstrument $instrument,
        public CaseAuthorizationGrantKind $grantKind,
        public int $grantId,
    ) {}
}
