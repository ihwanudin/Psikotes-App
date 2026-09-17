<?php

declare(strict_types=1);

namespace App\Data\Payments;

use App\Enums\AssessmentBillManualDecision;
use App\Enums\AssessmentBillManualRejectionCode;
use InvalidArgumentException;

final readonly class AssessmentBillManualReview
{
    public function __construct(
        public int $actorAdminId,
        public string $billReference,
        public string $expectedProofFingerprint,
        public AssessmentBillManualDecision $decision,
        public ?AssessmentBillManualRejectionCode $rejectionCode,
    ) {
        if ($actorAdminId < 1
            || ! preg_match('/^AB_[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $billReference)
            || ! preg_match('/^[0-9a-f]{64}$/D', $expectedProofFingerprint)
            || ($decision === AssessmentBillManualDecision::Approve) !== ($rejectionCode === null)) {
            throw new InvalidArgumentException('Invalid assessment bill manual review.');
        }
    }
}
