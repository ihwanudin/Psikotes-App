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
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Internal read-only gate. Session creation must recheck this inside its own locking transaction. */
final readonly class AssessmentEntitlementGate
{
    public function __construct(private AssessmentPriceSnapshot $prices, private AssessmentAccessPrerequisites $prerequisites) {}

    public function assertReady(AssessmentPrincipal $principal, string $testType): AssessmentEntitlement
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
            ->whereNotNull('ready_at')->where('ready_at', '<=', now())->whereNull('started_at')->whereNull('completed_at')->first();
        if ($entitlement === null || ! $this->settled($charge)) {
            throw new EntitlementLocked;
        }
        $this->prerequisites->assertSatisfied($participant, $testType);

        return $entitlement;
    }

    private function settled(AssessmentCharge $charge): bool
    {
        if ($charge->amount === 0) {
            return $charge->free_settled_at !== null && $charge->free_settled_at->lte(now())
                && ! DB::table('assessment_bill_items')->where('charge_id', $charge->id)->exists();
        }
        $item = DB::table('assessment_bill_items as item')->join('assessment_bills as bill', 'bill.id', '=', 'item.bill_id')
            ->where('item.charge_id', $charge->id)->where('item.organization_id', $charge->organization_id)
            ->where('item.participant_id', $charge->participant_id)->where('item.payer_type', $charge->payer_type)
            ->where('item.amount', $charge->amount)->where('item.currency', $charge->currency)
            ->whereNotNull('item.settled_at')->where('item.settled_at', '<=', now())
            ->where('bill.organization_id', $charge->organization_id)->where('bill.payer_type', $charge->payer_type)
            ->where('bill.currency', $charge->currency)->where('bill.status', 'paid')
            ->whereNotNull('bill.paid_at')->where('bill.paid_at', '<=', now())
            ->where('bill.payer_participant_id', $charge->payer_type === 'self' ? $charge->participant_id : null)
            ->where('item.payer_participant_id', $charge->payer_type === 'self' ? $charge->participant_id : null)
            ->first(['bill.id', 'bill.amount', 'bill.item_count']);
        if ($item === null) {
            return false;
        }
        $members = DB::table('assessment_bill_items')->where('bill_id', $item->id)->get(['amount', 'settled_at']);
        $total = 0;
        foreach ($members as $member) {
            $amount = (int) $member->amount;
            if ($amount <= 0 || $total > PHP_INT_MAX - $amount || $member->settled_at === null
                || CarbonImmutable::parse($member->settled_at)->gt(now())) {
                return false;
            }
            $total += $amount;
        }

        return count($members) === (int) $item->item_count && $total === (int) $item->amount;
    }
}
