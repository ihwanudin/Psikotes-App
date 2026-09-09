<?php

declare(strict_types=1);

namespace App\Domain\Proctoring;

use InvalidArgumentException;

final class ProctoringValidityPolicy
{
    /**
     * @param  array<mixed>  $events
     */
    public function decide(array $events): ProctoringValidityDecision
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
        $humanReviewRequired = false;
        $markerCodes = [];

        foreach ($uniqueEvents as $event) {
            $markerCodes[$event->kind->value] = true;

            if ($this->requiresV3($event->kind)) {
                $validity = ProctoringValidity::V3;
            } elseif ($validity === ProctoringValidity::V1 && $this->requiresV2($event->kind)) {
                $validity = ProctoringValidity::V2;
            }

            $humanReviewRequired = $humanReviewRequired || $this->requiresHumanReview($event->kind);
        }

        $markers = array_keys($markerCodes);
        sort($markers);

        return new ProctoringValidityDecision(
            validity: $validity,
            markerCodes: $markers,
            uniqueEvidenceCount: count($uniqueEvents),
            humanReviewRequired: $humanReviewRequired,
            procedureNoteRequired: $validity === ProctoringValidity::V2,
            publicationBlocked: $validity === ProctoringValidity::V3,
        );
    }

    private function requiresV2(ProctoringEventKind $kind): bool
    {
        return in_array($kind, [
            ProctoringEventKind::CameraPermissionDenied,
            ProctoringEventKind::CameraUnavailable,
            ProctoringEventKind::CameraInterrupted,
            ProctoringEventKind::ScreenDeparture,
        ], true);
    }

    private function requiresV3(ProctoringEventKind $kind): bool
    {
        return in_array($kind, [
            ProctoringEventKind::SubstitutionConfirmed,
            ProctoringEventKind::AssistanceConfirmed,
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
