<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Data\Payments\AssessmentBillManualReview;
use App\Data\Payments\PaymentEvent;
use App\Enums\AdminRole;
use App\Enums\AssessmentBillManualDecision;
use App\Enums\AssessmentBillManualReviewError;
use App\Enums\PaymentStatus;
use App\Exceptions\AssessmentBillManualReviewException;
use App\Models\Admin;
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
use App\Services\Payments\AssessmentBillProofIdentity;
use App\Services\Payments\AssessmentPriceSnapshot;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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
        private AssessmentBillProofIdentity $proofs,
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
    public function executeManual(AssessmentBillManualReview $review): array
    {
        if ($this->contexts->current() !== null || DB::connection()->transactionLevel() !== 0) {
            throw new LogicException('Assessment bill manual review requires an isolated service transaction.');
        }

        return $this->contexts->run(new RlsContext('service'),
            fn (): array => DB::transaction(fn (): array => $this->finalizeManual($review)));
    }

    /** @return array{decision: string, allocationCount: int, activatedAttemptCount: int} */
    private function finalize(PaymentEvent $event): array
    {
        ['bill' => $bill, 'items' => $items, 'attempts' => $attempts, 'participants' => $participants,
            'packages' => $packages, 'charges' => $charges, 'method' => $method] = $this->lockGraph($event->merchantReference);

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
        if (in_array($bill->status, ['expired', 'rejected'], true)) {
            $this->assertTerminalReplay($event, $bill, $items);

            return $this->result('replayed', $items->count(), 0);
        }
        if ($bill->status !== 'pending' || $bill->paid_at !== null) {
            throw new DomainException('ASSESSMENT_PAYMENT_STATE_INVALID');
        }
        if ($event->status === PaymentStatus::Pending) {
            return $this->result('ignored', $items->count(), 0);
        }
        if (in_array($event->status, [PaymentStatus::Expired, PaymentStatus::Cancelled], true)) {
            return $this->transitionTerminal($event, $bill, $items);
        }
        $this->assertPaidEvent($event);

        $paidAt = CarbonImmutable::instance($event->occurredAt)->utc()->startOfSecond();
        if ($paidAt->isFuture()) {
            throw new DomainException('ASSESSMENT_PAYMENT_TIME_INVALID');
        }

        return $this->settle($bill, $items, $attempts, $paidAt,
            ['status' => 'paid', 'paid_at' => $paidAt],
            function () use ($event, $bill, $items, $paidAt): void {
                $this->audit($event, $bill, $items, $paidAt);
            });
    }

    /** @return array{decision: string, allocationCount: int, activatedAttemptCount: int} */
    private function finalizeManual(AssessmentBillManualReview $review): array
    {
        $actor = Admin::withTrashed()->lockForUpdate()->find($review->actorAdminId);
        if ($actor === null || $actor->deleted_at !== null || $actor->role !== AdminRole::SuperAdmin) {
            throw new AssessmentBillManualReviewException(AssessmentBillManualReviewError::NotFound);
        }

        ['bill' => $bill, 'items' => $items, 'attempts' => $attempts, 'participants' => $participants,
            'packages' => $packages, 'charges' => $charges, 'method' => $method] = $this->lockGraph(
                $review->billReference,
                true,
            );
        try {
            $this->assertAllocations($bill, $items, $attempts, $participants, $packages, $charges, $method);
        } catch (DomainException) {
            throw new AssessmentBillManualReviewException(AssessmentBillManualReviewError::ScopeInvalid);
        }
        foreach ($items as $item) {
            if ($item->settled_at !== null && $bill->status === 'pending') {
                throw new AssessmentBillManualReviewException(AssessmentBillManualReviewError::ScopeInvalid);
            }
        }
        $fingerprint = $this->assertManualIdentity($review, $bill, $method);

        if (in_array($bill->status, ['paid', 'rejected'], true)) {
            return $this->assertManualReplay($review, $actor, $bill, $items, $fingerprint);
        }
        if ($bill->status !== 'pending' || $bill->paid_at !== null || $bill->verified_at !== null
            || $bill->verified_by_admin_id !== null || $bill->rejection_reason !== null) {
            throw new AssessmentBillManualReviewException(AssessmentBillManualReviewError::StateInvalid);
        }

        $reviewedAt = now()->toImmutable()->utc()->startOfSecond();
        if ($review->decision === AssessmentBillManualDecision::Reject) {
            foreach ($items as $item) {
                if ($item->settled_at !== null) {
                    throw new AssessmentBillManualReviewException(AssessmentBillManualReviewError::ScopeInvalid);
                }
            }
            $bill->update([
                'status' => 'rejected',
                'verified_at' => $reviewedAt,
                'verified_by_admin_id' => $actor->id,
                'rejection_reason' => $review->rejectionCode?->value,
            ]);
            $this->auditManual($review, $actor, $bill, $items, $reviewedAt, $fingerprint);

            return $this->result('rejected', $items->count(), 0);
        }

        return $this->settle($bill, $items, $attempts, $reviewedAt, [
            'status' => 'paid',
            'paid_at' => $reviewedAt,
            'verified_at' => $reviewedAt,
            'verified_by_admin_id' => $actor->id,
            'rejection_reason' => null,
        ], function () use ($review, $actor, $bill, $items, $reviewedAt, $fingerprint): void {
            $this->auditManual($review, $actor, $bill, $items, $reviewedAt, $fingerprint);
        });
    }

    /**
     * @return array{bill: AssessmentBill, items: Collection<int, AssessmentBillItem>,
     *   attempts: Collection<int, AssessmentParticipant>, participants: Collection<int, Participant>,
     *   packages: Collection<int, TestPackage>, charges: Collection<int, AssessmentCharge>, method: PaymentMethod|null}
     */
    private function lockGraph(string $reference, bool $manual = false): array
    {
        // The reference is only an unlocked routing hint. Authority starts at the organization mutex.
        $organizationId = AssessmentBill::query()->where('public_reference', $reference)->value('organization_id');
        if (! is_int($organizationId) || Branch::query()->lockForUpdate()->find($organizationId) === null) {
            $this->throwReferenceMismatch($manual);
        }

        $bill = AssessmentBill::query()->where('organization_id', $organizationId)
            ->where('public_reference', $reference)->lockForUpdate()->first();
        if ($bill === null) {
            $this->throwReferenceMismatch($manual);
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

        return compact('bill', 'items', 'attempts', 'participants', 'packages', 'charges', 'method');
    }

    private function throwReferenceMismatch(bool $manual): never
    {
        if ($manual) {
            throw new AssessmentBillManualReviewException(AssessmentBillManualReviewError::NotFound);
        }

        throw new DomainException('ASSESSMENT_PAYMENT_REFERENCE_MISMATCH');
    }

    private function assertManualIdentity(AssessmentBillManualReview $review, AssessmentBill $bill,
        ?PaymentMethod $method): string
    {
        if ($method === null || $method->code !== 'manual_transfer' || $bill->gateway_ref !== null
            || $bill->invoice_url !== null) {
            throw new AssessmentBillManualReviewException(AssessmentBillManualReviewError::ChannelInvalid);
        }
        try {
            $fingerprint = $this->proofs->fingerprint($bill, now());
        } catch (DomainException) {
            throw new AssessmentBillManualReviewException(AssessmentBillManualReviewError::ProofInvalid);
        }
        if ($fingerprint === null) {
            throw new AssessmentBillManualReviewException(AssessmentBillManualReviewError::ProofInvalid);
        }
        if (! hash_equals($fingerprint, $review->expectedProofFingerprint)) {
            throw new AssessmentBillManualReviewException(AssessmentBillManualReviewError::Conflict);
        }

        return $fingerprint;
    }

    /** @param Collection<int, AssessmentBillItem> $items
     * @return array{decision: string, allocationCount: int, activatedAttemptCount: int}
     */
    private function assertManualReplay(AssessmentBillManualReview $review, Admin $actor, AssessmentBill $bill,
        Collection $items, string $fingerprint): array
    {
        $statusMatches = ($review->decision === AssessmentBillManualDecision::Approve && $bill->status === 'paid')
            || ($review->decision === AssessmentBillManualDecision::Reject && $bill->status === 'rejected');
        if (! $statusMatches || $bill->verified_at === null || $bill->verified_by_admin_id !== $actor->id
            || ($bill->status === 'paid' && ($bill->paid_at === null || ! $bill->paid_at->equalTo($bill->verified_at)
                || $bill->rejection_reason !== null))
            || ($bill->status === 'rejected' && ($bill->paid_at !== null
                || $bill->rejection_reason !== $review->rejectionCode?->value))) {
            throw new AssessmentBillManualReviewException(AssessmentBillManualReviewError::Conflict);
        }
        foreach ($items as $item) {
            if (($bill->status === 'paid' && ($item->settled_at === null || ! $item->settled_at->equalTo($bill->paid_at)))
                || ($bill->status === 'rejected' && $item->settled_at !== null)) {
                throw new AssessmentBillManualReviewException(AssessmentBillManualReviewError::Conflict);
            }
        }
        $audit = DB::table('audit_logs')->where('action', 'assessment_bill.'.$bill->status)
            ->where('subject_type', AssessmentBill::class)->where('subject_id', (string) $bill->id)->get();
        $expected = $this->manualAuditContext($review, $bill, $items, $bill->verified_at, $fingerprint);
        if ($audit->count() !== 1 || $audit->first()->actor_type !== 'admin'
            || (int) $audit->first()->actor_id !== $actor->id
            || json_decode((string) $audit->first()->context, true, 512, JSON_THROW_ON_ERROR) !== $expected) {
            throw new AssessmentBillManualReviewException(AssessmentBillManualReviewError::Conflict);
        }

        return $this->result('replayed', $items->count(), 0);
    }

    /** @param Collection<int, AssessmentBillItem> $items
     * @param  Collection<int, AssessmentParticipant>  $attempts
     * @param  array<string, mixed>  $attributes
     * @return array{decision: string, allocationCount: int, activatedAttemptCount: int}
     */
    private function settle(AssessmentBill $bill, Collection $items, Collection $attempts, CarbonImmutable $paidAt,
        array $attributes, callable $audit): array
    {
        $bill->update($attributes);
        foreach ($items as $item) {
            if ($item->settled_at !== null) {
                throw new DomainException('ASSESSMENT_PAYMENT_ALLOCATION_INVALID');
            }
            $item->settled_at = $paidAt;
            $item->save();
        }
        $audit();

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

    private function assertPaidEvent(PaymentEvent $event): void
    {
        if ($event->status !== PaymentStatus::Paid) {
            throw new DomainException('ASSESSMENT_PAYMENT_STATE_INVALID');
        }
    }

    /** @param  Collection<int, AssessmentBillItem>  $items
     * @return array{decision: string, allocationCount: int, activatedAttemptCount: int}
     */
    private function transitionTerminal(PaymentEvent $event, AssessmentBill $bill, Collection $items): array
    {
        $occurredAt = CarbonImmutable::instance($event->occurredAt)->utc()->startOfSecond();
        if ($occurredAt->isFuture()) {
            throw new DomainException('ASSESSMENT_PAYMENT_TIME_INVALID');
        }
        foreach ($items as $item) {
            if ($item->settled_at !== null) {
                throw new DomainException('ASSESSMENT_PAYMENT_ALLOCATION_INVALID');
            }
        }
        $status = $event->status === PaymentStatus::Expired ? 'expired' : 'rejected';
        $bill->update(['status' => $status]);
        $this->auditTerminal($event, $bill, $items, $occurredAt, $status);

        return $this->result('transitioned', $items->count(), 0);
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

    /** @param  Collection<int, AssessmentBillItem>  $items */
    private function assertTerminalReplay(PaymentEvent $event, AssessmentBill $bill, Collection $items): void
    {
        $expectedStatus = $event->status === PaymentStatus::Expired ? 'expired'
            : ($event->status === PaymentStatus::Cancelled ? 'rejected' : null);
        if ($expectedStatus !== $bill->status || $bill->paid_at !== null) {
            throw new DomainException('ASSESSMENT_PAYMENT_STATE_INVALID');
        }
        foreach ($items as $item) {
            if ($item->settled_at !== null) {
                throw new DomainException('ASSESSMENT_PAYMENT_REPLAY_MISMATCH');
            }
        }
        $occurredAt = CarbonImmutable::instance($event->occurredAt)->utc()->startOfSecond();
        $audit = DB::table('audit_logs')->where('action', 'assessment_bill.'.$expectedStatus)
            ->where('subject_type', AssessmentBill::class)->where('subject_id', (string) $bill->id)->get();
        if ($audit->count() !== 1 || json_decode((string) $audit->first()->context, true, 512, JSON_THROW_ON_ERROR)
            !== $this->terminalAuditContext($event, $items, $occurredAt, $expectedStatus)) {
            throw new DomainException('ASSESSMENT_PAYMENT_REPLAY_MISMATCH');
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

    /** @param Collection<int, AssessmentBillItem> $items */
    private function auditManual(AssessmentBillManualReview $review, Admin $actor, AssessmentBill $bill,
        Collection $items, CarbonImmutable $reviewedAt, string $fingerprint): void
    {
        $now = now()->toImmutable();
        DB::table('audit_logs')->insert([
            'branch_id' => $bill->organization_id,
            'actor_type' => 'admin',
            'actor_id' => (string) $actor->id,
            'action' => 'assessment_bill.'.$bill->status,
            'subject_type' => AssessmentBill::class,
            'subject_id' => (string) $bill->id,
            'context' => json_encode(
                $this->manualAuditContext($review, $bill, $items, $reviewedAt, $fingerprint),
                JSON_THROW_ON_ERROR,
            ),
            'occurred_at' => $now,
            'expires_at' => $now->addYearsNoOverflow(2),
        ]);
    }

    /** @param  Collection<int, AssessmentBillItem>  $items */
    private function auditTerminal(PaymentEvent $event, AssessmentBill $bill, Collection $items,
        CarbonImmutable $occurredAt, string $status): void
    {
        $now = now()->toImmutable();
        DB::table('audit_logs')->insert([
            'branch_id' => $bill->organization_id,
            'actor_type' => 'system',
            'actor_id' => null,
            'action' => 'assessment_bill.'.$status,
            'subject_type' => AssessmentBill::class,
            'subject_id' => (string) $bill->id,
            'context' => json_encode($this->terminalAuditContext($event, $items, $occurredAt, $status), JSON_THROW_ON_ERROR),
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

    /** @param Collection<int, AssessmentBillItem> $items
     * @return array<string, mixed>
     */
    private function manualAuditContext(AssessmentBillManualReview $review, AssessmentBill $bill, Collection $items,
        CarbonInterface $reviewedAt, string $fingerprint): array
    {
        return [
            'version' => 1,
            'source' => 'manual_transfer',
            'decision' => $review->decision->value,
            'rejectionCode' => $review->rejectionCode?->value,
            'proofFingerprint' => $fingerprint,
            'amount' => $bill->amount,
            'currency' => $bill->currency,
            'reviewedAt' => $reviewedAt->format('Y-m-d\TH:i:s.u\Z'),
            'billItemIds' => $items->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(),
        ];
    }

    /** @param  Collection<int, AssessmentBillItem>  $items
     * @return array<string, mixed>
     */
    private function terminalAuditContext(PaymentEvent $event, Collection $items, CarbonImmutable $occurredAt,
        string $status): array
    {
        return [
            'version' => 1,
            'eventIdHash' => hash('sha256', $event->eventId),
            'providerReferenceHash' => hash('sha256', $event->providerReference),
            'status' => $status,
            'amount' => $event->amount,
            'currency' => $event->currency,
            'occurredAt' => $occurredAt->format('Y-m-d\TH:i:s.u\Z'),
            'billItemIds' => $items->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(),
        ];
    }

    /** @return array{decision: string, allocationCount: int, activatedAttemptCount: int} */
    private function result(string $decision, int $allocations, int $activated): array
    {
        return ['decision' => $decision, 'allocationCount' => $allocations, 'activatedAttemptCount' => $activated];
    }
}
