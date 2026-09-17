<?php

declare(strict_types=1);

namespace App\Data\Integrations;

use App\Enums\PayerType;
use DomainException;
use JsonSerializable;

/** Own-attempt payment facts only; never authority to purchase, settle, or start a test. */
final readonly class CheckoutPaymentFacts implements JsonSerializable
{
    public string $amountSource;

    public bool $actionAvailable;

    public function __construct(
        public ?PayerType $payer,
        public string $state,
        public ?int $amountIdr,
        public ?bool $consultationRequested,
    ) {
        if (! in_array($state, ['unselected', 'unpaid', 'unbilled', 'preparing', 'pending',
            'recovery_required', 'expired', 'rejected', 'paid', 'free'], true)
            || ($amountIdr !== null && ($amountIdr < 0 || $amountIdr > 9007199254740991))
            || (($amountIdr === null) !== ($consultationRequested === null))) {
            throw new DomainException('CHECKOUT_PAYMENT_UNAVAILABLE');
        }
        $this->amountSource = $amountIdr === null ? 'unavailable' : 'charge_snapshot';
        $this->actionAvailable = false;
    }

    /** @return array{payment: array{payer: string, state: string, amountIdr: int|null, amountSource: string, consultationRequested: bool|null, actionAvailable: false}} */
    public function toArray(): array
    {
        return ['payment' => ['payer' => $this->payer->value ?? 'unselected', 'state' => $this->state,
            'amountIdr' => $this->amountIdr, 'amountSource' => $this->amountSource,
            'consultationRequested' => $this->consultationRequested, 'actionAvailable' => false]];
    }

    /** @return array{payment: array{payer: string, state: string, amountIdr: int|null, amountSource: string, consultationRequested: bool|null, actionAvailable: false}} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
