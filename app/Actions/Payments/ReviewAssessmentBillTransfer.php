<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Data\Payments\AssessmentBillManualReview;

/** Internal boundary only. HTTP, policy, proof access, and UI wiring remain outside P11c1b. */
final readonly class ReviewAssessmentBillTransfer
{
    public function __construct(private FinalizeAssessmentBill $finalizer) {}

    /** @return array{decision: string, allocationCount: int, activatedAttemptCount: int} */
    public function execute(AssessmentBillManualReview $review): array
    {
        return $this->finalizer->executeManual($review);
    }
}
