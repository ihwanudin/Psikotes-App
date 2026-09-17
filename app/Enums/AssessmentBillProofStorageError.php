<?php

declare(strict_types=1);

namespace App\Enums;

enum AssessmentBillProofStorageError: string
{
    case NotFound = 'ASSESSMENT_BILL_PROOF_NOT_FOUND';
    case ContentInvalid = 'ASSESSMENT_BILL_PROOF_CONTENT_INVALID';
    case Conflict = 'ASSESSMENT_BILL_PROOF_CONFLICT';
    case StateInvalid = 'ASSESSMENT_BILL_PROOF_STATE_INVALID';
    case ScopeInvalid = 'ASSESSMENT_BILL_PROOF_SCOPE_INVALID';
    case ChannelInvalid = 'ASSESSMENT_BILL_PROOF_CHANNEL_INVALID';
    case StorageFailed = 'ASSESSMENT_BILL_PROOF_STORAGE_FAILED';
}
