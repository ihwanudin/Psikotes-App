<?php

declare(strict_types=1);

namespace App\Services\AssessmentSessions;

use App\Domain\AssessmentSessions\AssessmentSessionSelectionCandidate;
use App\Domain\AssessmentSessions\AssessmentSessionSelectionKind;
use App\Domain\AssessmentSessions\AssessmentSessionSelectionPolicy;
use App\Domain\AssessmentSessions\AssessmentSessionStatus;
use App\Domain\AssessmentSessions\CaseAuthorization;
use App\Domain\AssessmentSessions\CaseAuthorizationGrantKind;
use App\Domain\AssessmentSessions\CaseAuthorizationOrigin;
use App\Domain\AssessmentSessions\CaseAuthorizationRejected;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Models\AssessmentCase;
use App\Models\AssessmentCharge;
use App\Models\AssessmentEntitlement;
use App\Models\AssessmentParticipant;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\PackageItem;
use App\Models\Participant;
use App\Models\SelectionParticipant;
use App\Models\TestPackage;
use App\Security\RlsContextRunner;
use App\Services\ParticipantAuth\AssessmentEntitlementGate;
use App\Services\ParticipantAuth\AssessmentPrincipal;
use App\Services\ParticipantAuth\Exceptions\EntitlementLocked;
use App\Services\ParticipantAuth\ParticipantPrincipal;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Resolves a ready canonical grant to one durable case while retaining caller-owned locks. */
final readonly class CaseAuthorizationResolver
{
    public function __construct(
        private RlsContextRunner $contexts,
        private AssessmentEntitlementGate $integratedGate,
        private ParticipantAssessmentSessionCandidates $participantCandidates,
        private AssessmentSessionSelectionPolicy $selectionPolicy,
    ) {}

    public function resolveIntegratedForUpdate(
        AssessmentPrincipal $principal,
        GenericAssessmentInstrument $instrument,
    ): CaseAuthorization {
        $this->assertScope();

        $attemptHint = AssessmentParticipant::query()->find($principal->assessmentParticipantId);
        if ($attemptHint === null) {
            $this->reject();
        }
        $this->one(Participant::query()->whereKey($principal->participantId)
            ->where('branch_id', $principal->organizationId)->lockForUpdate()->limit(2)->get());
        $package = $this->one(TestPackage::query()->whereKey($attemptHint->package_id)
            ->lockForUpdate()->limit(2)->get());
        $this->assertMainComposition($this->packageTypes($package->id), $instrument);

        $cases = AssessmentCase::query()->where('participant_id', $principal->participantId)
            ->orderBy('id')->lockForUpdate()->get();
        $case = $this->one($cases->where('id', $attemptHint->assessment_case_id)->values());
        $orders = Order::query()->where('participant_id', $principal->participantId)
            ->orderBy('id')->lockForUpdate()->limit(2)->get();
        $selections = SelectionParticipant::query()->where('participant_id', $principal->participantId)
            ->orderBy('id')->lockForUpdate()->limit(2)->get();
        $attempt = $this->one(AssessmentParticipant::query()
            ->whereKey($principal->assessmentParticipantId)
            ->where('participant_id', $principal->participantId)
            ->where('organization_id', $principal->organizationId)
            ->where('package_id', $package->id)
            ->lockForUpdate()->limit(2)->get());
        $charge = $this->one(AssessmentCharge::query()
            ->where('assessment_participant_id', $attempt->id)
            ->where('participant_id', $principal->participantId)
            ->where('organization_id', $principal->organizationId)
            ->where('package_id', $package->id)
            ->lockForUpdate()->limit(2)->get());
        $genericEntitlements = Entitlement::query()->where('participant_id', $principal->participantId)
            ->orderBy('id')->lockForUpdate()->limit(2)->get();
        $entitlement = $this->one(AssessmentEntitlement::query()
            ->where('assessment_participant_id', $attempt->id)->where('charge_id', $charge->id)
            ->where('participant_id', $principal->participantId)
            ->where('organization_id', $principal->organizationId)
            ->where('test_type', $instrument->value)
            ->where('status', 'ready')->whereNotNull('ready_at')
            ->whereRaw('ready_at <= CURRENT_TIMESTAMP')->whereNull('started_at')->whereNull('completed_at')
            ->lockForUpdate()->limit(2)->get());

        if ($orders->isNotEmpty() || $selections->isNotEmpty() || $genericEntitlements->isNotEmpty()
            || $cases->contains(fn (AssessmentCase $candidate): bool => $candidate->origin !== CaseAuthorizationOrigin::Integrated->value)
            || ! in_array($attempt->assessment_status, ['READY', 'IN_PROGRESS'], true)
            || $attempt->revoked_at !== null || $attempt->finalized_at !== null
            || $case->public_id !== $attempt->assessment_attempt_id
            || $case->participant_id !== $principal->participantId
            || $case->organization_id !== $principal->organizationId
            || $case->package_id !== $package->id
            || $case->origin !== CaseAuthorizationOrigin::Integrated->value) {
            $this->reject();
        }

        try {
            $canonical = $this->integratedGate->assertReady($principal, $instrument->value);
        } catch (EntitlementLocked) {
            $this->reject();
        }
        if ($canonical->id !== $entitlement->id) {
            $this->reject();
        }

        return $this->authorization($case, $instrument, CaseAuthorizationOrigin::Integrated,
            CaseAuthorizationGrantKind::AssessmentEntitlement, $entitlement->id);
    }

    public function resolveParticipantForUpdate(
        ParticipantPrincipal $principal,
        GenericAssessmentInstrument $instrument,
    ): CaseAuthorization {
        $this->assertScope();

        $participant = $this->one(Participant::query()->whereKey($principal->participantId)
            ->where('branch_id', $principal->branchId)->lockForUpdate()->limit(2)->get());

        return $participant->source_system === CaseAuthorizationOrigin::DirectPublic->value
            ? $this->resolveDirect($participant, $instrument)
            : $this->resolveLegacy($participant, $instrument);
    }

    public function resolveSelectedParticipantForUpdate(
        ParticipantPrincipal $principal,
        AssessmentSessionSelectionCandidate $candidate,
    ): CaseAuthorization {
        $this->assertScope();
        $this->assertSelectedCandidateStillCanonical($principal, $candidate);

        $participant = $this->one(Participant::query()->whereKey($principal->participantId)
            ->lockForUpdate()->limit(2)->get());
        if ($participant->branch_id !== $principal->branchId
            || $candidate->participantId !== $principal->participantId
            || $candidate->organizationId !== $principal->branchId
            || $candidate->assessmentParticipantId !== null
            || $candidate->retestCandidate) {
            $this->reject();
        }

        $expectedOrigin = match ($participant->source_system) {
            CaseAuthorizationOrigin::DirectPublic->value => CaseAuthorizationOrigin::DirectPublic,
            'SELEKSI_BEASISWA_JEPANG' => CaseAuthorizationOrigin::LegacySelection,
            default => $this->reject(),
        };
        if ($candidate->origin !== $expectedOrigin) {
            $this->reject();
        }

        $cases = AssessmentCase::query()->where('participant_id', $principal->participantId)
            ->orderBy('id')->lockForUpdate()->get();
        if ($cases->contains(fn (AssessmentCase $case): bool => $case->organization_id !== $principal->branchId
            || $case->origin !== $expectedOrigin->value)) {
            $this->reject();
        }
        $case = $this->one($cases->where('public_id', $candidate->historyKey->casePublicId)->values());
        $grantId = $this->candidateEntitlementId($candidate);

        return $expectedOrigin === CaseAuthorizationOrigin::DirectPublic
            ? $this->resolveSelectedDirect($participant, $case, $candidate, $grantId)
            : $this->resolveSelectedLegacy($participant, $case, $candidate, $grantId);
    }

    private function assertSelectedCandidateStillCanonical(
        ParticipantPrincipal $principal,
        AssessmentSessionSelectionCandidate $candidate,
    ): void {
        $projection = $this->participantCandidates->project($principal, $candidate->historyKey->instrument);
        $outcome = $this->selectionPolicy->select($projection->scope, $projection->candidates);
        $expectedKind = $candidate->isLiveReplay()
            ? AssessmentSessionSelectionKind::Replay
            : AssessmentSessionSelectionKind::Selected;

        if ($outcome->kind !== $expectedKind || $outcome->candidate === null
            || ! $this->sameCandidate($candidate, $outcome->candidate)) {
            $this->reject();
        }
    }

    private function sameCandidate(
        AssessmentSessionSelectionCandidate $expected,
        AssessmentSessionSelectionCandidate $actual,
    ): bool {
        return $expected->historyKey->matches($actual->historyKey)
            && $expected->participantId === $actual->participantId
            && $expected->organizationId === $actual->organizationId
            && $expected->origin === $actual->origin
            && $expected->durableSourceGrantId === $actual->durableSourceGrantId
            && $expected->eligibleForAllocation === $actual->eligibleForAllocation
            && $expected->sessionPublicId === $actual->sessionPublicId
            && $expected->sessionStatus === $actual->sessionStatus
            && $expected->assessmentParticipantId === $actual->assessmentParticipantId
            && $expected->retestCandidate === $actual->retestCandidate;
    }

    private function resolveSelectedDirect(
        Participant $participant,
        AssessmentCase $case,
        AssessmentSessionSelectionCandidate $candidate,
        int $grantId,
    ): CaseAuthorization {
        if (! is_int($participant->package_id) || $case->package_id !== $participant->package_id) {
            $this->reject();
        }
        $package = $this->one(TestPackage::query()->whereKey($participant->package_id)
            ->lockForUpdate()->limit(2)->get());
        $this->assertMainComposition($this->packageTypes($package->id), $candidate->historyKey->instrument);

        $orders = Order::query()->where('participant_id', $participant->id)
            ->orderBy('id')->lockForUpdate()->get();
        $order = $this->one($orders->where('assessment_case_id', $case->id)->values());
        $selections = SelectionParticipant::query()->where('participant_id', $participant->id)
            ->orderBy('id')->lockForUpdate()->limit(1)->get();
        $integrated = AssessmentParticipant::query()->where('participant_id', $participant->id)
            ->orderBy('id')->lockForUpdate()->limit(1)->get();
        if ($selections->isNotEmpty() || $integrated->isNotEmpty()
            || $order->public_id !== $case->public_id
            || $order->status->value !== 'paid' || $order->paid_at === null) {
            $this->reject();
        }

        $this->assertSelectedEntitlementAndHistory($participant, $case, $candidate, $grantId, $order, null);

        return $this->authorization($case, $candidate->historyKey->instrument,
            CaseAuthorizationOrigin::DirectPublic, CaseAuthorizationGrantKind::Entitlement, $grantId);
    }

    private function resolveSelectedLegacy(
        Participant $participant,
        AssessmentCase $case,
        AssessmentSessionSelectionCandidate $candidate,
        int $grantId,
    ): CaseAuthorization {
        if ($participant->package_id !== null || $case->package_id !== null) {
            $this->reject();
        }
        $orders = Order::query()->where('participant_id', $participant->id)
            ->orderBy('id')->lockForUpdate()->limit(1)->get();
        $selections = SelectionParticipant::query()->where('participant_id', $participant->id)
            ->orderBy('id')->lockForUpdate()->get();
        $selection = $this->one($selections->where('assessment_case_id', $case->id)->values());
        $integrated = AssessmentParticipant::query()->where('participant_id', $participant->id)
            ->orderBy('id')->lockForUpdate()->limit(1)->get();
        if ($orders->isNotEmpty() || $integrated->isNotEmpty()) {
            $this->reject();
        }

        $this->assertSelectedEntitlementAndHistory($participant, $case, $candidate, $grantId, null, $selection);

        return $this->authorization($case, $candidate->historyKey->instrument,
            CaseAuthorizationOrigin::LegacySelection, CaseAuthorizationGrantKind::Entitlement, $grantId);
    }

    private function assertSelectedEntitlementAndHistory(
        Participant $participant,
        AssessmentCase $case,
        AssessmentSessionSelectionCandidate $candidate,
        int $grantId,
        ?Order $order,
        ?SelectionParticipant $selection,
    ): void {
        $instrument = $candidate->historyKey->instrument;
        $entitlement = $this->one(Entitlement::query()->whereKey($grantId)
            ->where('participant_id', $participant->id)
            ->where('assessment_case_id', $case->id)
            ->where('test_type', $instrument->value)
            ->lockForUpdate()->limit(2)->get());
        if (($order === null ? $entitlement->order_id !== null : $entitlement->order_id !== $order->id)
            || ! $this->entitlementTimeIsCurrent($grantId)) {
            $this->reject();
        }

        $sessions = DB::table('test_sessions')
            ->where('participant_id', $participant->id)
            ->where('assessment_case_id', $case->id)
            ->where('test_type', $instrument->value)
            ->orderBy('attempt_no')->orderBy('id')->lockForUpdate()->limit(2)->get();

        if ($candidate->isLiveReplay()) {
            if ($entitlement->status !== 'in_progress' || $entitlement->started_at === null
                || $entitlement->completed_at !== null || $sessions->count() !== 1) {
                $this->reject();
            }
            $this->assertReplayIdentity((array) $sessions->sole(), $participant, $case,
                $candidate, $grantId, $order, $selection);

            return;
        }

        if (! $candidate->eligibleForAllocation || $candidate->sessionPublicId !== null
            || $candidate->sessionStatus !== null || $entitlement->status !== 'ready'
            || $entitlement->started_at !== null || $entitlement->completed_at !== null
            || $sessions->isNotEmpty()) {
            $this->reject();
        }
    }

    /** @param array<string,mixed> $session */
    private function assertReplayIdentity(
        array $session,
        Participant $participant,
        AssessmentCase $case,
        AssessmentSessionSelectionCandidate $candidate,
        int $grantId,
        ?Order $order,
        ?SelectionParticipant $selection,
    ): void {
        $instrument = $candidate->historyKey->instrument;
        $authorizationId = "grant:v1:{$candidate->origin->value}:entitlement:{$grantId}";
        $intentId = "allocation:v1:{$candidate->origin->value}:entitlement:{$grantId}:{$instrument->value}";
        if (($session['public_id'] ?? null) !== $candidate->sessionPublicId
            || ($session['status'] ?? null) !== AssessmentSessionStatus::InProgress->value
            || (int) ($session['participant_id'] ?? 0) !== $participant->id
            || (int) ($session['assessment_case_id'] ?? 0) !== $case->id
            || ($session['test_type'] ?? null) !== $instrument->value
            || ($session['authorization_id'] ?? null) !== $authorizationId
            || ($session['allocation_intent_id'] ?? null) !== $intentId) {
            $this->reject();
        }

        $grants = DB::table('test_session_grants')
            ->where('test_session_id', (int) ($session['id'] ?? 0))
            ->lockForUpdate()->limit(2)->get();
        if ($grants->count() !== 1) {
            $this->reject();
        }
        $grant = (array) $grants->sole();
        if ((int) ($grant['assessment_case_id'] ?? 0) !== $case->id
            || (int) ($grant['participant_id'] ?? 0) !== $participant->id
            || (int) ($grant['organization_id'] ?? 0) !== $candidate->organizationId
            || ($grant['test_type'] ?? null) !== $instrument->value
            || ($grant['origin'] ?? null) !== $candidate->origin->value
            || ($grant['grant_kind'] ?? null) !== CaseAuthorizationGrantKind::Entitlement->value
            || (int) ($grant['entitlement_id'] ?? 0) !== $grantId
            || ($order === null ? ($grant['order_id'] ?? null) !== null : (int) ($grant['order_id'] ?? 0) !== $order->id)
            || ($selection === null ? ($grant['selection_participant_id'] ?? null) !== null : (int) ($grant['selection_participant_id'] ?? 0) !== $selection->id)
            || ($grant['assessment_participant_id'] ?? null) !== null
            || ($grant['assessment_entitlement_id'] ?? null) !== null) {
            $this->reject();
        }
    }

    private function entitlementTimeIsCurrent(int $grantId): bool
    {
        return Entitlement::query()->whereKey($grantId)
            ->whereNotNull('ready_at')->whereRaw('ready_at <= CURRENT_TIMESTAMP')->exists();
    }

    private function candidateEntitlementId(AssessmentSessionSelectionCandidate $candidate): int
    {
        $prefix = 'entitlement:';
        $value = $candidate->durableSourceGrantId;
        if (! is_string($value) || ! str_starts_with($value, $prefix)) {
            $this->reject();
        }
        $id = substr($value, strlen($prefix));
        if ($id === '' || ! ctype_digit($id) || (int) $id < 1) {
            $this->reject();
        }

        return (int) $id;
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
            ->orderBy('id')->lockForUpdate()->limit(2)->get());
        $order = $this->one(Order::query()->where('participant_id', $participant->id)
            ->orderBy('id')->lockForUpdate()->limit(2)->get());
        $selection = SelectionParticipant::query()->where('participant_id', $participant->id)
            ->orderBy('id')->lockForUpdate()->limit(2)->get();
        $integrated = AssessmentParticipant::query()->where('participant_id', $participant->id)
            ->orderBy('id')->lockForUpdate()->limit(2)->get();
        $entitlements = Entitlement::query()->where('participant_id', $participant->id)
            ->orderBy('id')->lockForUpdate()->get();
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
        $case = $this->one(AssessmentCase::query()->where('participant_id', $participant->id)
            ->orderBy('id')->lockForUpdate()->limit(2)->get());
        $orders = Order::query()->where('participant_id', $participant->id)
            ->orderBy('id')->lockForUpdate()->limit(2)->get();
        $selection = $this->one(SelectionParticipant::query()->where('participant_id', $participant->id)
            ->orderBy('id')->lockForUpdate()->limit(2)->get());
        $integrated = AssessmentParticipant::query()->where('participant_id', $participant->id)
            ->orderBy('id')->lockForUpdate()->limit(2)->get();
        $entitlements = Entitlement::query()->where('participant_id', $participant->id)
            ->orderBy('id')->lockForUpdate()->get();
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
        } catch (DomainException) {
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
