<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Data\Payments\PaymentEvent;
use App\Enums\PaymentStatus;
use App\Models\AssessmentBill;
use App\Models\AssessmentBillItem;
use App\Models\AssessmentCharge;
use App\Models\AssessmentParticipant;
use App\Models\Branch;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\TestPackage;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\ParticipantAuth\AssessmentPrincipal;
use App\Services\Payments\AssessmentPriceSnapshot;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Internal settlement boundary. Authentication and webhook routing remain outside P11a1. */
final readonly class FinalizeAssessmentBill
{
    public function __construct(
        private RlsContextRunner $contexts,
        private AssessmentPriceSnapshot $prices,
        private ActivateSettledAssessment $activation,
    ) {}

    /** @return array{decision: string, allocationCount: int, activatedAttemptCount: int} */
    public function execute(PaymentEvent $event): array
    {
        if (! preg_match('/^AB_[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $event->merchantReference)) {
            throw new DomainException('ASSESSMENT_PAYMENT_REFERENCE_INVALID');
        }
        $context = $this->contexts->current();
        if ($context !== null) {
            if ($context->role !== 'service') {
                throw new LogicException('Assessment bill finalization requires service authority.');
            }

            return DB::transaction(fn (): array => $this->finalize($event));
        }
        if (DB::connection()->transactionLevel() !== 0) {
            throw new LogicException('Assessment bill finalization cannot elevate an ambient transaction.');
        }

        return $this->contexts->run(new RlsContext('service'), fn (): array => $this->finalize($event));
    }

    /** @return array{decision: string, allocationCount: int, activatedAttemptCount: int} */
    private function finalize(PaymentEvent $event): array
    {
        // Routing is an unlocked hint under service RLS. Authority starts after the organization mutex.
        $organizationId = AssessmentBill::query()->where('public_reference', $event->merchantReference)->value('organization_id');
        if (! is_int($organizationId) || Branch::query()->lockForUpdate()->find($organizationId) === null) {
            throw new DomainException('ASSESSMENT_PAYMENT_REFERENCE_MISMATCH');
        }

        $bill = AssessmentBill::query()->where('organization_id', $organizationId)
            ->where('public_reference', $event->merchantReference)->lockForUpdate()->first();
        if ($bill === null) {
            throw new DomainException('ASSESSMENT_PAYMENT_REFERENCE_MISMATCH');
        }
        $items = AssessmentBillItem::query()->where('bill_id', $bill->id)->orderBy('id')->lockForUpdate()->get();
        $chargeHints = AssessmentCharge::query()->whereIn('id', $items->pluck('charge_id'))->get(['id', 'assessment_participant_id']);
        $attempts = AssessmentParticipant::query()->where('organization_id', $organizationId)
            ->whereIn('id', $chargeHints->pluck('assessment_participant_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $participants = Participant::query()->where('branch_id', $organizationId)
            ->whereIn('id', $attempts->pluck('participant_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $packages = TestPackage::query()->whereIn('id', $attempts->pluck('package_id'))
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $charges = AssessmentCharge::query()->whereIn('id', $items->pluck('charge_id'))
            ->orderBy('assessment_participant_id')->lockForUpdate()->get()->keyBy('id');
        $method = PaymentMethod::query()->lockForUpdate()->find($bill->payment_method_id);

        $this->assertEvent($event, $bill);
        $this->assertAllocations($bill, $items, $attempts, $participants, $packages, $charges, $method);

        if ($bill->status === 'paid') {
            $this->assertPaidState($bill, $items);
            if ($event->status !== PaymentStatus::Paid) {
                return $this->result('ignored', $items->count(), 0);
            }
            $this->assertReplay($event, $bill, $items);

            return $this->result('replayed', $items->count(), 0);
        }
        if ($event->status !== PaymentStatus::Paid) {
            return $this->result('ignored', $items->count(), 0);
        }
        if ($bill->status !== 'pending' || $bill->paid_at !== null) {
            throw new DomainException('ASSESSMENT_PAYMENT_STATE_INVALID');
        }

        $paidAt = CarbonImmutable::instance($event->occurredAt)->utc()->startOfSecond();
        if ($paidAt->isFuture()) {
            throw new DomainException('ASSESSMENT_PAYMENT_TIME_INVALID');
        }
        $bill->update(['status' => 'paid', 'paid_at' => $paidAt]);
        foreach ($items as $item) {
            if ($item->settled_at !== null) {
                throw new DomainException('ASSESSMENT_PAYMENT_ALLOCATION_INVALID');
            }
            $item->settled_at = $paidAt;
            $item->save();
        }
        $this->audit($event, $bill, $items, $paidAt);

        $activated = 0;
        foreach ($attempts->sortKeys() as $attempt) {
            if ($this->activation->execute(new AssessmentPrincipal(
                $attempt->participant_id,
                $attempt->organization_id,
                $attempt->id,
            )) !== []) {
                $activated++;
            }
        }

        return $this->result('settled', $items->count(), $activated);
    }

    private function assertEvent(PaymentEvent $event, AssessmentBill $bill): void
    {
        if ($bill->public_reference !== $event->merchantReference || $bill->gateway_ref === null
            || ! hash_equals($bill->gateway_ref, $event->providerReference)) {
            throw new DomainException('ASSESSMENT_PAYMENT_REFERENCE_MISMATCH');
        }
        if ($bill->amount !== $event->amount || $bill->currency !== $event->currency) {
            throw new DomainException('ASSESSMENT_PAYMENT_MONEY_MISMATCH');
        }
    }

    /**
     * @param  Collection<int, AssessmentBillItem>  $items
     * @param  Collection<int, AssessmentParticipant>  $attempts
     * @param  Collection<int, Participant>  $participants
     * @param  Collection<int, TestPackage>  $packages
     * @param  Collection<int, AssessmentCharge>  $charges
     */
    private function assertAllocations(AssessmentBill $bill, Collection $items, Collection $attempts,
        Collection $participants, Collection $packages, Collection $charges, ?PaymentMethod $method): void
    {
        if ($method === null || $bill->amount < 1 || $bill->currency !== 'IDR' || $bill->item_count < 1
            || $items->count() !== $bill->item_count || ! in_array($bill->payer_type, ['self', 'organization'], true)
            || ($bill->payer_type === 'organization' ? $bill->payer_participant_id !== null
                : ($bill->payer_participant_id === null || $bill->item_count !== 1))) {
            throw new DomainException('ASSESSMENT_PAYMENT_SCOPE_INVALID');
        }

        $total = 0;
        foreach ($items as $item) {
            $charge = $charges->get($item->charge_id);
            $attempt = $charge === null ? null : $attempts->get($charge->assessment_participant_id);
            $participant = $attempt === null ? null : $participants->get($attempt->participant_id);
            $package = $attempt === null ? null : $packages->get($attempt->package_id);
            $funding = $bill->payer_type === 'self' ? 'COMMERCIAL_SELF_PAY' : 'INVOICED_TO_ORGANIZATION';
            $metadata = $attempt?->metadata;
            if ($charge === null || $attempt === null || $participant === null || $package === null
                || $item->bill_id !== $bill->id || $item->organization_id !== $bill->organization_id
                || $item->participant_id !== $participant->id || $item->payer_type !== $bill->payer_type
                || $item->payer_participant_id !== $bill->payer_participant_id || $item->amount !== $charge->amount
                || $item->currency !== $bill->currency || $charge->organization_id !== $bill->organization_id
                || $charge->participant_id !== $participant->id || $charge->package_id !== $package->id
                || $charge->payer_type !== $bill->payer_type || $charge->currency !== $bill->currency
                || $attempt->organization_id !== $bill->organization_id || $attempt->participant_id !== $participant->id
                || $attempt->package_id !== $package->id || $attempt->funding_mode !== $funding
                || ! is_array($metadata) || ($metadata['checkout_contract_version'] ?? null) !== 'checkout-v2'
                || ! array_key_exists('checkout_initial_funding_mode', $metadata)
                || ! in_array($metadata['checkout_initial_funding_mode'], [null, $funding], true)) {
                throw new DomainException('ASSESSMENT_PAYMENT_SCOPE_INVALID');
            }
            try {
                $this->prices->fromCharge($charge, $charge->consultation_requested);
            } catch (DomainException) {
                throw new DomainException('ASSESSMENT_PAYMENT_SNAPSHOT_INVALID');
            }
            if ($item->amount < 1 || $total > PHP_INT_MAX - $item->amount) {
                throw new DomainException('ASSESSMENT_PAYMENT_TOTAL_INVALID');
            }
            $total += $item->amount;
        }
        if ($total !== $bill->amount) {
            throw new DomainException('ASSESSMENT_PAYMENT_TOTAL_INVALID');
        }
    }

    /** @param Collection<int, AssessmentBillItem> $items */
    private function assertReplay(PaymentEvent $event, AssessmentBill $bill, Collection $items): void
    {
        $paidAt = CarbonImmutable::instance($event->occurredAt)->utc()->startOfSecond();
        if ($bill->paid_at === null || ! $bill->paid_at->equalTo($paidAt)) {
            throw new DomainException('ASSESSMENT_PAYMENT_REPLAY_MISMATCH');
        }
        $audit = DB::table('audit_logs')->where('action', 'assessment_bill.paid')
            ->where('subject_type', AssessmentBill::class)->where('subject_id', (string) $bill->id)->get();
        $expected = $this->auditContext($event, $items, $paidAt);
        if ($audit->count() !== 1 || json_decode((string) $audit->first()->context, true, 512, JSON_THROW_ON_ERROR) !== $expected) {
            throw new DomainException('ASSESSMENT_PAYMENT_REPLAY_MISMATCH');
        }
    }

    /** @param  Collection<int, AssessmentBillItem>  $items */
    private function assertPaidState(AssessmentBill $bill, Collection $items): void
    {
        if ($bill->paid_at === null || DB::table('audit_logs')->where('action', 'assessment_bill.paid')
            ->where('subject_type', AssessmentBill::class)->where('subject_id', (string) $bill->id)->count() !== 1) {
            throw new DomainException('ASSESSMENT_PAYMENT_REPLAY_MISMATCH');
        }
        foreach ($items as $item) {
            if ($item->settled_at === null || ! $item->settled_at->equalTo($bill->paid_at)) {
                throw new DomainException('ASSESSMENT_PAYMENT_REPLAY_MISMATCH');
            }
        }
    }

    /** @param Collection<int, AssessmentBillItem> $items */
    private function audit(PaymentEvent $event, AssessmentBill $bill, Collection $items, CarbonImmutable $paidAt): void
    {
        $now = now()->toImmutable();
        DB::table('audit_logs')->insert([
            'branch_id' => $bill->organization_id,
            'actor_type' => 'system',
            'actor_id' => null,
            'action' => 'assessment_bill.paid',
            'subject_type' => AssessmentBill::class,
            'subject_id' => (string) $bill->id,
            'context' => json_encode($this->auditContext($event, $items, $paidAt), JSON_THROW_ON_ERROR),
            'occurred_at' => $now,
            'expires_at' => $now->addYearsNoOverflow(2),
        ]);
    }

    /** @param Collection<int, AssessmentBillItem> $items
     * @return array<string, mixed>
     */
    private function auditContext(PaymentEvent $event, Collection $items, CarbonImmutable $paidAt): array
    {
        return [
            'version' => 1,
            'eventIdHash' => hash('sha256', $event->eventId),
            'providerReferenceHash' => hash('sha256', $event->providerReference),
            'amount' => $event->amount,
            'currency' => $event->currency,
            'paidAt' => $paidAt->format('Y-m-d\TH:i:s.u\Z'),
            'billItemIds' => $items->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(),
        ];
    }

    /** @return array{decision: string, allocationCount: int, activatedAttemptCount: int} */
    private function result(string $decision, int $allocations, int $activated): array
    {
        return ['decision' => $decision, 'allocationCount' => $allocations, 'activatedAttemptCount' => $activated];
    }
}
