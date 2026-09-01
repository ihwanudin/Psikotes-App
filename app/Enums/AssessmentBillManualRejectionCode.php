<?php

declare(strict_types=1);

namespace App\Enums;

enum AssessmentBillManualRejectionCode: string
{
    case AmountMismatch = 'AMOUNT_MISMATCH';
    case UnreadableProof = 'UNREADABLE_PROOF';
    case WrongBeneficiary = 'WRONG_BENEFICIARY';
    case DuplicateProof = 'DUPLICATE_PROOF';
    case OtherUnverifiable = 'OTHER_UNVERIFIABLE';
}
