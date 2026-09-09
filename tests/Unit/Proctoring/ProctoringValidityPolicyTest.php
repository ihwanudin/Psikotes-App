<?php

declare(strict_types=1);

namespace Tests\Unit\Proctoring;

use App\Domain\Proctoring\ProctoringAdjudicatedFinding;
use App\Domain\Proctoring\ProctoringAdjudicatedFindingKind;
use App\Domain\Proctoring\ProctoringEvent;
use App\Domain\Proctoring\ProctoringEventKind;
use App\Domain\Proctoring\ProctoringEvidenceSource;
use App\Domain\Proctoring\ProctoringInstrument;
use App\Domain\Proctoring\ProctoringValidity;
use App\Domain\Proctoring\ProctoringValidityPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProctoringValidityPolicyTest extends TestCase
{
    #[DataProvider('automaticV2Events')]
    public function test_camera_departure_network_or_timing_evidence_sets_minimum_v2(
        ProctoringEventKind $kind,
        ProctoringEvidenceSource $source,
    ): void {
        $decision = (new ProctoringValidityPolicy)->decide([
            $this->event('evidence-1', $kind, $source),
        ]);

        $this->assertSame(ProctoringValidity::V2, $decision->validity);
        $this->assertTrue($decision->procedureNoteRequired);
        $this->assertFalse($decision->publicationBlocked);
        $this->assertSame(1, $decision->uniqueEvidenceCount);
    }

    /** @return iterable<string, array{ProctoringEventKind, ProctoringEvidenceSource}> */
    public static function automaticV2Events(): iterable
    {
        yield 'camera permission denied' => [ProctoringEventKind::CameraPermissionDenied, ProctoringEvidenceSource::ClientObservation];
        yield 'camera unavailable' => [ProctoringEventKind::CameraUnavailable, ProctoringEvidenceSource::ClientObservation];
        yield 'camera interrupted for any duration' => [ProctoringEventKind::CameraInterrupted, ProctoringEvidenceSource::ClientObservation];
        yield 'one coalesced screen departure' => [ProctoringEventKind::ScreenDeparture, ProctoringEvidenceSource::ClientObservation];
        yield 'network interruption' => [ProctoringEventKind::NetworkInterrupted, ProctoringEvidenceSource::ServerFinding];
        yield 'unreasonable timing' => [ProctoringEventKind::UnreasonableTiming, ProctoringEvidenceSource::ServerFinding];
    }

    #[DataProvider('reviewOnlyEvents')]
    public function test_unadjudicated_detection_is_pending_and_blocks_publication(ProctoringEventKind $kind): void
    {
        $decision = (new ProctoringValidityPolicy)->decide([
            $this->event('evidence-review', $kind),
        ]);

        $this->assertSame(ProctoringValidity::V1, $decision->validity);
        $this->assertTrue($decision->humanReviewRequired);
        $this->assertTrue($decision->pendingAdjudication);
        $this->assertTrue($decision->publicationBlocked);
        $this->assertSame(['evidence-review'], $decision->pendingEvidenceIds);
    }

    /** @return iterable<string, array{ProctoringEventKind}> */
    public static function reviewOnlyEvents(): iterable
    {
        yield 'face mismatch' => [ProctoringEventKind::FaceMismatch];
        yield 'second face' => [ProctoringEventKind::SecondFaceDetected];
        yield 'audio assistance signal' => [ProctoringEventKind::AudioAssistanceDetected];
    }

    #[DataProvider('automaticV3Events')]
    public function test_authoritative_invalidity_sets_v3(ProctoringEventKind $kind): void
    {
        $decision = (new ProctoringValidityPolicy)->decide([
            $this->event('evidence-invalid', $kind, ProctoringEvidenceSource::ServerFinding),
        ]);

        $this->assertSame(ProctoringValidity::V3, $decision->validity);
        $this->assertTrue($decision->publicationBlocked);
        $this->assertFalse($decision->pendingAdjudication);
    }

    /** @return iterable<string, array{ProctoringEventKind}> */
    public static function automaticV3Events(): iterable
    {
        yield 'identity failure' => [ProctoringEventKind::IdentityFailure];
        yield 'subtest incomplete' => [ProctoringEventKind::SubtestIncomplete];
        yield 'invalid response pattern confirmed' => [ProctoringEventKind::InvalidResponsePatternConfirmed];
    }

    public function test_pending_detection_remains_blocking_when_v2_is_also_present(): void
    {
        $decision = (new ProctoringValidityPolicy)->decide([
            $this->event('camera', ProctoringEventKind::CameraInterrupted),
            $this->event('face', ProctoringEventKind::FaceMismatch),
        ]);

        $this->assertSame(ProctoringValidity::V2, $decision->validity);
        $this->assertTrue($decision->procedureNoteRequired);
        $this->assertTrue($decision->pendingAdjudication);
        $this->assertTrue($decision->publicationBlocked);
    }

    public function test_v3_dominates_v2_while_unresolved_detection_remains_traceable(): void
    {
        $decision = (new ProctoringValidityPolicy)->decide([
            $this->event('camera', ProctoringEventKind::CameraInterrupted),
            $this->event('face', ProctoringEventKind::FaceMismatch),
            $this->event('identity', ProctoringEventKind::IdentityFailure, ProctoringEvidenceSource::ServerFinding),
        ]);

        $this->assertSame(ProctoringValidity::V3, $decision->validity);
        $this->assertTrue($decision->publicationBlocked);
        $this->assertTrue($decision->pendingAdjudication);
        $this->assertSame(['face'], $decision->pendingEvidenceIds);
    }

    public function test_human_dismissal_resolves_the_exact_raw_signal_without_changing_validity(): void
    {
        $decision = (new ProctoringValidityPolicy)->decide(
            [$this->event('face-raw', ProctoringEventKind::FaceMismatch)],
            [$this->finding('review-1', 'face-raw', ProctoringAdjudicatedFindingKind::SignalDismissed)],
        );

        $this->assertSame(ProctoringValidity::V1, $decision->validity);
        $this->assertFalse($decision->pendingAdjudication);
        $this->assertFalse($decision->publicationBlocked);
        $this->assertSame(1, $decision->uniqueAdjudicationCount);
    }

    #[DataProvider('confirmedFindings')]
    public function test_confirmed_human_finding_requires_and_resolves_compatible_raw_evidence(
        ProctoringEventKind $rawKind,
        ProctoringAdjudicatedFindingKind $findingKind,
    ): void {
        $decision = (new ProctoringValidityPolicy)->decide(
            [$this->event('raw-1', $rawKind)],
            [$this->finding('review-1', 'raw-1', $findingKind)],
        );

        $this->assertSame(ProctoringValidity::V3, $decision->validity);
        $this->assertTrue($decision->publicationBlocked);
        $this->assertFalse($decision->pendingAdjudication);
    }

    /** @return iterable<string, array{ProctoringEventKind, ProctoringAdjudicatedFindingKind}> */
    public static function confirmedFindings(): iterable
    {
        yield 'substitution' => [ProctoringEventKind::FaceMismatch, ProctoringAdjudicatedFindingKind::SubstitutionConfirmed];
        yield 'second face assistance' => [ProctoringEventKind::SecondFaceDetected, ProctoringAdjudicatedFindingKind::AssistanceConfirmed];
        yield 'audio assistance' => [ProctoringEventKind::AudioAssistanceDetected, ProctoringAdjudicatedFindingKind::AssistanceConfirmed];
    }

    public function test_finding_without_its_raw_evidence_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ProctoringValidityPolicy)->decide([], [
            $this->finding('review-1', 'missing-raw', ProctoringAdjudicatedFindingKind::SubstitutionConfirmed),
        ]);
    }

    public function test_finding_incompatible_with_its_raw_evidence_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ProctoringValidityPolicy)->decide(
            [$this->event('audio-raw', ProctoringEventKind::AudioAssistanceDetected)],
            [$this->finding('review-1', 'audio-raw', ProctoringAdjudicatedFindingKind::SubstitutionConfirmed)],
        );
    }

    /** @param array{string, string} $credentials */
    #[DataProvider('invalidAdjudicationCredentials')]
    public function test_adjudication_requires_nonblank_identity_and_token(array $credentials): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProctoringAdjudicatedFinding(
            'review-1',
            'face-raw',
            ProctoringAdjudicatedFindingKind::SignalDismissed,
            $credentials[0],
            $credentials[1],
        );
    }

    /** @return iterable<string, array{array{string, string}}> */
    public static function invalidAdjudicationCredentials(): iterable
    {
        yield 'blank adjudicator identity' => [[' ', 'adjudication-token-1']];
        yield 'blank adjudication token' => [['psychologist-1', ' ']];
    }

    public function test_event_kind_must_match_its_explicit_source_contract(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->event('identity', ProctoringEventKind::IdentityFailure, ProctoringEvidenceSource::ClientObservation);
    }

    public function test_duplicate_coalesced_evidence_does_not_inflate_the_decision(): void
    {
        $departure = $this->event('departure-1', ProctoringEventKind::ScreenDeparture);

        $decision = (new ProctoringValidityPolicy)->decide([$departure, $departure, $departure]);

        $this->assertSame(ProctoringValidity::V2, $decision->validity);
        $this->assertSame(1, $decision->uniqueEvidenceCount);
        $this->assertSame(['SCREEN_DEPARTURE'], $decision->markerCodes);
    }

    public function test_conflicting_payload_for_one_evidence_id_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ProctoringValidityPolicy)->decide([
            $this->event('same-evidence', ProctoringEventKind::ScreenDeparture),
            $this->event('same-evidence', ProctoringEventKind::CameraInterrupted),
        ]);
    }

    public function test_dass_event_is_rejected_at_the_typed_boundary(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ProctoringInstrument::fromAssessmentCode('dass21');
    }

    public function test_invalid_or_non_typed_evidence_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ProctoringValidityPolicy)->decide(['SCREEN_DEPARTURE']);
    }

    public function test_empty_evidence_is_a_clean_publishable_v1_decision(): void
    {
        $decision = (new ProctoringValidityPolicy)->decide([]);

        $this->assertSame(ProctoringValidity::V1, $decision->validity);
        $this->assertSame([], $decision->markerCodes);
        $this->assertFalse($decision->humanReviewRequired);
        $this->assertFalse($decision->pendingAdjudication);
        $this->assertFalse($decision->publicationBlocked);
        $this->assertSame(0, $decision->uniqueEvidenceCount);
    }

    private function event(
        string $evidenceId,
        ProctoringEventKind $kind,
        ProctoringEvidenceSource $source = ProctoringEvidenceSource::ClientObservation,
    ): ProctoringEvent {
        return new ProctoringEvent($evidenceId, ProctoringInstrument::Ist, $kind, $source);
    }

    private function finding(
        string $findingId,
        string $sourceEvidenceId,
        ProctoringAdjudicatedFindingKind $kind,
    ): ProctoringAdjudicatedFinding {
        return new ProctoringAdjudicatedFinding(
            $findingId,
            $sourceEvidenceId,
            $kind,
            'psychologist-1',
            'adjudication-token-1',
        );
    }
}
