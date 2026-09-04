<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Actions\Notifications\EnqueueAssessmentActivation;
use App\Models\AssessmentCharge;
use App\Models\AssessmentEntitlement;
use App\Models\AssessmentParticipant;
use App\Models\Participant;
use App\Security\RlsContextRunner;
use App\Services\ParticipantAuth\AssessmentAccessPrerequisites;
use App\Services\ParticipantAuth\AssessmentPrincipal;
use App\Services\ParticipantAuth\Exceptions\EntitlementLocked;
use App\Services\Payments\AssessmentPriceSnapshot;
use App\Services\Payments\AssessmentSettlementReader;
use DomainException;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Internal caller supplies authenticated scope, never browser-supplied paid/consent flags. */
final readonly class ActivateSettledAssessment
{
    public function __construct(
        private AssessmentPriceSnapshot $prices,
        private AssessmentAccessPrerequisites $prerequisites,
        private EnqueueAssessmentActivation $outbox,
        private AssessmentSettlementReader $settlement,
    ) {}

    /** @return list<string> Newly activated types; unmet prerequisites are a no-op, not a batch failure. */
    public function execute(AssessmentPrincipal $principal): array
    {
        if (app(RlsContextRunner::class)->current()?->role !== 'service') {
            throw new LogicException('Assessment activation requires service RLS context.');
        }

        return DB::transaction(function () use ($principal): array {
            // Same organization mutex as reservation. Future finalizers must acquire it before bill locks.
            if (DB::table('branches')->where('id', $principal->organizationId)->lockForUpdate()->first() === null) {
                return [];
            }
            $charge = AssessmentCharge::query()->where('assessment_participant_id', $principal->assessmentParticipantId)
                ->where('organization_id', $principal->organizationId)->where('participant_id', $principal->participantId)->first();
            if ($charge === null) {
                return [];
            }
            $billId = DB::table('assessment_bill_items')->where('charge_id', $charge->id)->value('bill_id');
            if ($billId !== null) {
                DB::table('assessment_bills')->where('id', $billId)->lockForUpdate()->first();
                DB::table('assessment_bill_items')->where('bill_id', $billId)->orderBy('id')->lockForUpdate()->get();
            }
            $attempt = AssessmentParticipant::query()->where('organization_id', $principal->organizationId)
                ->where('participant_id', $principal->participantId)->lockForUpdate()->find($principal->assessmentParticipantId);
            $participant = Participant::query()->where('branch_id', $principal->organizationId)->lockForUpdate()->find($principal->participantId);
            $charge = AssessmentCharge::query()->lockForUpdate()->findOrFail($charge->id);
            if ($attempt === null || $participant === null || $charge->package_id !== $attempt->package_id
                || $charge->assessment_participant_id !== $attempt->id || $charge->participant_id !== $participant->id
                || $charge->organization_id !== $principal->organizationId
                || ($attempt->metadata['checkout_contract_version'] ?? null) !== 'checkout-v2'
                || ! in_array($attempt->assessment_status, ['PROVISIONED', 'READY', 'IN_PROGRESS'], true)
                || $attempt->revoked_at !== null || $attempt->finalized_at !== null) {
                return [];
            }
            try {
                $snapshot = $this->prices->fromCharge($charge, $charge->consultation_requested);
            } catch (DomainException) {
                return [];
            }
            if (! $this->settlement->isSettled($charge)) {
                return [];
            }
            $entitlements = AssessmentEntitlement::query()->where('assessment_participant_id', $attempt->id)
                ->orderBy('test_type')->lockForUpdate()->get()->keyBy('test_type');
            // Lock persisted evidence before evaluating it. Participant lock also guards absent FK child inserts.
            foreach (['consent_records', 'identity_verifications', 'identity_evidence'] as $table) {
                DB::table($table)->where('participant_id', $participant->id)->orderBy('id')->lockForUpdate()->get();
            }
            $activated = [];
            foreach ($snapshot['testTypes'] as $type) {
                $entitlement = $entitlements->get($type);
                if ($entitlement !== null) {
                    if ($entitlement->charge_id !== $charge->id || $entitlement->organization_id !== $attempt->organization_id
                        || $entitlement->participant_id !== $participant->id) {
                        throw new LogicException('Assessment entitlement scope is inconsistent.');
                    }
                    if ($entitlement->status !== 'locked' || $entitlement->ready_at !== null
                        || $entitlement->started_at !== null || $entitlement->completed_at !== null) {
                        continue; // Never rewind ready, running, or completed rights on retry.
                    }
                }
                try {
                    $this->prerequisites->assertSatisfied($participant, $type);
                } catch (EntitlementLocked) {
                    continue; // DASS and other participants remain independent.
                }
                $entitlement ??= new AssessmentEntitlement(['charge_id' => $charge->id,
                    'assessment_participant_id' => $attempt->id, 'organization_id' => $attempt->organization_id,
                    'participant_id' => $participant->id, 'test_type' => $type]);
                $entitlement->fill(['status' => 'ready', 'ready_at' => now()])->save();
                $activated[] = $type;
            }
            if ($activated !== []) {
                if ($attempt->assessment_status === 'PROVISIONED') {
                    $attempt->update(['assessment_status' => 'READY']);
                }
                $this->outbox->handle($attempt);
                $at = now()->toImmutable();
                DB::table('audit_logs')->insert(['branch_id' => $attempt->organization_id, 'actor_type' => 'system',
                    'actor_id' => null, 'action' => 'assessment.activated', 'subject_type' => AssessmentParticipant::class,
                    'subject_id' => (string) $attempt->id,
                    'context' => json_encode(['charge_id' => $charge->id, 'activated_count' => count($activated)], JSON_THROW_ON_ERROR),
                    'occurred_at' => $at, 'expires_at' => $at->addYears(2)]);
            }

            return $activated;
        });
    }
}
