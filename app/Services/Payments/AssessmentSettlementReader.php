<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\AssessmentCharge;
use App\Security\RlsContextRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Internal settlement evidence only, never access entitlement or scope authorization.
 * Caller supplies a validated persisted charge in its authorized service transaction,
 * with its existing locks and snapshot checks; never accept request-selected IDs here.
 * This reader adds no locks, writes, policy checks, or RLS context elevation.
 */
final readonly class AssessmentSettlementReader
{
    public function __construct(private RlsContextRunner $contexts) {}

    public function isSettled(AssessmentCharge $charge): bool
    {
        $this->assertServiceContext();

        return $this->isSettledAt($charge, CarbonImmutable::instance(now()));
    }

    /** Current evidence evaluated at one instant, not a reconstruction of historical statuses. */
    public function isSettledAt(AssessmentCharge $charge, CarbonImmutable $asOf): bool
    {
        $this->assertServiceContext();
        if ($charge->amount === 0) {
            return $charge->free_settled_at !== null && $charge->free_settled_at->lte($asOf)
                && ! DB::table('assessment_bill_items')->where('charge_id', $charge->id)->exists();
        }
        $item = DB::table('assessment_bill_items as item')->join('assessment_bills as bill', 'bill.id', '=', 'item.bill_id')
            ->where('item.charge_id', $charge->id)->where('item.organization_id', $charge->organization_id)
            ->where('item.participant_id', $charge->participant_id)->where('item.payer_type', $charge->payer_type)
            ->where('item.amount', $charge->amount)->where('item.currency', $charge->currency)
            ->whereNotNull('item.settled_at')->where('item.settled_at', '<=', $asOf)
            ->where('bill.organization_id', $charge->organization_id)->where('bill.payer_type', $charge->payer_type)
            ->where('bill.currency', $charge->currency)->where('bill.status', 'paid')
            ->whereNotNull('bill.paid_at')->where('bill.paid_at', '<=', $asOf)
            ->where('bill.payer_participant_id', $charge->payer_type === 'self' ? $charge->participant_id : null)
            ->where('item.payer_participant_id', $charge->payer_type === 'self' ? $charge->participant_id : null)
            ->first(['bill.id', 'bill.amount', 'bill.item_count']);
        if ($item === null) {
            return false;
        }
        $members = DB::table('assessment_bill_items')->where('bill_id', $item->id)->get(['amount', 'settled_at']);
        $total = 0;
        foreach ($members as $member) {
            $amount = (int) $member->amount;
            if ($amount <= 0 || $total > PHP_INT_MAX - $amount || $member->settled_at === null
                || CarbonImmutable::parse($member->settled_at)->gt($asOf)) {
                return false;
            }
            $total += $amount;
        }

        return count($members) === (int) $item->item_count && $total === (int) $item->amount;
    }

    private function assertServiceContext(): void
    {
        if ($this->contexts->current()?->role !== 'service') {
            throw new LogicException('Settlement reader requires service RLS context.');
        }
    }
}
