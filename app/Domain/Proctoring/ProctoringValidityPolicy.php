<?php

declare(strict_types=1);

namespace App\Domain\Proctoring;

use InvalidArgumentException;

final class ProctoringValidityPolicy
{
    /**
     * @param  array<mixed>  $events
     * @param  array<mixed>  $findings
     */
    public function decide(array $events, array $findings = []): ProctoringValidityDecision
    {
        /** @var array<string, ProctoringEvent> $uniqueEvents */
        $uniqueEvents = [];

        foreach ($events as $event) {
            if (! $event instanceof ProctoringEvent) {
                throw new InvalidArgumentException('Proctoring evidence must contain typed events.');
            }

            $existing = $uniqueEvents[$event->evidenceId] ?? null;
            if ($existing !== null && ! $existing->hasSamePayload($event)) {
                throw new InvalidArgumentException('Proctoring evidence ID has conflicting payloads.');
            }

            $uniqueEvents[$event->evidenceId] = $event;
        }

        $validity = ProctoringValidity::V1;
        /** @var array<string, true> $pendingEvidence */
        $pendingEvidence = [];
        $markerCodes = [];

        foreach ($uniqueEvents as $event) {
            $markerCodes[$event->kind->value] = true;

            if ($this->requiresV3($event->kind)) {
                $validity = ProctoringValidity::V3;
            } elseif ($validity === ProctoringValidity::V1 && $this->requiresV2($event->kind)) {
                $validity = ProctoringValidity::V2;
            }

            if ($this->requiresHumanReview($event->kind)) {
                $pendingEvidence[$event->evidenceId] = true;
            }
        }

        /** @var array<string, ProctoringAdjudicatedFinding> $uniqueFindings */
        $uniqueFindings = [];
        /** @var array<string, ProctoringAdjudicatedFinding> $findingsByEvidence */
        $findingsByEvidence = [];
        foreach ($findings as $finding) {
            if (! $finding instanceof ProctoringAdjudicatedFinding) {
                throw new InvalidArgumentException('Proctoring adjudication must contain typed findings.');
            }

            $existing = $uniqueFindings[$finding->findingId] ?? null;
            if ($existing !== null) {
                if (! $existing->hasSamePayload($finding)) {
                    throw new InvalidArgumentException('Proctoring finding ID has conflicting payloads.');
                }

                continue;
            }

            $source = $uniqueEvents[$finding->sourceEvidenceId] ?? null;
            if ($source === null || ! $finding->supports($source)) {
                throw new InvalidArgumentException('Proctoring finding has no compatible source evidence.');
            }
            if (isset($findingsByEvidence[$finding->sourceEvidenceId])) {
                throw new InvalidArgumentException('Proctoring evidence has conflicting adjudications.');
            }

            $uniqueFindings[$finding->findingId] = $finding;
            $findingsByEvidence[$finding->sourceEvidenceId] = $finding;
            unset($pendingEvidence[$finding->sourceEvidenceId]);
            $markerCodes[$finding->kind->value] = true;

            if ($finding->confirmsV3()) {
                $validity = ProctoringValidity::V3;
            }
        }

        $markers = array_keys($markerCodes);
        sort($markers);
        $pendingIds = array_keys($pendingEvidence);
        sort($pendingIds);
        $pendingAdjudication = $pendingIds !== [];

        return new ProctoringValidityDecision(
            validity: $validity,
            markerCodes: $markers,
            uniqueEvidenceCount: count($uniqueEvents),
            uniqueAdjudicationCount: count($uniqueFindings),
            humanReviewRequired: $pendingAdjudication,
            pendingAdjudication: $pendingAdjudication,
            pendingEvidenceIds: $pendingIds,
            procedureNoteRequired: $validity === ProctoringValidity::V2,
            publicationBlocked: $validity === ProctoringValidity::V3 || $pendingAdjudication,
        );
    }

    private function requiresV2(ProctoringEventKind $kind): bool
    {
        return in_array($kind, [
            ProctoringEventKind::CameraPermissionDenied,
            ProctoringEventKind::CameraUnavailable,
            ProctoringEventKind::CameraInterrupted,
            ProctoringEventKind::ScreenDeparture,
            ProctoringEventKind::NetworkInterrupted,
            ProctoringEventKind::UnreasonableTiming,
        ], true);
    }

    private function requiresV3(ProctoringEventKind $kind): bool
    {
        return in_array($kind, [
            ProctoringEventKind::IdentityFailure,
            ProctoringEventKind::SubtestIncomplete,
            ProctoringEventKind::InvalidResponsePatternConfirmed,
        ], true);
    }

    private function requiresHumanReview(ProctoringEventKind $kind): bool
    {
        return in_array($kind, [
            ProctoringEventKind::FaceMismatch,
            ProctoringEventKind::SecondFaceDetected,
            ProctoringEventKind::AudioAssistanceDetected,
        ], true);
    }
}
