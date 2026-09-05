<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Actions\Payments\IssueAssessmentBillInvoice;
use App\Data\Integrations\CheckoutSelfPaymentIssuanceResult;
use App\Data\Integrations\CheckoutSessionMutationCredentials;
use App\Security\RlsContextRunner;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

/** Internal orchestration only. Provider authority and persistence remain in the canonical P10 action. */
final readonly class IssueCheckoutSelfPayment
{
    public function __construct(
        private CoordinateCheckoutSelfPayment $claims,
        private IssueAssessmentBillInvoice $issuance,
        private RlsContextRunner $contexts,
    ) {}

    public function execute(
        #[SensitiveParameter] CheckoutSessionMutationCredentials $credentials,
        bool $consultationRequested,
    ): CheckoutSelfPaymentIssuanceResult {
        $this->assertOutsideTransaction();

        try {
            $claim = $this->claims->execute($credentials, $consultationRequested);
            $this->assertOutsideTransaction();
            if (in_array($claim->state, ['pending', 'paid'], true)) {
                return new CheckoutSelfPaymentIssuanceResult($claim->state);
            }
            if ($claim->state !== 'issuance_required' || $claim->messageId === null) {
                throw new DomainException('CHECKOUT_PAYMENT_ISSUANCE_INVALID');
            }
            $issued = $this->issuance->execute($claim->messageId);
            $this->assertOutsideTransaction();
            if ($issued['decision'] !== 'issued') {
                throw new DomainException('CHECKOUT_PAYMENT_ISSUANCE_INVALID');
            }

            return new CheckoutSelfPaymentIssuanceResult('pending');
        } catch (DomainException|InvalidArgumentException|LogicException) {
            throw new DomainException('CHECKOUT_PAYMENT_UNAVAILABLE');
        }
    }

    private function assertOutsideTransaction(): void
    {
        if ($this->contexts->current() !== null || DB::transactionLevel() !== 0) {
            throw new LogicException('Checkout payment issuance owns an empty context and transaction.');
        }
    }
}
