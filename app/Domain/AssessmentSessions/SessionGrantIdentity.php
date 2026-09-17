<?php

declare(strict_types=1);

namespace App\Domain\AssessmentSessions;

use InvalidArgumentException;

final readonly class SessionGrantIdentity
{
    public int $assessmentCaseId;

    public int $participantId;

    public int $organizationId;

    public GenericAssessmentInstrument $instrument;

    public CaseAuthorizationOrigin $origin;

    public CaseAuthorizationGrantKind $grantKind;

    private function __construct(
        CaseAuthorization $authorization,
        public ?int $assessmentParticipantId,
        public ?int $orderId,
        public ?int $selectionParticipantId,
        public ?int $assessmentEntitlementId,
        public ?int $entitlementId,
    ) {
        $this->assessmentCaseId = $authorization->caseId;
        $this->participantId = $authorization->participantId;
        $this->organizationId = $authorization->organizationId;
        $this->instrument = $authorization->instrument;
        $this->origin = $authorization->origin;
        $this->grantKind = $authorization->grantKind;
    }

    public static function integrated(CaseAuthorization $authorization, int $assessmentParticipantId): self
    {
        self::assertAuthorization(
            $authorization,
            CaseAuthorizationOrigin::Integrated,
            CaseAuthorizationGrantKind::AssessmentEntitlement,
        );

        return new self(
            $authorization,
            self::positiveId($assessmentParticipantId, 'Assessment participant'),
            null,
            null,
            $authorization->grantId,
            null,
        );
    }

    public static function directPublic(CaseAuthorization $authorization, int $orderId): self
    {
        self::assertAuthorization(
            $authorization,
            CaseAuthorizationOrigin::DirectPublic,
            CaseAuthorizationGrantKind::Entitlement,
        );

        return new self(
            $authorization,
            null,
            self::positiveId($orderId, 'Order'),
            null,
            null,
            $authorization->grantId,
        );
    }

    public static function legacySelection(CaseAuthorization $authorization, int $selectionParticipantId): self
    {
        self::assertAuthorization(
            $authorization,
            CaseAuthorizationOrigin::LegacySelection,
            CaseAuthorizationGrantKind::Entitlement,
        );

        return new self(
            $authorization,
            null,
            null,
            self::positiveId($selectionParticipantId, 'Selection participant'),
            null,
            $authorization->grantId,
        );
    }

    /**
     * @return array{
     *     assessment_case_id: int,
     *     participant_id: int,
     *     organization_id: int,
     *     test_type: string,
     *     origin: string,
     *     grant_kind: string,
     *     assessment_participant_id: int|null,
     *     order_id: int|null,
     *     selection_participant_id: int|null,
     *     assessment_entitlement_id: int|null,
     *     entitlement_id: int|null
     * }
     */
    public function toArray(): array
    {
        return [
            'assessment_case_id' => $this->assessmentCaseId,
            'participant_id' => $this->participantId,
            'organization_id' => $this->organizationId,
            'test_type' => $this->instrument->value,
            'origin' => $this->origin->value,
            'grant_kind' => $this->grantKind->value,
            'assessment_participant_id' => $this->assessmentParticipantId,
            'order_id' => $this->orderId,
            'selection_participant_id' => $this->selectionParticipantId,
            'assessment_entitlement_id' => $this->assessmentEntitlementId,
            'entitlement_id' => $this->entitlementId,
        ];
    }

    private static function assertAuthorization(
        CaseAuthorization $authorization,
        CaseAuthorizationOrigin $origin,
        CaseAuthorizationGrantKind $grantKind,
    ): void {
        if ($authorization->origin !== $origin || $authorization->grantKind !== $grantKind) {
            throw new InvalidArgumentException('Case authorization does not match the session grant identity factory.');
        }
    }

    private static function positiveId(int $value, string $label): int
    {
        if ($value < 1) {
            throw new InvalidArgumentException("{$label} identity must be positive.");
        }

        return $value;
    }
}
