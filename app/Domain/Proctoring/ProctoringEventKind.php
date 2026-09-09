<?php

declare(strict_types=1);

namespace App\Domain\Proctoring;

enum ProctoringEventKind: string
{
    case CameraPermissionDenied = 'CAMERA_PERMISSION_DENIED';
    case CameraUnavailable = 'CAMERA_UNAVAILABLE';
    case CameraInterrupted = 'CAMERA_INTERRUPTED';
    case ScreenDeparture = 'SCREEN_DEPARTURE';
    case FaceMismatch = 'FACE_MISMATCH';
    case SecondFaceDetected = 'SECOND_FACE_DETECTED';
    case AudioAssistanceDetected = 'AUDIO_ASSISTANCE_DETECTED';
    case SubstitutionConfirmed = 'SUBSTITUTION_CONFIRMED';
    case AssistanceConfirmed = 'ASSISTANCE_CONFIRMED';
    case IdentityFailure = 'IDENTITY_FAILURE';
    case SubtestIncomplete = 'SUBTEST_INCOMPLETE';
    case InvalidResponsePatternConfirmed = 'INVALID_RESPONSE_PATTERN_CONFIRMED';
}
