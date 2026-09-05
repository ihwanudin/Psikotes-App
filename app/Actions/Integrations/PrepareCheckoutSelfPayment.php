<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Actions\Payments\PreviewAssessmentBill;
use App\Actions\Payments\ReserveAssessmentBill;
use App\Data\Integrations\CheckoutSelfPaymentPreparation;
use App\Data\Integrations\CheckoutSessionMutationCredentials;
use App\Data\Integrations\CheckoutSessionPrincipal;
use App\Enums\PayerType;
use App\Models\AssessmentBill;
use App\Models\AssessmentBillItem;
use App\Models\AssessmentCharge;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Security\RlsContextRunner;
use App\Services\Payments\AssessmentPriceSnapshot;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

/** Internal reservation preparation only. No claim, provider call, entitlement, or public response. */
final readonly class PrepareCheckoutSelfPayment
{
    private const string PURPOSE = 'checkout-self-v1:';

    public function __construct(
        private RlsContextRunner $contexts,
        private CheckoutSessionLifecycle $sessions,
        private PreviewAssessmentBill $preview,
        private ReserveAssessmentBill $reserve,
        private AssessmentPriceSnapshot $prices,
    ) {}

    public function execute(
        #[SensitiveParameter] CheckoutSessionMutationCredentials $credentials,
        bool $consultationRequested,
    ): CheckoutSelfPaymentPreparation {
        if ($this->contexts->current() !== null || DB::transactionLevel() !== 0) {
            throw new LogicException('Checkout payment preparation owns its service transaction.');
        }

        try {
            return $this->contexts->runAsService(
                fn (): CheckoutSelfPaymentPreparation => $this->prepare($credentials, $consultationRequested),
            );
        } catch (InvalidCheckoutSession|AuthorizationException|DomainException|InvalidArgumentException|LogicException) {
            throw new DomainException('CHECKOUT_PAYMENT_UNAVAILABLE');
        }
    }

    private function prepare(
        #[SensitiveParameter] CheckoutSessionMutationCredentials $credentials,
        bool $consultationRequested,
    ): CheckoutSelfPaymentPreparation {
        $principal = $this->sessions->lockMutation($credentials)->principal();
        if ($principal->fundingMode !== 'COMMERCIAL_SELF_PAY') {
            throw new DomainException('SELF_PAYMENT_REQUIRED');
        }
        $participant = Participant::withTrashed()->where('branch_id', $principal->organizationId)
            ->lockForUpdate()->find($principal->participantId);
        if ($participant === null || $participant->trashed()) {
            throw new AuthorizationException('BILL_PAYER_NOT_AUTHORIZED');
        }

        $selection = [['assessmentParticipantId' => $principal->assessmentParticipantId,
            'consultationRequested' => $consultationRequested]];
        $key = self::PURPOSE.$principal->assessmentAttemptId;
        $existing = $this->existing($principal, $key);
        if ($existing !== null) {
            return $this->validated($existing, $principal, $selection, $key, false);
        }

        /** @var Collection<int, PaymentMethod> $methods */
        $methods = PaymentMethod::query()->where('code', 'xendit')->orderBy('id')->lockForUpdate()->limit(2)->get();
        $method = $methods->count() === 1 ? $methods->first() : null;
        if (! $method instanceof PaymentMethod || ! $method->is_active) {
            throw new DomainException('PAYMENT_METHOD_NOT_AVAILABLE');
        }
        $preview = $this->preview->execute(
            $principal->organizationId,
            $selection,
            PayerType::SelfPay,
            $principal->participantId,
        );
        $selectionHash = $preview['selectionHash'] ?? null;
        if (! is_string($selectionHash) || ! is_int($preview['totalAmount'] ?? null)
            || $preview['totalAmount'] < 1 || $preview['canReserve'] !== true) {
            throw new DomainException('SELF_PAYMENT_NOT_PAYABLE');
        }
        $bill = $this->reserve->execute($participant, $selection, $method->id, $selectionHash, $key);

        return $this->validated($bill, $principal, $selection, $key, true);
    }

    private function existing(CheckoutSessionPrincipal $principal, string $key): ?AssessmentBill
    {
        $linked = DB::table('assessment_bill_items')
            ->join('assessment_charges', 'assessment_charges.id', '=', 'assessment_bill_items.charge_id')
            ->where('assessment_charges.assessment_participant_id', $principal->assessmentParticipantId)
            ->pluck('assessment_bill_items.bill_id')->all();
        $keyed = AssessmentBill::query()->where('idempotency_key', $key)->pluck('id')->all();
        $ids = array_values(array_unique([...$linked, ...$keyed]));
        if ($ids === []) {
            return null;
        }
        $bills = AssessmentBill::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        if ($bills->count() !== 1) {
            throw new DomainException('SELF_PAYMENT_BILL_INVALID');
        }

        return $bills->first();
    }

    /** @param list<array{assessmentParticipantId:int,consultationRequested:bool}> $selection */
    private function validated(AssessmentBill $bill, CheckoutSessionPrincipal $principal,
        array $selection, string $key, bool $created): CheckoutSelfPaymentPreparation
    {
        $method = PaymentMethod::query()->lockForUpdate()->find($bill->payment_method_id);
        $items = AssessmentBillItem::query()->where('bill_id', $bill->id)->orderBy('id')->lockForUpdate()->get();
        $chargeIds = $items->pluck('charge_id');
        $charges = AssessmentCharge::query()->whereIn('id', $chargeIds)
            ->orderBy('assessment_participant_id')->lockForUpdate()->get();
        $item = $items->count() === 1 ? $items->first() : null;
        $charge = $charges->count() === 1 ? $charges->first() : null;
        $consultation = $selection[0]['consultationRequested'];

        if (! $method instanceof PaymentMethod || $method->code !== 'xendit'
            || ! $item instanceof AssessmentBillItem || ! $charge instanceof AssessmentCharge
            || ! in_array($bill->status, ['reserved', 'issuing', 'pending', 'paid'], true)
            || $bill->organization_id !== $principal->organizationId || $bill->payer_type !== 'self'
            || $bill->payer_participant_id !== $principal->participantId || $bill->item_count !== 1
            || $bill->amount < 1 || $bill->currency !== 'IDR'
            || ! preg_match('/^AB_[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $bill->public_reference)
            || ! preg_match('/^[a-f0-9]{64}$/D', $bill->selection_hash)
            || ! preg_match('/^[a-f0-9]{64}$/D', $bill->request_hash)
            || ! hash_equals($key, $bill->idempotency_key)
            || $item->bill_id !== $bill->id || $item->charge_id !== $charge->id
            || $item->organization_id !== $principal->organizationId
            || $item->participant_id !== $principal->participantId || $item->payer_type !== 'self'
            || $item->payer_participant_id !== $principal->participantId
            || $item->amount !== $bill->amount || $item->currency !== 'IDR'
            || $charge->assessment_participant_id !== $principal->assessmentParticipantId
            || $charge->organization_id !== $principal->organizationId
            || $charge->participant_id !== $principal->participantId || $charge->package_id !== $principal->packageId
            || $charge->payer_type !== 'self' || $charge->amount !== $bill->amount || $charge->currency !== 'IDR'
            || $charge->consultation_requested !== $consultation || $charge->free_settled_at !== null
            || $bill->proof_object_key !== null || $bill->proof_checksum_sha256 !== null
            || $bill->proof_mime_type !== null || $bill->proof_size_bytes !== null
            || $bill->proof_uploaded_at !== null || $bill->verified_at !== null
            || $bill->verified_by_admin_id !== null || $bill->rejection_reason !== null) {
            throw new DomainException('SELF_PAYMENT_BILL_INVALID');
        }
        $this->prices->fromCharge($charge, $consultation);
        $this->assertPolicySnapshot($charge->policy_snapshot, $principal);
        $requestHash = hash('sha256', json_encode([
            'organizationId' => $principal->organizationId, 'payerType' => 'self',
            'participantId' => $principal->participantId, 'selection' => $selection,
            'selectionHash' => $bill->selection_hash, 'paymentMethodId' => $method->id,
        ], JSON_THROW_ON_ERROR));
        if (! hash_equals($requestHash, $bill->request_hash)) {
            throw new DomainException('SELF_PAYMENT_BILL_INVALID');
        }
        $this->assertState($bill, $item);

        return new CheckoutSelfPaymentPreparation(
            $principal->organizationId,
            $bill->id,
            $bill->status,
            $created,
        );
    }

    /** @param array<string, mixed> $snapshot */
    private function assertPolicySnapshot(array $snapshot, CheckoutSessionPrincipal $principal): void
    {
        $allowed = $snapshot['allowedPayerTypes'] ?? null;
        if (count($snapshot) !== 7 || ($snapshot['organizationId'] ?? null) !== $principal->organizationId
            || ($snapshot['integrationClientId'] ?? null) !== $principal->integrationClientId
            || ($snapshot['sourceId'] ?? null) !== $principal->integrationSourceId
            || ($snapshot['contractVersion'] ?? null) !== 'checkout-v2'
            || ($snapshot['payerType'] ?? null) !== 'self'
            || ! is_array($allowed) || ! array_is_list($allowed) || ! in_array('self', $allowed, true)
            || count(array_unique($allowed)) !== count($allowed)
            || ! in_array($snapshot['lockedPayerType'] ?? null, [null, 'self'], true)) {
            throw new DomainException('SELF_PAYMENT_POLICY_SNAPSHOT_INVALID');
        }
        foreach ($allowed as $payer) {
            if (! in_array($payer, ['self', 'organization'], true)) {
                throw new DomainException('SELF_PAYMENT_POLICY_SNAPSHOT_INVALID');
            }
        }
    }

    private function assertState(AssessmentBill $bill, AssessmentBillItem $item): void
    {
        $providerIdentity = is_string($bill->gateway_ref) && trim($bill->gateway_ref) !== ''
            && is_string($bill->invoice_url) && $this->validHttpsUrl($bill->invoice_url)
            && $bill->expires_at !== null;
        if (in_array($bill->status, ['reserved', 'issuing'], true)) {
            if ($bill->gateway_ref !== null || $bill->invoice_url !== null || $bill->expires_at !== null
                || $bill->paid_at !== null || $item->settled_at !== null) {
                throw new DomainException('SELF_PAYMENT_STATE_INVALID');
            }

            return;
        }
        if (! $providerIdentity) {
            throw new DomainException('SELF_PAYMENT_STATE_INVALID');
        }
        if ($bill->status === 'pending') {
            if ($bill->paid_at !== null || $item->settled_at !== null) {
                throw new DomainException('SELF_PAYMENT_STATE_INVALID');
            }

            return;
        }
        if ($bill->paid_at === null || $item->settled_at === null
            || ! $item->settled_at->equalTo($bill->paid_at)
            || DB::table('audit_logs')->where('action', 'assessment_bill.paid')
                ->where('subject_type', AssessmentBill::class)->where('subject_id', (string) $bill->id)->count() !== 1) {
            throw new DomainException('SELF_PAYMENT_STATE_INVALID');
        }
    }

    private function validHttpsUrl(string $value): bool
    {
        $url = parse_url($value);

        return is_array($url) && ($url['scheme'] ?? null) === 'https'
            && is_string($url['host'] ?? null) && $url['host'] !== ''
            && ! isset($url['user']) && ! isset($url['pass']);
    }
}
