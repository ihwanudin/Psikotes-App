<?php

declare(strict_types=1);

namespace App\Data\Integrations;

use App\Models\AssessmentBill;
use App\Models\AssessmentBillItem;
use App\Models\AssessmentCharge;
use App\Models\AssessmentParticipant;
use App\Models\Branch;
use App\Models\IntegrationClient;
use App\Models\IntegrationSource;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\TestPackage;
use Carbon\CarbonImmutable;
use SensitiveParameter;

/** Freshly locked checkout graph; its models are evidence, never standalone authority. */
final readonly class CheckoutSummaryEvidence
{
    public function __construct(
        public CheckoutSessionPrincipal $principal,
        public Branch $organization,
        public IntegrationClient $client,
        public IntegrationSource $source,
        public TestPackage $package,
        public AssessmentParticipant $attempt,
        #[SensitiveParameter] public Participant $participant,
        public ?AssessmentCharge $charge,
        public ?AssessmentBillItem $billItem,
        public ?AssessmentBill $bill,
        public ?PaymentMethod $paymentMethod,
        public bool $paymentEvidenceCanonical,
        public CarbonImmutable $asOf,
    ) {}
}
