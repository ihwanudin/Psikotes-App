<?php

declare(strict_types=1);

namespace App\Enums;

enum AssessmentBillManualReviewError: string
{
    case NotFound = 'ASSESSMENT_BILL_REVIEW_NOT_FOUND';
    case Conflict = 'ASSESSMENT_BILL_REVIEW_CONFLICT';
    case StateInvalid = 'ASSESSMENT_BILL_REVIEW_STATE_INVALID';
    case ScopeInvalid = 'ASSESSMENT_BILL_REVIEW_SCOPE_INVALID';
    case ChannelInvalid = 'ASSESSMENT_BILL_REVIEW_CHANNEL_INVALID';
    case ProofInvalid = 'ASSESSMENT_BILL_REVIEW_PROOF_INVALID';
}
