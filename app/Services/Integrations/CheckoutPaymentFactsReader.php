<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Data\Integrations\CheckoutPaymentFacts;
use App\Enums\PayerType;
use App\Models\AssessmentBill;
use App\Models\AssessmentBillItem;
use App\Models\AssessmentCharge;
use App\Models\AssessmentParticipant;
use App\Security\RlsContextRunner;
use App\Services\Payments\AssessmentPriceSnapshot;
use App\Services\Payments\AssessmentSettlementReader;
use DomainException;
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
            return new CheckoutPaymentFacts($payer, $unsettled, null, null);
        }
        $charge = $charges->first();
        if ($charges->count() !== 1 || $payer === null
            || $charge->assessment_participant_id !== $attempt->id || $charge->organization_id !== $attempt->organization_id
            || $charge->participant_id !== $attempt->participant_id || $charge->package_id !== $attempt->package_id
            || $charge->payer_type !== $payer->value) {
            throw new DomainException('CHECKOUT_PAYMENT_UNAVAILABLE');
        }
        try {
            $this->prices->fromCharge($charge, $charge->consultation_requested);
        } catch (DomainException) {
            throw new DomainException('CHECKOUT_PAYMENT_UNAVAILABLE');
        }
        if ($charge->free_settled_at !== null && ($charge->amount !== 0 || $charge->free_settled_at->gt(now()))) {
            throw new DomainException('CHECKOUT_PAYMENT_UNAVAILABLE');
        }
        $items = AssessmentBillItem::query()->where('charge_id', $charge->id)->limit(2)->get();
        if ($charge->amount === 0) {
            if ($items->isNotEmpty()) {
                throw new DomainException('CHECKOUT_PAYMENT_UNAVAILABLE');
            }
            $state = $this->settlement->isSettled($charge) ? 'free' : $unsettled;
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
                if (! $this->settlement->isSettled($charge)) {
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

        return new CheckoutPaymentFacts($payer, $state, $charge->amount, $charge->consultation_requested);
    }
}
