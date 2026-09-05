<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Data\Integrations\CheckoutPaymentAction;
use App\Data\Integrations\CheckoutSessionPrincipal;
use App\Data\Payments\PayerDecision;
use App\Enums\PayerType;
use App\Models\AssessmentBill;
use App\Models\AssessmentBillItem;
use App\Models\AssessmentCharge;
use App\Models\AssessmentParticipant;
use App\Models\Branch;
use App\Models\IntegrationClient;
use App\Models\IntegrationSource;
use App\Models\PackageItem;
use App\Models\TestPackage;
use App\Services\Payments\AssessmentPriceSnapshot;
use App\Services\Payments\ResolvePayerPolicy;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Collection;

/**
 * Projects only from a caller's freshly locked checkout graph. It performs no query or write.
 * Supplied models are not authorization; the credential lifecycle must own the transaction.
 */
final readonly class CheckoutPaymentActionProjector
{
    public function __construct(
        private CheckoutSessionHttpContract $http,
        private AssessmentPriceSnapshot $prices,
        private ResolvePayerPolicy $payerPolicy,
    ) {}

    public function project(
        CheckoutSessionPrincipal $principal,
        Branch $organization,
        IntegrationClient $client,
        IntegrationSource $source,
        TestPackage $package,
        AssessmentParticipant $attempt,
        ?AssessmentCharge $charge,
        ?AssessmentBillItem $item,
        ?AssessmentBill $bill,
        CarbonInterface $asOf,
        bool $psychotestConsentAccepted,
        bool $dassConsentAccepted,
    ): ?CheckoutPaymentAction {
        try {
            if ($this->http->paymentJsonBodyLimit() === null) {
                return null;
            }
            $payer = $this->assertGraph($principal, $organization, $client, $source, $package, $attempt);
            $decision = $this->payerPolicy->resolve($organization, $client, $source, $package, $asOf, $payer->value);
            $policy = $this->policySnapshot($decision, $principal, $payer);
            if ($charge === null && $item === null && $bill === null) {
                return $this->fresh($package, $payer, $psychotestConsentAccepted && $dassConsentAccepted);
            }
            if (! $charge instanceof AssessmentCharge || ! $item instanceof AssessmentBillItem
                || ! $bill instanceof AssessmentBill || $payer !== PayerType::SelfPay) {
                return null;
            }

            return $this->pending($principal, $package, $charge, $item, $bill, $policy);
        } catch (DomainException) {
            return null;
        }
    }

    private function assertGraph(CheckoutSessionPrincipal $principal, Branch $organization,
        IntegrationClient $client, IntegrationSource $source, TestPackage $package,
        AssessmentParticipant $attempt): PayerType
    {
        $metadata = $attempt->metadata;
        if (! $organization->exists || ! $client->exists || ! $source->exists || ! $package->exists || ! $attempt->exists
            || $organization->isDirty() || $client->isDirty() || $source->isDirty() || $package->isDirty() || $attempt->isDirty()
            || $principal->assessmentStatus !== 'PROVISIONED' || $attempt->assessment_status !== 'PROVISIONED'
            || $organization->id !== $principal->organizationId || $client->id !== $principal->integrationClientId
            || $client->organization_id !== $principal->organizationId || $source->id !== $principal->integrationSourceId
            || $source->integration_client_id !== $principal->integrationClientId
            || $source->source_system !== $principal->sourceSystem || $source->contract_version !== 'checkout-v2'
            || $package->id !== $principal->packageId || $attempt->id !== $principal->assessmentParticipantId
            || $attempt->organization_id !== $principal->organizationId || $attempt->participant_id !== $principal->participantId
            || $attempt->package_id !== $principal->packageId || $attempt->integration_client_id !== $principal->integrationClientId
            || $attempt->source_system !== $principal->sourceSystem
            || $attempt->assessment_attempt_id !== $principal->assessmentAttemptId
            || ! is_array($metadata) || ($metadata['checkout_contract_version'] ?? null) !== 'checkout-v2'
            || ($metadata['checkout_initial_funding_mode'] ?? null) !== $principal->fundingMode
            || $attempt->funding_mode !== $principal->fundingMode) {
            throw new DomainException('CHECKOUT_PAYMENT_ACTION_UNAVAILABLE');
        }
        $items = $package->relationLoaded('items') ? $package->getRelation('items') : null;
        if (! $items instanceof Collection || $items->isEmpty()) {
            throw new DomainException('CHECKOUT_PAYMENT_ACTION_UNAVAILABLE');
        }
        $types = [];
        foreach ($items as $item) {
            if (! $item instanceof PackageItem || ! $item->exists || $item->isDirty()
                || $item->package_id !== $package->id) {
                throw new DomainException('CHECKOUT_PAYMENT_ACTION_UNAVAILABLE');
            }
            $types[] = $item->test_type;
        }
        if (! in_array('dass21', $types, true) || array_diff($types, ['dass21']) === []) {
            throw new DomainException('CHECKOUT_PAYMENT_ACTION_UNAVAILABLE');
        }

        return match ($principal->fundingMode) {
            'COMMERCIAL_SELF_PAY' => PayerType::SelfPay,
            'INVOICED_TO_ORGANIZATION' => PayerType::Organization,
            default => throw new DomainException('CHECKOUT_PAYMENT_ACTION_UNAVAILABLE'),
        };
    }

    private function fresh(TestPackage $package, PayerType $payer, bool $consentsAccepted): ?CheckoutPaymentAction
    {
        $without = $this->choice($this->prices->capture($package, false));
        $with = $this->choice($this->prices->capture($package, true));
        if ($payer === PayerType::SelfPay) {
            $choices = array_values(array_filter([$without, $with],
                static fn (array $choice): bool => $choice['amountIdr'] !== 0 || $consentsAccepted));

            return $choices === [] ? null : new CheckoutPaymentAction('select', $choices);
        }
        if (! $consentsAccepted || $without['amountIdr'] !== 0) {
            return null;
        }

        return new CheckoutPaymentAction('select', [$without]);
    }

    /** @param array<string, mixed> $currentPolicy */
    private function pending(CheckoutSessionPrincipal $principal, TestPackage $package, AssessmentCharge $charge,
        AssessmentBillItem $item, AssessmentBill $bill, array $currentPolicy): ?CheckoutPaymentAction
    {
        if (! $charge->exists || ! $item->exists || ! $bill->exists
            || $charge->isDirty() || $item->isDirty() || $bill->isDirty()
            || $bill->status !== 'pending' || $bill->paid_at !== null || $item->settled_at !== null
            || $bill->organization_id !== $principal->organizationId || $bill->payer_type !== 'self'
            || $bill->payer_participant_id !== $principal->participantId || $bill->item_count !== 1
            || $bill->currency !== 'IDR' || $bill->amount < 1 || $bill->invoice_url === null
            || ! $this->validHttpsUrl($bill->invoice_url)
            || $bill->gateway_ref === null || trim($bill->gateway_ref) === ''
            || ! preg_match('/^AB_[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $bill->public_reference)
            || ! preg_match('/^[a-f0-9]{64}$/D', $bill->selection_hash)
            || ! preg_match('/^[a-f0-9]{64}$/D', $bill->request_hash)
            || trim($bill->idempotency_key) === ''
            || $bill->expires_at === null
            || $bill->proof_object_key !== null || $bill->proof_checksum_sha256 !== null
            || $bill->proof_mime_type !== null || $bill->proof_size_bytes !== null
            || $bill->proof_uploaded_at !== null || $bill->verified_at !== null
            || $bill->verified_by_admin_id !== null || $bill->rejection_reason !== null
            || $item->bill_id !== $bill->id || $item->charge_id !== $charge->id
            || $item->organization_id !== $principal->organizationId || $item->participant_id !== $principal->participantId
            || $item->payer_type !== 'self' || $item->payer_participant_id !== $principal->participantId
            || $item->currency !== 'IDR' || $item->amount !== $bill->amount
            || $charge->assessment_participant_id !== $principal->assessmentParticipantId
            || $charge->organization_id !== $principal->organizationId || $charge->participant_id !== $principal->participantId
            || $charge->package_id !== $principal->packageId || $charge->payer_type !== 'self'
            || $charge->currency !== 'IDR' || $charge->amount !== $bill->amount || $charge->free_settled_at !== null
            || $charge->policy_snapshot !== $currentPolicy) {
            return null;
        }
        $snapshot = $this->prices->fromCharge($charge, $charge->consultation_requested);
        if ($snapshot !== $this->prices->capture($package, $charge->consultation_requested)) {
            return null;
        }

        return new CheckoutPaymentAction('continue', [$this->choice($snapshot)]);
    }

    /** @return array{organizationId:int,integrationClientId:int,sourceId:int,contractVersion:string,allowedPayerTypes:list<string>,payerType:string,lockedPayerType:string|null} */
    private function policySnapshot(PayerDecision $decision, CheckoutSessionPrincipal $principal, PayerType $payer): array
    {
        $allowed = array_map(static fn (PayerType $type): string => $type->value, $decision->allowedPayerTypes);
        $canonical = array_values(array_filter(PayerType::cases(),
            static fn (PayerType $type): bool => in_array($type->value, $allowed, true)));
        if ($decision->rejectionReason !== null || $decision->selectedPayerType !== $payer
            || $allowed !== array_map(static fn (PayerType $type): string => $type->value, $canonical)
            || ! in_array($payer->value, $allowed, true)) {
            throw new DomainException('CHECKOUT_PAYMENT_ACTION_UNAVAILABLE');
        }

        return ['organizationId' => $principal->organizationId,
            'integrationClientId' => $principal->integrationClientId, 'sourceId' => $principal->integrationSourceId,
            'contractVersion' => 'checkout-v2', 'allowedPayerTypes' => $allowed, 'payerType' => $payer->value,
            'lockedPayerType' => $decision->lockedPayerType?->value];
    }

    /** @param array<string, mixed> $snapshot
     * @return array{consultationRequested:bool,baseAmountIdr:int,consultationAmountIdr:int,amountIdr:int}
     */
    private function choice(array $snapshot): array
    {
        foreach (['baseAmount', 'consultationAmount', 'amount'] as $field) {
            if (! is_int($snapshot[$field] ?? null) || $snapshot[$field] < 0
                || $snapshot[$field] > 9_007_199_254_740_991) {
                throw new DomainException('CHECKOUT_PAYMENT_ACTION_UNAVAILABLE');
            }
        }
        if (($snapshot['currency'] ?? null) !== 'IDR' || ! is_bool($snapshot['consultationRequested'] ?? null)) {
            throw new DomainException('CHECKOUT_PAYMENT_ACTION_UNAVAILABLE');
        }

        return ['consultationRequested' => $snapshot['consultationRequested'],
            'baseAmountIdr' => $snapshot['baseAmount'], 'consultationAmountIdr' => $snapshot['consultationAmount'],
            'amountIdr' => $snapshot['amount']];
    }

    private function validHttpsUrl(string $value): bool
    {
        $url = parse_url($value);

        return is_array($url) && ($url['scheme'] ?? null) === 'https'
            && is_string($url['host'] ?? null) && $url['host'] !== ''
            && ! isset($url['user']) && ! isset($url['pass']);
    }
}
