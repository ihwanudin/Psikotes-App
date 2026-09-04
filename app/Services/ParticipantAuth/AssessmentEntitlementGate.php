<?php

declare(strict_types=1);

namespace App\Services\ParticipantAuth;

use App\Models\AssessmentCharge;
use App\Models\AssessmentEntitlement;
use App\Models\AssessmentParticipant;
use App\Models\Participant;
use App\Security\RlsContextRunner;
use App\Services\ParticipantAuth\Exceptions\EntitlementLocked;
use App\Services\Payments\AssessmentPriceSnapshot;
use App\Services\Payments\AssessmentSettlementReader;
use DomainException;
use LogicException;

/** Internal read-only gate. Session creation must recheck this inside its own locking transaction. */
final readonly class AssessmentEntitlementGate
{
    public function __construct(private AssessmentPriceSnapshot $prices, private AssessmentAccessPrerequisites $prerequisites, private AssessmentSettlementReader $settlement) {}

    public function assertReady(AssessmentPrincipal $principal, string $testType): AssessmentEntitlement
    {
        return $this->evaluate($principal, $testType, null);
    }

    /** Uses captured evaluation inputs, not a historical database snapshot or authorization grant. */
    public function assertReadyAt(AssessmentPrincipal $principal, string $testType, AssessmentPrerequisiteFrame $frame): AssessmentEntitlement
    {
        return $this->evaluate($principal, $testType, $frame);
    }

    private function evaluate(AssessmentPrincipal $principal, string $testType, ?AssessmentPrerequisiteFrame $frame): AssessmentEntitlement
    {
        if (app(RlsContextRunner::class)->current()?->role !== 'service') {
            throw new LogicException('Assessment gate requires service RLS context.');
        }
        $attempt = AssessmentParticipant::query()->where('organization_id', $principal->organizationId)
            ->where('participant_id', $principal->participantId)->find($principal->assessmentParticipantId);
        $participant = Participant::query()->where('branch_id', $principal->organizationId)->find($principal->participantId);
        if ($attempt === null || $participant === null
            || ($attempt->metadata['checkout_contract_version'] ?? null) !== 'checkout-v2'
            || ! in_array($attempt->assessment_status, ['READY', 'IN_PROGRESS'], true)
            || $attempt->revoked_at !== null || $attempt->finalized_at !== null) {
            throw new EntitlementLocked;
        }
        $charge = AssessmentCharge::query()->where('assessment_participant_id', $attempt->id)
            ->where('organization_id', $principal->organizationId)->where('participant_id', $principal->participantId)
            ->where('package_id', $attempt->package_id)->first();
        if ($charge === null) {
            throw new EntitlementLocked;
        }
        try {
            $snapshot = $this->prices->fromCharge($charge, $charge->consultation_requested);
        } catch (DomainException) {
            throw new EntitlementLocked;
        }
        if (! in_array($testType, $snapshot['testTypes'], true)) {
            throw new EntitlementLocked;
        }
        $entitlement = AssessmentEntitlement::query()->where('assessment_participant_id', $attempt->id)
            ->where('organization_id', $principal->organizationId)->where('participant_id', $principal->participantId)
            ->where('charge_id', $charge->id)->where('test_type', $testType)->where('status', 'ready')
            ->whereNotNull('ready_at')->where('ready_at', '<=', $frame === null ? now() : $frame->asOf)->whereNull('started_at')->whereNull('completed_at')->first();
        if ($entitlement === null || ! ($frame === null
            ? $this->settlement->isSettled($charge)
            : $this->settlement->isSettledAt($charge, $frame->asOf))) {
            throw new EntitlementLocked;
        }
        if ($frame === null) {
            $this->prerequisites->assertSatisfied($participant, $testType);
        } else {
            $this->prerequisites->assertSatisfiedAt($participant, $testType, $frame);
        }

        return $entitlement;
    }
}
