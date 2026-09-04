<?php

declare(strict_types=1);

namespace App\Data\Integrations;

use DomainException;
use JsonSerializable;

/** Internal own-product facts; neither a quote nor authority to pay or start. */
final readonly class CheckoutProductPaymentFacts implements JsonSerializable
{
    /** @param list<string> $testTypes Canonical sorted unique instrument types. */
    public function __construct(
        public string $packageLabel,
        public string $source,
        public array $testTypes,
        public CheckoutPaymentFacts $payment,
    ) {
        foreach ($testTypes as $type) {
            if (! in_array($type, ['ist', 'papi', 'rmib', 'kraepelin', 'dass21'], true)) {
                throw new DomainException('CHECKOUT_PAYMENT_UNAVAILABLE');
            }
        }
        $sorted = $testTypes;
        sort($sorted);
        if (trim($packageLabel) === '' || ! in_array($source, ['charge_snapshot', 'catalog'], true)
            || $testTypes === [] || $sorted !== $testTypes
            || count(array_unique($testTypes)) !== count($testTypes)
            || ($source === 'catalog') !== ($payment->amountIdr === null)) {
            throw new DomainException('CHECKOUT_PAYMENT_UNAVAILABLE');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['product' => ['packageLabel' => $this->packageLabel, 'source' => $this->source, 'testTypes' => $this->testTypes],
            ...$this->payment->toArray()];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
