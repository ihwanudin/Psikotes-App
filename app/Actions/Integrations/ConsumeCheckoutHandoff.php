<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Data\Integrations\CheckoutHandoffConsumeInput;
use App\Data\Integrations\CheckoutSessionScope;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Integrations\ConsumeCheckoutHandoffTransaction;
use Illuminate\Support\Facades\DB;
use LogicException;
use SensitiveParameter;

/** Internal P13b boundary. Consuming a handoff grants only a checkout-session scope. */
final readonly class ConsumeCheckoutHandoff
{
    public function __construct(
        private RlsContextRunner $contexts,
        private ConsumeCheckoutHandoffTransaction $transaction,
    ) {}

    public function execute(#[SensitiveParameter] CheckoutHandoffConsumeInput $input): CheckoutSessionScope
    {
        if ($this->contexts->current() !== null || DB::transactionLevel() !== 0) {
            throw new LogicException('Checkout handoff consumption owns its service transaction.');
        }

        /** @var CheckoutSessionScope|null $scope */
        $scope = $this->contexts->run(
            new RlsContext('service'),
            fn (): ?CheckoutSessionScope => $this->transaction->execute($input->rawToken()),
        );
        if ($scope === null) {
            throw new InvalidCheckoutHandoff;
        }

        return $scope;
    }
}
