<?php

declare(strict_types=1);

namespace App\Services\AssessmentSessions;

use App\Domain\AssessmentSessions\AssessmentSessionCandidateProjection;
use App\Domain\AssessmentSessions\AssessmentSessionHistoryKey;
use App\Domain\AssessmentSessions\AssessmentSessionSelectionCandidate;
use App\Domain\AssessmentSessions\AssessmentSessionSelectionScope;
use App\Domain\AssessmentSessions\AssessmentSessionStatus;
use App\Domain\AssessmentSessions\CaseAuthorizationOrigin;
use App\Domain\AssessmentSessions\CaseAuthorizationRejected;
use App\Domain\AssessmentSessions\GenericAssessmentInstrument;
use App\Domain\AssessmentSessions\InvalidAssessmentSessionState;
use App\Security\RlsContextRunner;
use App\Services\ParticipantAuth\ParticipantPrincipal;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class ParticipantAssessmentSessionCandidates
{
    public function __construct(private RlsContextRunner $contexts) {}

    public function project(
        ParticipantPrincipal $principal,
        GenericAssessmentInstrument $instrument,
    ): AssessmentSessionCandidateProjection {
        $this->assertServiceTransaction();

        $participantRows = DB::table('participants')
            ->where('id', $principal->participantId)
            ->lockForUpdate()
            ->limit(2)
            ->get();
        if ($participantRows->count() !== 1) {
            throw new CaseAuthorizationRejected;
        }

        $participant = (array) $participantRows->sole();
        if ((int) ($participant['branch_id'] ?? 0) !== $principal->branchId) {
            throw new CaseAuthorizationRejected;
        }

        $origin = match ((string) ($participant['source_system'] ?? '')) {
            CaseAuthorizationOrigin::DirectPublic->value => CaseAuthorizationOrigin::DirectPublic,
            'SELEKSI_BEASISWA_JEPANG' => CaseAuthorizationOrigin::LegacySelection,
            default => throw new CaseAuthorizationRejected,
        };
        $participantPackageId = $origin === CaseAuthorizationOrigin::DirectPublic
            ? $this->positiveId($participant['package_id'] ?? null)
            : null;
        if ($origin === CaseAuthorizationOrigin::LegacySelection && ($participant['package_id'] ?? null) !== null) {
            throw new CaseAuthorizationRejected;
        }
        $scope = AssessmentSessionSelectionScope::forParticipantCredential(
            $principal->participantId,
            $principal->branchId,
            $origin,
            $instrument,
        );

        $cases = DB::table('assessment_cases')
            ->where('participant_id', $principal->participantId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();

        foreach ($cases as $case) {
            if ((int) ($case['organization_id'] ?? 0) !== $principal->branchId
                || ($case['origin'] ?? null) !== $origin->value
                || ($origin === CaseAuthorizationOrigin::DirectPublic
                    && (int) ($case['package_id'] ?? 0) !== $participantPackageId)) {
                throw new CaseAuthorizationRejected;
            }
        }

        $this->assertNoOpposingOriginGraph($principal, $origin);

        $candidates = [];
        foreach ($cases as $case) {
            $candidate = $origin === CaseAuthorizationOrigin::DirectPublic
                ? $this->directCandidate($principal, $instrument, $case)
                : $this->legacyCandidate($principal, $instrument, $case);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        return new AssessmentSessionCandidateProjection($scope, $candidates);
    }

    /** @param array<string,mixed> $case */
    private function directCandidate(
        ParticipantPrincipal $principal,
        GenericAssessmentInstrument $instrument,
        array $case,
    ): ?AssessmentSessionSelectionCandidate {
        $packageId = $this->positiveId($case['package_id'] ?? null);
        $packageTypes = DB::table('package_items')
            ->where('package_id', $packageId)
            ->orderBy('test_type')
            ->lockForUpdate()
            ->pluck('test_type')
            ->map(static fn (mixed $type): string => (string) $type)
            ->all();
        if (! in_array('dass21', $packageTypes, true)
            || array_diff($packageTypes, ['dass21', 'ist', 'papi', 'rmib', 'kraepelin']) !== []) {
            throw new CaseAuthorizationRejected;
        }
        if (! in_array($instrument->value, $packageTypes, true)) {
            return null;
        }

        $orders = DB::table('orders')
            ->where('participant_id', $principal->participantId)
            ->where('assessment_case_id', $this->positiveId($case['id'] ?? null))
            ->orderBy('id')
            ->lockForUpdate()
            ->limit(2)
            ->get();
        if ($orders->count() !== 1) {
            throw new CaseAuthorizationRejected;
        }
        $order = (array) $orders->sole();
        if (($order['public_id'] ?? null) !== ($case['public_id'] ?? null)) {
            throw new CaseAuthorizationRejected;
        }

        return $this->candidate(
            $principal,
            $instrument,
            $case,
            $order,
            null,
            ($order['status'] ?? null) === 'paid' && ($order['paid_at'] ?? null) !== null,
        );
    }

    /** @param array<string,mixed> $case */
    private function legacyCandidate(
        ParticipantPrincipal $principal,
        GenericAssessmentInstrument $instrument,
        array $case,
    ): ?AssessmentSessionSelectionCandidate {
        if (($case['package_id'] ?? null) !== null) {
            throw new CaseAuthorizationRejected;
        }
        $selections = DB::table('selection_participants')
            ->where('participant_id', $principal->participantId)
            ->where('assessment_case_id', $this->positiveId($case['id'] ?? null))
            ->orderBy('id')
            ->lockForUpdate()
            ->limit(2)
            ->get();
        if ($selections->count() !== 1) {
            throw new CaseAuthorizationRejected;
        }

        return $this->candidate($principal, $instrument, $case, null, (array) $selections->sole(), true);
    }

    /**
     * @param  array<string,mixed>  $case
     * @param  array<string,mixed>|null  $order
     * @param  array<string,mixed>|null  $selection
     */
    private function candidate(
        ParticipantPrincipal $principal,
        GenericAssessmentInstrument $instrument,
        array $case,
        ?array $order,
        ?array $selection,
        bool $sourceActive,
    ): ?AssessmentSessionSelectionCandidate {
        $caseId = $this->positiveId($case['id'] ?? null);
        $entitlements = DB::table('entitlements')
            ->where('participant_id', $principal->participantId)
            ->where('assessment_case_id', $caseId)
            ->where('test_type', $instrument->value)
            ->orderBy('id')
            ->lockForUpdate()
            ->limit(2)
            ->get();
        if ($entitlements->isEmpty()) {
            if (($case['origin'] ?? null) === CaseAuthorizationOrigin::DirectPublic->value) {
                throw new CaseAuthorizationRejected;
            }

            return null;
        }
        if ($entitlements->count() !== 1) {
            throw new CaseAuthorizationRejected;
        }
        $entitlement = (array) $entitlements->sole();
        $expectedOrderId = $order === null ? null : $this->positiveId($order['id'] ?? null);
        $actualOrderId = $entitlement['order_id'] ?? null;
        if (($expectedOrderId === null && $actualOrderId !== null)
            || ($expectedOrderId !== null && (int) $actualOrderId !== $expectedOrderId)) {
            throw new CaseAuthorizationRejected;
        }

        $sessions = DB::table('test_sessions')
            ->where('participant_id', $principal->participantId)
            ->where('assessment_case_id', $caseId)
            ->where('test_type', $instrument->value)
            ->orderBy('attempt_no')
            ->orderBy('id')
            ->lockForUpdate()
            ->limit(2)
            ->get();
        if ($sessions->count() > 1) {
            throw new InvalidAssessmentSessionState('Multiple case-scoped session histories are not yet supported.');
        }

        $entitlementId = $this->positiveId($entitlement['id'] ?? null);
        $durableGrantId = 'entitlement:'.$entitlementId;
        if ($sessions->isEmpty()) {
            $eligible = $sourceActive
                && ($entitlement['status'] ?? null) === 'ready'
                && ($entitlement['ready_at'] ?? null) !== null
                && DB::table('entitlements')->where('id', $entitlementId)
                    ->whereRaw('ready_at <= CURRENT_TIMESTAMP')
                    ->whereNull('started_at')
                    ->whereNull('completed_at')
                    ->exists();

            return $this->selectionCandidate($case, $principal, $instrument, $durableGrantId, $eligible);
        }

        $session = (array) $sessions->sole();
        $grants = DB::table('test_session_grants')
            ->where('test_session_id', $this->positiveId($session['id'] ?? null))
            ->lockForUpdate()
            ->limit(2)
            ->get();
        if ($grants->count() !== 1) {
            throw new CaseAuthorizationRejected;
        }
        $grant = (array) $grants->sole();
        $expectedSelectionId = $selection === null ? null : $this->positiveId($selection['id'] ?? null);
        if ((int) ($grant['assessment_case_id'] ?? 0) !== $caseId
            || (int) ($grant['participant_id'] ?? 0) !== $principal->participantId
            || (int) ($grant['organization_id'] ?? 0) !== $principal->branchId
            || ($grant['test_type'] ?? null) !== $instrument->value
            || ($grant['origin'] ?? null) !== ($case['origin'] ?? null)
            || ($grant['grant_kind'] ?? null) !== 'entitlement'
            || (int) ($grant['entitlement_id'] ?? 0) !== $entitlementId
            || ($expectedOrderId === null ? ($grant['order_id'] ?? null) !== null : (int) ($grant['order_id'] ?? 0) !== $expectedOrderId)
            || ($expectedSelectionId === null ? ($grant['selection_participant_id'] ?? null) !== null : (int) ($grant['selection_participant_id'] ?? 0) !== $expectedSelectionId)
            || ($grant['assessment_participant_id'] ?? null) !== null
            || ($grant['assessment_entitlement_id'] ?? null) !== null) {
            throw new CaseAuthorizationRejected;
        }
        $status = AssessmentSessionStatus::tryFrom((string) ($session['status'] ?? ''))
            ?? throw new InvalidAssessmentSessionState('Persisted session status is invalid.');

        return $this->selectionCandidate(
            $case,
            $principal,
            $instrument,
            $durableGrantId,
            false,
            (string) ($session['public_id'] ?? ''),
            $status,
        );
    }

    /** @param array<string,mixed> $case */
    private function selectionCandidate(
        array $case,
        ParticipantPrincipal $principal,
        GenericAssessmentInstrument $instrument,
        ?string $durableGrantId,
        bool $eligible,
        ?string $sessionPublicId = null,
        ?AssessmentSessionStatus $sessionStatus = null,
    ): AssessmentSessionSelectionCandidate {
        return new AssessmentSessionSelectionCandidate(
            new AssessmentSessionHistoryKey((string) ($case['public_id'] ?? ''), $instrument),
            $principal->participantId,
            $principal->branchId,
            CaseAuthorizationOrigin::from((string) ($case['origin'] ?? '')),
            $durableGrantId,
            $eligible,
            $sessionPublicId,
            $sessionStatus,
            null,
            false,
        );
    }

    private function assertNoOpposingOriginGraph(
        ParticipantPrincipal $principal,
        CaseAuthorizationOrigin $origin,
    ): void {
        $opposingExists = match ($origin) {
            CaseAuthorizationOrigin::DirectPublic => $this->hasLockedRows('selection_participants', $principal)
                || $this->hasLockedRows('assessment_participants', $principal),
            CaseAuthorizationOrigin::LegacySelection => $this->hasLockedRows('orders', $principal)
                || $this->hasLockedRows('assessment_participants', $principal),
            CaseAuthorizationOrigin::Integrated => true,
        };
        if ($opposingExists) {
            throw new CaseAuthorizationRejected;
        }
    }

    private function hasLockedRows(string $table, ParticipantPrincipal $principal): bool
    {
        return DB::table($table)
            ->where('participant_id', $principal->participantId)
            ->orderBy('id')
            ->lockForUpdate()
            ->first() !== null;
    }

    private function positiveId(mixed $value): int
    {
        if ((! is_int($value) && (! is_string($value) || ! ctype_digit($value))) || (int) $value < 1) {
            throw new CaseAuthorizationRejected;
        }

        return (int) $value;
    }

    private function assertServiceTransaction(): void
    {
        if ($this->contexts->current()?->role !== 'service' || DB::transactionLevel() < 1) {
            throw new LogicException('Session candidate projection requires an active service transaction.');
        }
    }
}
