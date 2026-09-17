<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Data\Integrations\CheckoutPaymentFacts;
use App\Data\Integrations\CheckoutProductPaymentFacts;
use App\Enums\PayerType;
use App\Models\AssessmentBill;
use App\Models\AssessmentBillItem;
use App\Models\AssessmentCharge;
use App\Models\AssessmentParticipant;
use App\Models\PackageItem;
use App\Models\TestPackage;
use App\Security\RlsContextRunner;
use App\Services\Payments\AssessmentPriceSnapshot;
use App\Services\Payments\AssessmentSettlementReader;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Internal projection for the lifecycle's freshly validated graph. Caller holds the
 * canonical organization mutex throughout, as reservation/finalization/issuance do.
 * No bill locks after attempt/session locks, policy re-selection, RLS elevation or writes.
 * A supplied model is not an authorization proof; only the credential lifecycle is an entrypoint.
 */
final readonly class CheckoutPaymentFactsReader
{
    public function __construct(
        private RlsContextRunner $contexts,
        private AssessmentPriceSnapshot $prices,
        private AssessmentSettlementReader $settlement,
    ) {}

    public function project(AssessmentParticipant $attempt): CheckoutPaymentFacts
    {
        return $this->read($attempt, null)['payment'];
    }

    /**
     * Current evidence at a shared instant, not a historical database snapshot.
     * Only absent-charge product projection consumes the validated preloaded package.items graph.
     */
    public function projectAt(AssessmentParticipant $attempt, CarbonImmutable $asOf): CheckoutProductPaymentFacts
    {
        $facts = $this->read($attempt, $asOf);
        foreach (['id', 'organization_id', 'participant_id', 'package_id'] as $key) {
            $value = $attempt->getAttribute($key);
            if (! is_int($value) || $value <= 0 || $attempt->isDirty($key)) {
                throw new DomainException('CHECKOUT_PAYMENT_UNAVAILABLE');
            }
        }
        if ($facts['snapshot'] !== null) {
            return new CheckoutProductPaymentFacts($facts['snapshot']['packageName'], 'charge_snapshot',
                $facts['snapshot']['testTypes'], $facts['payment']);
        }
        $package = $attempt->relationLoaded('package') ? $attempt->getRelation('package') : null;
        if (! $package instanceof TestPackage || ! $package->exists || $package->id !== $attempt->package_id
            || $package->isDirty(['id', 'name']) || ! is_string($package->getAttribute('name')) || ! $package->relationLoaded('items')) {
            throw new DomainException('CHECKOUT_PAYMENT_UNAVAILABLE');
        }
        $items = $package->getRelation('items');
        if (! $items instanceof Collection || $items->isEmpty()) {
            throw new DomainException('CHECKOUT_PAYMENT_UNAVAILABLE');
        }
        $types = [];
        foreach ($items as $item) {
            if (! $item instanceof PackageItem || ! $item->exists || $item->package_id !== $package->id
                || ! is_int($item->getKey()) || $item->getKey() <= 0
                || $item->isDirty(['id', 'package_id', 'test_type']) || ! is_string($item->getAttribute('test_type'))) {
                throw new DomainException('CHECKOUT_PAYMENT_UNAVAILABLE');
            }
            $types[] = $item->test_type;
        }
        sort($types);

        return new CheckoutProductPaymentFacts($package->name, 'catalog', $types, $facts['payment']);
    }

    /** @return array{payment: CheckoutPaymentFacts, snapshot: array<string, mixed>|null} */
    private function read(AssessmentParticipant $attempt, ?CarbonImmutable $asOf): array
    {
        if ($this->contexts->current()?->role !== 'service' || DB::transactionLevel() === 0) {
            throw new LogicException('Checkout payment projection requires its validated service transaction.');
        }
        $metadata = $attempt->metadata;
        if (! $attempt->exists || ! is_array($metadata)
            || ($metadata['checkout_contract_version'] ?? null) !== 'checkout-v2'
            || ! array_key_exists('checkout_initial_funding_mode', $metadata)
            || ! in_array($attempt->funding_mode, [null, 'COMMERCIAL_SELF_PAY', 'INVOICED_TO_ORGANIZATION'], true)
            || ! in_array($metadata['checkout_initial_funding_mode'], [null, $attempt->funding_mode], true)) {
            throw new DomainException('CHECKOUT_PAYMENT_UNAVAILABLE');
        }
        $payer = match ($attempt->funding_mode) {
            'COMMERCIAL_SELF_PAY' => PayerType::SelfPay,
            'INVOICED_TO_ORGANIZATION' => PayerType::Organization,
            default => null,
        };
        $unsettled = match ($payer) {
            PayerType::SelfPay => 'unpaid', PayerType::Organization => 'unbilled', null => 'unselected',
        };
        $charges = AssessmentCharge::query()->where('assessment_participant_id', $attempt->id)->limit(2)->get();
        if ($charges->isEmpty()) {
            return ['payment' => new CheckoutPaymentFacts($payer, $unsettled, null, null), 'snapshot' => null];
        }
        $charge = $charges->first();
        if ($charges->count() !== 1 || $payer === null
            || $charge->assessment_participant_id !== $attempt->id || $charge->organization_id !== $attempt->organization_id
            || $charge->participant_id !== $attempt->participant_id || $charge->package_id !== $attempt->package_id
            || $charge->payer_type !== $payer->value) {
            throw new DomainException('CHECKOUT_PAYMENT_UNAVAILABLE');
        }
        try {
            $snapshot = $this->prices->fromCharge($charge, $charge->consultation_requested);
        } catch (DomainException) {
            throw new DomainException('CHECKOUT_PAYMENT_UNAVAILABLE');
        }
        if ($charge->free_settled_at !== null && ($charge->amount !== 0 || $charge->free_settled_at->gt($asOf ?? now()))) {
            throw new DomainException('CHECKOUT_PAYMENT_UNAVAILABLE');
        }
        $items = AssessmentBillItem::query()->where('charge_id', $charge->id)->limit(2)->get();
        if ($charge->amount === 0) {
            if ($items->isNotEmpty()) {
                throw new DomainException('CHECKOUT_PAYMENT_UNAVAILABLE');
            }
            $state = ($asOf === null ? $this->settlement->isSettled($charge) : $this->settlement->isSettledAt($charge, $asOf)) ? 'free' : $unsettled;
        } elseif ($items->isEmpty()) {
            $state = $unsettled;
        } else {
            $item = $items->first();
            if ($items->count() !== 1) {
                throw new DomainException('CHECKOUT_PAYMENT_UNAVAILABLE');
            }
            $bill = AssessmentBill::query()->find($item->bill_id);
            $payerParticipant = $payer === PayerType::SelfPay ? $attempt->participant_id : null;
            if ($bill === null || $item->charge_id !== $charge->id || $item->organization_id !== $attempt->organization_id
                || $item->participant_id !== $attempt->participant_id || $item->payer_type !== $payer->value
                || $item->payer_participant_id !== $payerParticipant || $item->amount !== $charge->amount
                || $item->currency !== $charge->currency || $bill->organization_id !== $attempt->organization_id
                || $bill->payer_type !== $payer->value || $bill->payer_participant_id !== $payerParticipant
                || $bill->currency !== $charge->currency) {
                throw new DomainException('CHECKOUT_PAYMENT_UNAVAILABLE');
            }
            if ($bill->status === 'paid') {
                if (! ($asOf === null ? $this->settlement->isSettled($charge) : $this->settlement->isSettledAt($charge, $asOf))) {
                    throw new DomainException('CHECKOUT_PAYMENT_UNAVAILABLE');
                }
                $state = 'paid';
            } else {
                if ($bill->paid_at !== null || $item->settled_at !== null) {
                    throw new DomainException('CHECKOUT_PAYMENT_UNAVAILABLE');
                }
                $state = match ($bill->status) {
                    'reserved', 'issuing' => 'preparing',
                    'pending' => 'pending', 'unknown' => 'recovery_required',
                    'expired' => 'expired', 'rejected' => 'rejected',
                    default => throw new DomainException('CHECKOUT_PAYMENT_UNAVAILABLE'),
                };
            }
        }

        return ['payment' => new CheckoutPaymentFacts($payer, $state, $charge->amount, $charge->consultation_requested), 'snapshot' => $snapshot];
    }
}
