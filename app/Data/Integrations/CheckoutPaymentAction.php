<?php

declare(strict_types=1);

namespace App\Data\Integrations;

use DomainException;
use JsonSerializable;

/** A narrow browser capability, never payment, settlement, or resource authority. */
final readonly class CheckoutPaymentAction implements JsonSerializable
{
    public string $path;

    public string $currency;

    /**
     * @var list<array{consultationRequested:bool,baseAmountIdr:int,consultationAmountIdr:int,amountIdr:int}>
     */
    public array $choices;

    /** @param array<mixed> $choices */
    public function __construct(public string $mode, array $choices)
    {
        if (! in_array($mode, ['select', 'continue'], true) || $choices === [] || ! array_is_list($choices)
            || ($mode === 'continue' && count($choices) !== 1)) {
            throw new DomainException('CHECKOUT_PAYMENT_ACTION_INVALID');
        }
        $seen = [];
        $validated = [];
        foreach ($choices as $choice) {
            if (! is_array($choice)
                || array_keys($choice) !== ['consultationRequested', 'baseAmountIdr', 'consultationAmountIdr', 'amountIdr']
                || ! is_bool($choice['consultationRequested']) || ! is_int($choice['baseAmountIdr'])
                || ! is_int($choice['consultationAmountIdr']) || ! is_int($choice['amountIdr'])) {
                throw new DomainException('CHECKOUT_PAYMENT_ACTION_INVALID');
            }
            foreach (['baseAmountIdr', 'consultationAmountIdr', 'amountIdr'] as $field) {
                if ($choice[$field] < 0 || $choice[$field] > 9_007_199_254_740_991) {
                    throw new DomainException('CHECKOUT_PAYMENT_ACTION_INVALID');
                }
            }
            $key = $choice['consultationRequested'] ? 1 : 0;
            if (isset($seen[$key]) || ($seen !== [] && $key !== 1)
                || $choice['baseAmountIdr'] > 9_007_199_254_740_991 - $choice['consultationAmountIdr']
                || $choice['amountIdr'] !== $choice['baseAmountIdr'] + $choice['consultationAmountIdr']
                || ($choice['consultationRequested'] ? $choice['consultationAmountIdr'] <= 0
                    : $choice['consultationAmountIdr'] !== 0)) {
                throw new DomainException('CHECKOUT_PAYMENT_ACTION_INVALID');
            }
            $seen[$key] = true;
            $validated[] = $choice;
        }
        $this->path = '/checkout/payment';
        $this->currency = 'IDR';
        $this->choices = $validated;
    }

    /** @return array{path:'/checkout/payment',mode:string,currency:'IDR',choices:list<array{consultationRequested:bool,baseAmountIdr:int,consultationAmountIdr:int,amountIdr:int}>} */
    public function toArray(): array
    {
        return ['path' => $this->path, 'mode' => $this->mode, 'currency' => $this->currency,
            'choices' => $this->choices];
    }

    /** @return array{path:'/checkout/payment',mode:string,currency:'IDR',choices:list<array{consultationRequested:bool,baseAmountIdr:int,consultationAmountIdr:int,amountIdr:int}>} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
