<?php

declare(strict_types=1);

namespace App\Data\Integrations;

use Carbon\CarbonInterface;
use SensitiveParameter;

final readonly class EstablishedCheckoutSession
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
        public CarbonInterface $establishedAt,
        public CarbonInterface $idleExpiresAt,
        public CarbonInterface $absoluteExpiresAt,
        #[SensitiveParameter] private string $rawSelector,
        #[SensitiveParameter] private string $rawCsrfToken,
    ) {}

    public function rawSelector(): string
    {
        return $this->rawSelector;
    }

    public function rawCsrfToken(): string
    {
        return $this->rawCsrfToken;
    }

    /** @return array{sessionPublicId:string,handoffPublicId:string,assessmentParticipantId:int,assessmentAttemptId:string,organizationId:int,participantId:int,packageId:int,integrationClientId:int,integrationSourceId:int,sourceSystem:string,establishedAt:string,idleExpiresAt:string,absoluteExpiresAt:string} */
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
            'establishedAt' => $this->establishedAt->toISOString(),
            'idleExpiresAt' => $this->idleExpiresAt->toISOString(),
            'absoluteExpiresAt' => $this->absoluteExpiresAt->toISOString(),
        ];
    }
}
