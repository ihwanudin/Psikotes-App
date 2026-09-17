<?php

declare(strict_types=1);

namespace App\Data\Integrations;

use Carbon\CarbonInterface;

final readonly class CheckoutSessionScope
{
    public function __construct(
        public string $handoffPublicId,
        public int $assessmentParticipantId,
        public string $assessmentAttemptId,
        public int $organizationId,
        public int $participantId,
        public int $packageId,
        public int $integrationClientId,
        public int $integrationSourceId,
        public string $sourceSystem,
        public CarbonInterface $consumedAt,
    ) {}

    /** @return array{handoffPublicId:string,assessmentParticipantId:int,assessmentAttemptId:string,organizationId:int,participantId:int,packageId:int,integrationClientId:int,integrationSourceId:int,sourceSystem:string,consumedAt:string,purpose:string,destination:string} */
    public function descriptor(): array
    {
        return [
            'handoffPublicId' => $this->handoffPublicId,
            'assessmentParticipantId' => $this->assessmentParticipantId,
            'assessmentAttemptId' => $this->assessmentAttemptId,
            'organizationId' => $this->organizationId,
            'participantId' => $this->participantId,
            'packageId' => $this->packageId,
            'integrationClientId' => $this->integrationClientId,
            'integrationSourceId' => $this->integrationSourceId,
            'sourceSystem' => $this->sourceSystem,
            'consumedAt' => $this->consumedAt->toISOString(),
            'purpose' => 'checkout-handoff',
            'destination' => 'integrated-checkout-session',
        ];
    }
}
