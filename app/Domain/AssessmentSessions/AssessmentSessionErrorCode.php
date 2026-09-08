<?php

declare(strict_types=1);

namespace App\Domain\AssessmentSessions;

enum AssessmentSessionErrorCode: string
{
    case SessionNotStarted = 'SESSION_NOT_STARTED';
    case SessionClosed = 'SESSION_CLOSED';
    case DeadlineExceeded = 'DEADLINE_EXCEEDED';
    case AutosaveStaleRevision = 'AUTOSAVE_STALE_REVISION';
    case AutosaveRevisionGap = 'AUTOSAVE_REVISION_GAP';
    case MutationPayloadMismatch = 'MUTATION_PAYLOAD_MISMATCH';
    case InvalidAnswerBatch = 'INVALID_ANSWER_BATCH';
}
