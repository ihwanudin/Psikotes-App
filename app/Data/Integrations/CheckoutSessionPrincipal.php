<?php

declare(strict_types=1);

namespace App\Data\Integrations;

use Carbon\CarbonInterface;

final readonly class CheckoutSessionPrincipal
{
    public function __construct(
        public string $sessionPublicId,
        public string $handoffPublicId,
        public int $assessmentParticipantId,
        public string $assessmentAttemptId,
        public int $organizationId,
        public int $participantId,
        public int $packageId,
        public int $integrationClientId,
        public int $integrationSourceId,
        public string $sourceSystem,
        public string $assessmentStatus,
        public ?string $fundingMode,
        public CarbonInterface $establishedAt,
        public CarbonInterface $lastSeenAt,
        public CarbonInterface $idleExpiresAt,
        public CarbonInterface $absoluteExpiresAt,
    ) {}

    /** @return array{sessionPublicId:string,handoffPublicId:string,assessmentParticipantId:int,assessmentAttemptId:string,organizationId:int,participantId:int,packageId:int,integrationClientId:int,integrationSourceId:int,sourceSystem:string,assessmentStatus:string,fundingMode:?string,establishedAt:string,lastSeenAt:string,idleExpiresAt:string,absoluteExpiresAt:string} */
    public function descriptor(): array
    {
        return [
            'sessionPublicId' => $this->sessionPublicId,
            'handoffPublicId' => $this->handoffPublicId,
            'assessmentParticipantId' => $this->assessmentParticipantId,
            'assessmentAttemptId' => $this->assessmentAttemptId,
            'organizationId' => $this->organizationId,
            'participantId' => $this->participantId,
            'packageId' => $this->packageId,
            'integrationClientId' => $this->integrationClientId,
            'integrationSourceId' => $this->integrationSourceId,
            'sourceSystem' => $this->sourceSystem,
            'assessmentStatus' => $this->assessmentStatus,
            'fundingMode' => $this->fundingMode,
            'establishedAt' => $this->establishedAt->toISOString(),
            'lastSeenAt' => $this->lastSeenAt->toISOString(),
            'idleExpiresAt' => $this->idleExpiresAt->toISOString(),
            'absoluteExpiresAt' => $this->absoluteExpiresAt->toISOString(),
        ];
    }
}
