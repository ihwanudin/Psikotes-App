<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Actions\Payments\ClaimAssessmentBillInvoice;
use App\Data\Integrations\CheckoutSelfPaymentClaimResult;
use App\Data\Integrations\CheckoutSessionMutationCredentials;
use App\Security\RlsContextRunner;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

/** Coordinates committed preparation and canonical claim; never consumes a permit or calls a provider. */
final readonly class CoordinateCheckoutSelfPayment
{
    public function __construct(
        private PrepareCheckoutSelfPayment $preparations,
        private RlsContextRunner $contexts,
        private ClaimAssessmentBillInvoice $claims,
    ) {}

    public function execute(
        #[SensitiveParameter] CheckoutSessionMutationCredentials $credentials,
        bool $consultationRequested,
    ): CheckoutSelfPaymentClaimResult {
        $this->assertOutsideTransaction();

        try {
            $preparation = $this->preparations->execute($credentials, $consultationRequested);
            $this->assertOutsideTransaction();
            if (in_array($preparation->status, ['pending', 'paid'], true)) {
                return new CheckoutSelfPaymentClaimResult($preparation->status, null);
            }
            if (! in_array($preparation->status, ['reserved', 'issuing'], true)) {
                throw new DomainException('CHECKOUT_PAYMENT_STATE_INVALID');
            }
            $claim = $this->contexts->runAsService(
                fn (): array => $this->claims->execute($preparation->organizationId, $preparation->billId),
            );
            $this->assertOutsideTransaction();
            $decision = $claim['decision'];
            $messageId = $claim['messageId'];
            if (! in_array($decision, ['claimed', 'replayed'], true)
                || ! is_string($messageId) || ! Str::isUlid($messageId)) {
                throw new DomainException('CHECKOUT_PAYMENT_CLAIM_INVALID');
            }

            return new CheckoutSelfPaymentClaimResult('issuance_required', $messageId);
        } catch (DomainException|InvalidArgumentException|LogicException) {
            throw new DomainException('CHECKOUT_PAYMENT_UNAVAILABLE');
        }
    }

    private function assertOutsideTransaction(): void
    {
        if ($this->contexts->current() !== null || DB::transactionLevel() !== 0) {
            throw new LogicException('Checkout payment claim owns an empty context and transaction.');
        }
    }
}
