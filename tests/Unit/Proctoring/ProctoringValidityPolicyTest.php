<?php

declare(strict_types=1);

namespace Tests\Unit\Proctoring;

use App\Domain\Proctoring\ProctoringEvent;
use App\Domain\Proctoring\ProctoringEventKind;
use App\Domain\Proctoring\ProctoringInstrument;
use App\Domain\Proctoring\ProctoringValidity;
use App\Domain\Proctoring\ProctoringValidityPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProctoringValidityPolicyTest extends TestCase
{
    #[DataProvider('automaticV2Events')]
    public function test_camera_failure_or_one_departure_sets_minimum_v2(ProctoringEventKind $kind): void
    {
        $decision = (new ProctoringValidityPolicy)->decide([
            $this->event('evidence-1', $kind),
        ]);

        $this->assertSame(ProctoringValidity::V2, $decision->validity);
        $this->assertTrue($decision->procedureNoteRequired);
        $this->assertFalse($decision->publicationBlocked);
        $this->assertSame(1, $decision->uniqueEvidenceCount);
    }

    /** @return iterable<string, array{ProctoringEventKind}> */
    public static function automaticV2Events(): iterable
    {
        yield 'camera permission denied' => [ProctoringEventKind::CameraPermissionDenied];
        yield 'camera unavailable' => [ProctoringEventKind::CameraUnavailable];
        yield 'camera interrupted for any duration' => [ProctoringEventKind::CameraInterrupted];
        yield 'one coalesced screen departure' => [ProctoringEventKind::ScreenDeparture];
    }

    #[DataProvider('reviewOnlyEvents')]
    public function test_unadjudicated_detection_remains_a_review_marker(ProctoringEventKind $kind): void
    {
        $decision = (new ProctoringValidityPolicy)->decide([
            $this->event('evidence-review', $kind),
        ]);

        $this->assertSame(ProctoringValidity::V1, $decision->validity);
        $this->assertTrue($decision->humanReviewRequired);
        $this->assertFalse($decision->procedureNoteRequired);
        $this->assertCount(1, $decision->markerCodes);
    }

    /** @return iterable<string, array{ProctoringEventKind}> */
    public static function reviewOnlyEvents(): iterable
    {
        yield 'face mismatch' => [ProctoringEventKind::FaceMismatch];
        yield 'second face' => [ProctoringEventKind::SecondFaceDetected];
        yield 'audio assistance signal' => [ProctoringEventKind::AudioAssistanceDetected];
    }

    #[DataProvider('automaticV3Events')]
    public function test_proven_invalidity_sets_v3(ProctoringEventKind $kind): void
    {
        $decision = (new ProctoringValidityPolicy)->decide([
            $this->event('evidence-invalid', $kind),
        ]);

        $this->assertSame(ProctoringValidity::V3, $decision->validity);
        $this->assertTrue($decision->publicationBlocked);
        $this->assertFalse($decision->procedureNoteRequired);
    }

    /** @return iterable<string, array{ProctoringEventKind}> */
    public static function automaticV3Events(): iterable
    {
        yield 'substitution confirmed' => [ProctoringEventKind::SubstitutionConfirmed];
        yield 'assistance confirmed' => [ProctoringEventKind::AssistanceConfirmed];
        yield 'identity failure' => [ProctoringEventKind::IdentityFailure];
        yield 'subtest incomplete' => [ProctoringEventKind::SubtestIncomplete];
        yield 'invalid response pattern confirmed' => [ProctoringEventKind::InvalidResponsePatternConfirmed];
    }

    public function test_v3_dominates_v2_and_review_only_markers(): void
    {
        $decision = (new ProctoringValidityPolicy)->decide([
            $this->event('camera', ProctoringEventKind::CameraInterrupted),
            $this->event('face', ProctoringEventKind::FaceMismatch),
            $this->event('identity', ProctoringEventKind::IdentityFailure),
        ]);

        $this->assertSame(ProctoringValidity::V3, $decision->validity);
        $this->assertTrue($decision->publicationBlocked);
        $this->assertTrue($decision->humanReviewRequired);
        $this->assertSame(3, $decision->uniqueEvidenceCount);
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

    public function test_invalid_or_non_typed_events_fail_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ProctoringValidityPolicy)->decide(['SCREEN_DEPARTURE']);
    }

    public function test_empty_evidence_is_a_clean_v1_decision(): void
    {
        $decision = (new ProctoringValidityPolicy)->decide([]);

        $this->assertSame(ProctoringValidity::V1, $decision->validity);
        $this->assertSame([], $decision->markerCodes);
        $this->assertFalse($decision->humanReviewRequired);
        $this->assertSame(0, $decision->uniqueEvidenceCount);
    }

    private function event(string $evidenceId, ProctoringEventKind $kind): ProctoringEvent
    {
        return new ProctoringEvent($evidenceId, ProctoringInstrument::Ist, $kind);
    }
}
