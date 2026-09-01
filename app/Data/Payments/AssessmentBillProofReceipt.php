<?php

declare(strict_types=1);

namespace App\Data\Payments;

final readonly class AssessmentBillProofReceipt
{
    public function __construct(public bool $replaced, public string $proofFingerprint) {}
}
