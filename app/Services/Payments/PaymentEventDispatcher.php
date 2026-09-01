<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Actions\Payments\FinalizeAssessmentBill;
use App\Data\Payments\PaymentEvent;
use App\Security\RlsContextRunner;
use App\Services\Payments\Exceptions\AssessmentBillPaymentRejected;
use App\Services\Payments\Exceptions\PaymentAmountMismatch;
use App\Services\Payments\Exceptions\PaymentReferenceMismatch;
use DomainException;
use LogicException;

/** Routes an already-authenticated/normalized provider event inside the durable claim transaction. */
final readonly class PaymentEventDispatcher
{
    public function __construct(
        private RlsContextRunner $contexts,
        private OrderPaymentEventHandler $legacy,
        private FinalizeAssessmentBill $bills,
    ) {}

    /** True only when this event produced a new durable domain transition. */
    public function applyInCurrentServiceTransaction(string $provider, PaymentEvent $event): bool
    {
        if ($this->contexts->current()?->role !== 'service') {
            throw new LogicException('Payment dispatch requires an active service transaction.');
        }
        if (! str_starts_with($event->merchantReference, 'AB_')) {
            return $this->legacy->applyInCurrentServiceTransaction($event)->changed;
        }
        if ($provider !== 'xendit') {
            throw new PaymentReferenceMismatch('Payment event target does not match.');
        }

        try {
            $result = $this->bills->execute($event);
        } catch (DomainException $exception) {
            throw match ($exception->getMessage()) {
                'ASSESSMENT_PAYMENT_REFERENCE_INVALID',
                'ASSESSMENT_PAYMENT_REFERENCE_MISMATCH' => new PaymentReferenceMismatch('Payment event target does not match.'),
                'ASSESSMENT_PAYMENT_MONEY_MISMATCH' => new PaymentAmountMismatch('Payment event money does not match.'),
                'ASSESSMENT_PAYMENT_STATE_INVALID',
                'ASSESSMENT_PAYMENT_TIME_INVALID',
                'ASSESSMENT_PAYMENT_SCOPE_INVALID',
                'ASSESSMENT_PAYMENT_SNAPSHOT_INVALID',
                'ASSESSMENT_PAYMENT_TOTAL_INVALID',
                'ASSESSMENT_PAYMENT_ALLOCATION_INVALID',
                'ASSESSMENT_PAYMENT_REPLAY_MISMATCH' => new AssessmentBillPaymentRejected('Assessment bill payment was rejected.'),
                default => $exception,
            };
        }

        return match ($result['decision']) {
            'settled', 'transitioned' => true,
            'replayed', 'ignored' => false,
            default => throw new LogicException('Assessment bill finalizer returned an unknown decision.'),
        };
    }
}
