<?php

declare(strict_types=1);

namespace App\Services\Commissions;

use App\Data\Payments\PaymentEvent;
use App\Enums\PaymentStatus;
use App\Security\RlsContextRunner;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class RecordBranchCommissionLedger
{
    public function __construct(private RlsContextRunner $contexts) {}

    public function recordForPaymentEvent(PaymentEvent $event): void
    {
        if ($event->status !== PaymentStatus::Paid) {
            return;
        }

        if (str_starts_with($event->merchantReference, 'AB_')) {
            $this->recordAssessmentBillReference($event->merchantReference);

            return;
        }

        $this->recordDirectOrderGatewayReference($event->providerReference);
    }

    public function recordDirectOrder(int $orderId): void
    {
        $this->bestEffort(function () use ($orderId): void {
            $this->contexts->runAsService(function () use ($orderId): void {
                DB::transaction(fn (): mixed => $this->recordDirectOrderInService($orderId));
            });
        });
    }

    public function recordAssessmentBillReference(string $billReference): void
    {
        $this->bestEffort(function () use ($billReference): void {
            $this->contexts->runAsService(function () use ($billReference): void {
                DB::transaction(fn (): mixed => $this->recordAssessmentBillInService($billReference));
            });
        });
    }

    private function recordDirectOrderGatewayReference(string $gatewayReference): void
    {
        $this->bestEffort(function () use ($gatewayReference): void {
            $this->contexts->runAsService(function () use ($gatewayReference): void {
                DB::transaction(function () use ($gatewayReference): void {
                    $orderId = DB::table('orders')->where('gateway_ref', $gatewayReference)->value('id');
                    if (is_int($orderId)) {
                        $this->recordDirectOrderInService($orderId);
                    }
                });
            });
        });
    }

    private function recordDirectOrderInService(int $orderId): void
    {
        $order = DB::table('orders as orders')
            ->join('participants as participant', 'participant.id', '=', 'orders.participant_id')
            ->leftJoin('packages as package', 'package.id', '=', 'participant.package_id')
            ->where('orders.id', $orderId)
            ->lockForUpdate()
            ->first([
                'orders.id',
                'orders.status',
                'orders.paid_at',
                'orders.amount',
                'orders.currency',
                'participant.id as participant_id',
                'participant.referral_branch_id as branch_id',
                'participant.package_id',
                'package.amount as package_amount',
            ]);

        if ($order === null || $order->status !== 'paid' || $order->paid_at === null) {
            return;
        }

        $paidAt = CarbonImmutable::parse((string) $order->paid_at)->utc();
        $source = ['type' => 'direct_order', 'id' => (int) $order->id];
        $basis = [
            'base_amount' => $order->package_amount,
            'paid_amount' => $order->amount,
            'net_amount' => $order->amount,
        ];

        $this->recordSource(
            branchId: (int) $order->branch_id,
            participantId: (int) $order->participant_id,
            assessmentBillId: null,
            assessmentBillItemId: null,
            orderId: (int) $order->id,
            source: $source,
            paidAt: $paidAt,
            grossAmount: (int) $order->amount,
            currency: (string) $order->currency,
            basisAmounts: $basis,
        );
    }

    private function recordAssessmentBillInService(string $billReference): void
    {
        $bill = DB::table('assessment_bills')
            ->where('public_reference', $billReference)
            ->where('status', 'paid')
            ->whereNotNull('paid_at')
            ->lockForUpdate()
            ->first(['id', 'organization_id', 'paid_at', 'currency']);

        if ($bill === null) {
            return;
        }

        $items = DB::table('assessment_bill_items as item')
            ->join('assessment_charges as charge', 'charge.id', '=', 'item.charge_id')
            ->join('participants as participant', 'participant.id', '=', 'item.participant_id')
            ->where('item.bill_id', $bill->id)
            ->whereNotNull('item.settled_at')
            ->orderBy('item.id')
            ->lockForUpdate()
            ->get([
                'item.id',
                'item.bill_id',
                'item.participant_id',
                'participant.referral_branch_id as branch_id',
                'item.amount',
                'item.currency',
                'item.settled_at',
                'charge.base_amount',
            ]);

        foreach ($items as $item) {
            $paidAt = CarbonImmutable::parse((string) $item->settled_at)->utc();
            $this->recordSource(
                branchId: (int) $item->branch_id,
                participantId: (int) $item->participant_id,
                assessmentBillId: (int) $item->bill_id,
                assessmentBillItemId: (int) $item->id,
                orderId: null,
                source: ['type' => 'assessment_bill_item', 'id' => (int) $item->id],
                paidAt: $paidAt,
                grossAmount: (int) $item->amount,
                currency: (string) $item->currency,
                basisAmounts: [
                    'base_amount' => $item->base_amount,
                    'paid_amount' => $item->amount,
                    'net_amount' => $item->amount,
                ],
            );
        }
    }

    /**
     * @param  array{type:string,id:int}  $source
     * @param  array<string,mixed>  $basisAmounts
     */
    private function recordSource(
        int $branchId,
        ?int $participantId,
        ?int $assessmentBillId,
        ?int $assessmentBillItemId,
        ?int $orderId,
        array $source,
        CarbonInterface $paidAt,
        int $grossAmount,
        string $currency,
        array $basisAmounts,
    ): void {
        if (DB::table('commission_entries')
            ->where('source_type', $source['type'])
            ->where('source_id', $source['id'])
            ->exists()) {
            $this->resolveGap($source);

            return;
        }

        $rule = $this->feeRule($branchId, $paidAt, $source, $grossAmount);
        if ($rule === null) {
            return;
        }

        $basis = $basisAmounts[$rule->rate_basis] ?? null;
        if (! is_int($basis) || $basis < 0) {
            $this->gap($branchId, $source, 'basis_unavailable', $paidAt, $grossAmount, [
                'rateBasis' => $rule->rate_basis,
            ]);

            return;
        }

        $commission = $this->calculateCommission($rule, $basis);
        if ($commission === null) {
            $this->gap($branchId, $source, 'calculation_error', $paidAt, $grossAmount, [
                'rateType' => $rule->rate_type,
            ]);

            return;
        }

        DB::table('commission_entries')->insertOrIgnore([
            'branch_id' => $branchId,
            'participant_id' => $participantId,
            'assessment_bill_id' => $assessmentBillId,
            'assessment_bill_item_id' => $assessmentBillItemId,
            'order_id' => $orderId,
            'source_type' => $source['type'],
            'source_id' => $source['id'],
            'period_month' => CarbonImmutable::instance($paidAt)->setTimezone('Asia/Jakarta')->startOfMonth()->toDateString(),
            'paid_at' => CarbonImmutable::instance($paidAt)->utc(),
            'fee_rule_id' => (int) $rule->id,
            'rate_basis' => $rule->rate_basis,
            'rate_basis_amount' => $basis,
            'gross_amount' => $grossAmount,
            'commission_amount' => $commission,
            'currency' => $currency,
            'calculation_snapshot' => json_encode([
                'version' => 1,
                'timezone' => 'Asia/Jakarta',
                'roundingMode' => 'floor',
                'rateType' => $rule->rate_type,
                'percentageBps' => $rule->percentage_bps,
                'fixedAmount' => $rule->fixed_amount,
            ], JSON_THROW_ON_ERROR),
            'status' => 'accrued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->resolveGap($source);
    }

    /**
     * @param  array{type:string,id:int}  $source
     */
    private function feeRule(int $branchId, CarbonInterface $paidAt, array $source, int $amount): ?object
    {
        $rules = DB::table('branch_fee_rules')
            ->where('branch_id', $branchId)
            ->where('effective_from', '<=', CarbonImmutable::instance($paidAt)->utc())
            ->where(function ($query) use ($paidAt): void {
                $query->whereNull('effective_until')
                    ->orWhere('effective_until', '>', CarbonImmutable::instance($paidAt)->utc());
            })
            ->orderByDesc('effective_from')
            ->limit(2)
            ->get();

        if ($rules->count() === 0) {
            $this->gap($branchId, $source, 'fee_rule_missing', $paidAt, $amount);

            return null;
        }
        if ($rules->count() > 1) {
            $this->gap($branchId, $source, 'fee_rule_ambiguous', $paidAt, $amount);

            return null;
        }

        return $rules->first();
    }

    private function calculateCommission(object $rule, int $basis): ?int
    {
        if ($rule->rate_type === 'percentage' && is_numeric($rule->percentage_bps)) {
            return intdiv($basis * (int) $rule->percentage_bps, 10_000);
        }

        if ($rule->rate_type === 'fixed' && is_numeric($rule->fixed_amount)) {
            return (int) $rule->fixed_amount;
        }

        return null;
    }

    /**
     * @param  array{type:string,id:int}  $source
     * @param  array<string,mixed>  $context
     */
    private function gap(int $branchId, array $source, string $reason, CarbonInterface $paidAt, int $amount, array $context = []): void
    {
        DB::table('commission_ledger_gaps')->updateOrInsert(
            ['source_type' => $source['type'], 'source_id' => $source['id']],
            [
                'branch_id' => $branchId,
                'reason_code' => $reason,
                'paid_at' => CarbonImmutable::instance($paidAt)->utc(),
                'currency' => 'IDR',
                'amount' => $amount,
                'context' => json_encode(['version' => 1, ...$context], JSON_THROW_ON_ERROR),
                'status' => 'open',
                'resolved_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    /** @param array{type:string,id:int} $source */
    private function resolveGap(array $source): void
    {
        DB::table('commission_ledger_gaps')
            ->where('source_type', $source['type'])
            ->where('source_id', $source['id'])
            ->where('status', 'open')
            ->update(['status' => 'resolved', 'resolved_at' => now(), 'updated_at' => now()]);
    }

    private function bestEffort(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            Log::warning('commission_ledger_recording_failed', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
