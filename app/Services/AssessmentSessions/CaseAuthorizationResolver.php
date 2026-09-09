<?php

declare(strict_types=1);

namespace App\Services\AssessmentSessions;

use App\Domain\AssessmentSessions\CaseAuthorization;
use App\Domain\AssessmentSessions\CaseAuthorizationGrantKind;
use App\Domain\AssessmentSessions\CaseAuthorizationOrigin;
use App\Domain\AssessmentSessions\CaseAuthorizationRejected;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Models\AssessmentCase;
use App\Models\AssessmentCharge;
use App\Models\AssessmentEntitlement;
use App\Models\AssessmentParticipant;
use App\Models\Branch;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\PackageItem;
use App\Models\Participant;
use App\Models\SelectionParticipant;
use App\Models\TestPackage;
use App\Security\RlsContextRunner;
use App\Services\ParticipantAuth\AssessmentEntitlementGate;
use App\Services\ParticipantAuth\AssessmentPrincipal;
use App\Services\ParticipantAuth\ParticipantPrincipal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/** Resolves a ready canonical grant to one durable case while retaining caller-owned locks. */
final readonly class CaseAuthorizationResolver
{
    public function __construct(
        private RlsContextRunner $contexts,
        private AssessmentEntitlementGate $integratedGate,
    ) {}

    public function resolveIntegratedForUpdate(
        AssessmentPrincipal $principal,
        GenericAssessmentInstrument $instrument,
    ): CaseAuthorization {
        $this->assertScope();

        try {
            $attemptHint = AssessmentParticipant::query()->find($principal->assessmentParticipantId);
            if ($attemptHint === null) {
                $this->reject();
            }
            $this->one(Branch::query()->whereKey($principal->organizationId)->lockForUpdate()->limit(2)->get());
            $package = $this->one(TestPackage::query()->whereKey($attemptHint->package_id)
                ->lockForUpdate()->limit(2)->get());
            $this->assertMainComposition($this->packageTypes($package->id), $instrument);

            $attempt = $this->one(AssessmentParticipant::query()
                ->whereKey($principal->assessmentParticipantId)
                ->where('participant_id', $principal->participantId)
                ->where('organization_id', $principal->organizationId)
                ->where('package_id', $package->id)
                ->lockForUpdate()->limit(2)->get());
            $this->one(Participant::query()->whereKey($principal->participantId)
                ->where('branch_id', $principal->organizationId)->lockForUpdate()->limit(2)->get());
            $case = $this->one(AssessmentCase::query()->whereKey($attempt->assessment_case_id)
                ->lockForUpdate()->limit(2)->get());
            $charge = $this->one(AssessmentCharge::query()
                ->where('assessment_participant_id', $attempt->id)
                ->where('participant_id', $principal->participantId)
                ->where('organization_id', $principal->organizationId)
                ->where('package_id', $package->id)
                ->lockForUpdate()->limit(2)->get());
            $entitlement = $this->one(AssessmentEntitlement::query()
                ->where('assessment_participant_id', $attempt->id)->where('charge_id', $charge->id)
                ->where('participant_id', $principal->participantId)
                ->where('organization_id', $principal->organizationId)
                ->where('test_type', $instrument->value)
                ->where('status', 'ready')->whereNotNull('ready_at')
                ->whereRaw('ready_at <= CURRENT_TIMESTAMP')->whereNull('started_at')->whereNull('completed_at')
                ->lockForUpdate()->limit(2)->get());

            if (! in_array($attempt->assessment_status, ['READY', 'IN_PROGRESS'], true)
                || $attempt->revoked_at !== null || $attempt->finalized_at !== null
                || $case->public_id !== $attempt->assessment_attempt_id
                || $case->participant_id !== $principal->participantId
                || $case->organization_id !== $principal->organizationId
                || $case->package_id !== $package->id
                || $case->origin !== CaseAuthorizationOrigin::Integrated->value) {
                $this->reject();
            }

            $canonical = $this->integratedGate->assertReady($principal, $instrument->value);
            if ($canonical->id !== $entitlement->id) {
                $this->reject();
            }

            return $this->authorization($case, $instrument, CaseAuthorizationOrigin::Integrated,
                CaseAuthorizationGrantKind::AssessmentEntitlement, $entitlement->id);
        } catch (CaseAuthorizationRejected $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->reject();
        }
    }

    public function resolveParticipantForUpdate(
        ParticipantPrincipal $principal,
        GenericAssessmentInstrument $instrument,
    ): CaseAuthorization {
        $this->assertScope();

        try {
            $participant = $this->one(Participant::query()->whereKey($principal->participantId)
                ->where('branch_id', $principal->branchId)->lockForUpdate()->limit(2)->get());

            return $participant->source_system === CaseAuthorizationOrigin::DirectPublic->value
                ? $this->resolveDirect($participant, $instrument)
                : $this->resolveLegacy($participant, $instrument);
        } catch (CaseAuthorizationRejected $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->reject();
        }
    }

    private function resolveDirect(Participant $participant, GenericAssessmentInstrument $instrument): CaseAuthorization
    {
        if (! is_int($participant->package_id)) {
            $this->reject();
        }
        $package = $this->one(TestPackage::query()->whereKey($participant->package_id)
            ->lockForUpdate()->limit(2)->get());
        $packageTypes = $this->packageTypes($package->id);
        $this->assertMainComposition($packageTypes, $instrument);

        $orderHint = $this->one(Order::query()->where('participant_id', $participant->id)
            ->orderBy('id')->limit(2)->get());
        if (! is_int($orderHint->assessment_case_id)) {
            $this->reject();
        }
        $case = $this->one(AssessmentCase::query()->where('participant_id', $participant->id)
            ->where('origin', CaseAuthorizationOrigin::DirectPublic->value)
            ->orderBy('id')->lockForUpdate()->limit(2)->get());
        $order = $this->one(Order::query()->where('participant_id', $participant->id)
            ->orderBy('id')->lockForUpdate()->limit(2)->get());
        $entitlements = Entitlement::query()->where('participant_id', $participant->id)
            ->orderBy('id')->lockForUpdate()->get();
        $selection = SelectionParticipant::query()->where('participant_id', $participant->id)
            ->orderBy('id')->lockForUpdate()->limit(2)->get();
        $integrated = AssessmentParticipant::query()->where('participant_id', $participant->id)
            ->orderBy('id')->lockForUpdate()->limit(2)->get();
        $grant = $this->readyEntitlement($participant->id, $instrument, $order->id);
        $entitlementTypes = $entitlements->pluck('test_type')->sort()->values()->all();

        if ($selection->isNotEmpty() || $integrated->isNotEmpty()
            || $entitlementTypes !== $packageTypes
            || $entitlements->contains(fn (Entitlement $row): bool => $row->order_id !== $order->id)
            || $order->status->value !== 'paid' || $order->paid_at === null
            || $order->assessment_case_id !== $case->id
            || $case->public_id !== $order->public_id
            || $case->participant_id !== $participant->id
            || $case->organization_id !== $participant->branch_id
            || $case->package_id !== $package->id) {
            $this->reject();
        }

        return $this->authorization($case, $instrument, CaseAuthorizationOrigin::DirectPublic,
            CaseAuthorizationGrantKind::Entitlement, $grant->id);
    }

    private function resolveLegacy(Participant $participant, GenericAssessmentInstrument $instrument): CaseAuthorization
    {
        if ($participant->source_system !== 'SELEKSI_BEASISWA_JEPANG' || $participant->package_id !== null) {
            $this->reject();
        }
        $selection = $this->one(SelectionParticipant::query()->where('participant_id', $participant->id)
            ->orderBy('id')->lockForUpdate()->limit(2)->get());
        $case = $this->one(AssessmentCase::query()->where('participant_id', $participant->id)
            ->where('origin', CaseAuthorizationOrigin::LegacySelection->value)
            ->orderBy('id')->lockForUpdate()->limit(2)->get());
        $entitlements = Entitlement::query()->where('participant_id', $participant->id)
            ->orderBy('id')->lockForUpdate()->get();
        $orders = Order::query()->where('participant_id', $participant->id)
            ->orderBy('id')->lockForUpdate()->limit(2)->get();
        $integrated = AssessmentParticipant::query()->where('participant_id', $participant->id)
            ->orderBy('id')->lockForUpdate()->limit(2)->get();
        $grant = $this->readyEntitlement($participant->id, $instrument, null);

        if ($orders->isNotEmpty() || $integrated->isNotEmpty()
            || $entitlements->contains(fn (Entitlement $row): bool => $row->getAttribute('order_id') !== null)
            || $selection->getAttribute('assessment_case_id') !== $case->id
            || $case->participant_id !== $participant->id
            || $case->organization_id !== $participant->branch_id
            || $case->package_id !== null) {
            $this->reject();
        }

        return $this->authorization($case, $instrument, CaseAuthorizationOrigin::LegacySelection,
            CaseAuthorizationGrantKind::Entitlement, $grant->id);
    }

    private function readyEntitlement(int $participantId, GenericAssessmentInstrument $instrument, ?int $orderId): Entitlement
    {
        $query = Entitlement::query()->where('participant_id', $participantId)
            ->where('test_type', $instrument->value)->where('status', 'ready')
            ->whereNotNull('ready_at')->whereRaw('ready_at <= CURRENT_TIMESTAMP')
            ->whereNull('started_at')->whereNull('completed_at');
        $orderId === null ? $query->whereNull('order_id') : $query->where('order_id', $orderId);

        return $this->one($query->orderBy('id')->lockForUpdate()->limit(2)->get());
    }

    /** @return list<string> */
    private function packageTypes(int $packageId): array
    {
        return array_values(PackageItem::query()->where('package_id', $packageId)
            ->orderBy('test_type')->lockForUpdate()->get()->pluck('test_type')
            ->map(static fn (mixed $type): string => (string) $type)->all());
    }

    /** @param list<string> $types */
    private function assertMainComposition(array $types, GenericAssessmentInstrument $instrument): void
    {
        try {
            $canonical = TestPackage::canonicalComposition($types);
        } catch (Throwable) {
            $this->reject();
        }
        if (! in_array($instrument->value, $canonical, true)) {
            $this->reject();
        }
    }

    private function assertScope(): void
    {
        if ($this->contexts->current()?->role !== 'service' || DB::transactionLevel() < 1) {
            throw new LogicException('Case authorization requires an active service transaction.');
        }
    }

    /** @template TModel of Model
     * @param  Collection<int, TModel>  $rows
     * @return TModel
     */
    private function one(Collection $rows): Model
    {
        if ($rows->count() !== 1) {
            $this->reject();
        }

        return $rows->firstOrFail();
    }

    private function authorization(AssessmentCase $case, GenericAssessmentInstrument $instrument,
        CaseAuthorizationOrigin $origin, CaseAuthorizationGrantKind $grantKind, int $grantId): CaseAuthorization
    {
        if ($case->origin !== $origin->value) {
            $this->reject();
        }

        return new CaseAuthorization($case->id, $case->public_id, $case->participant_id,
            $case->organization_id, $case->package_id, $origin, $instrument, $grantKind, $grantId);
    }

    private function reject(): never
    {
        throw new CaseAuthorizationRejected;
    }
}
