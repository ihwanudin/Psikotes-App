<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Data\Integrations\CheckoutSelfPaymentIssuanceResult;
use App\Data\Integrations\CheckoutSessionMutationCredentials;
use App\Security\RlsContextRunner;
use DomainException;
use Illuminate\Support\Facades\DB;
use LogicException;
use SensitiveParameter;

/** Credential-bound dispatcher for positive self payment and the exact zero-price exception. */
final readonly class ExecuteCheckoutPayment
{
    public function __construct(
        private IssueCheckoutSelfPayment $selfPayment,
        private SettleZeroPriceCheckout $zeroPrice,
        private RlsContextRunner $contexts,
    ) {}

    public function execute(
        #[SensitiveParameter] CheckoutSessionMutationCredentials $credentials,
        bool $consultationRequested,
    ): CheckoutSelfPaymentIssuanceResult {
        $this->assertOutsideTransaction();

        try {
            $result = $this->selfPayment->execute($credentials, $consultationRequested);
            $this->assertOutsideTransaction();

            return $result;
        } catch (DomainException) {
            $this->assertOutsideTransaction();
        }

        try {
            $zero = $this->zeroPrice->execute($credentials, $consultationRequested);
        } catch (DomainException) {
            $this->assertOutsideTransaction();

            throw new DomainException('CHECKOUT_PAYMENT_UNAVAILABLE');
        }
        $this->assertOutsideTransaction();
        if ($zero->state !== 'settled') {
            throw new DomainException('CHECKOUT_PAYMENT_UNAVAILABLE');
        }

        return new CheckoutSelfPaymentIssuanceResult('paid', null);
    }

    private function assertOutsideTransaction(): void
    {
        if ($this->contexts->current() !== null || DB::transactionLevel() !== 0) {
            throw new LogicException('Checkout payment command owns an empty context and transaction.');
        }
    }
}
