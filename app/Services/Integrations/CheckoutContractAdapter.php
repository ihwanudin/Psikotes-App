<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Actions\Integrations\IntegrationContractViolation;
use App\Data\Payments\PayerDecision;
use App\Models\IntegrationClient;
use App\Models\IntegrationSource;
use App\Models\TestPackage;
use App\Security\RlsContextRunner;
use App\Services\Payments\ResolvePayerPolicy;

final readonly class CheckoutContractAdapter
{
    public const string VERSION = 'checkout-v2';

    public function __construct(private ResolvePayerPolicy $policy) {}

    /** Caller must establish service RLS context. A stopped v2 source never downgrades to v1. */
    public function assertLegacyAllowed(int $organizationId, string $sourceSystem): void
    {
        if (app(RlsContextRunner::class)->current()?->role !== 'service') {
            throw new \LogicException('Legacy cutover checks require service RLS context.');
        }
        if (IntegrationSource::query()->where('source_system', $sourceSystem)
            ->where('contract_version', self::VERSION)
            ->whereHas('client', fn ($query) => $query->where('organization_id', $organizationId))
            ->exists()) {
            throw new IntegrationContractViolation('CHECKOUT_CONTRACT_REQUIRED');
        }
    }

    /**
     * Input is validated by ProvisionCheckoutParticipantRequest; registry is server-mapped.
     * This decision does not persist an attempt or authorize payment/access.
     *
     * @param  array<string, mixed>  $input
     */
    public function resolve(IntegrationClient $client, IntegrationSource $source, TestPackage $package, array $input): PayerDecision
    {
        if (config('assessment_integration.checkout.enabled') !== true) {
            throw new IntegrationContractViolation('CHECKOUT_NOT_ENABLED', 503);
        }
        if (($input['contractVersion'] ?? null) !== self::VERSION || $source->contract_version !== self::VERSION) {
            throw new IntegrationContractViolation('CHECKOUT_CONTRACT_REQUIRED');
        }
        $organization = $client->organization;
        if (($input['organizationCode'] ?? null) !== $organization->organization_code
            || ($input['sourceSystem'] ?? null) !== $source->source_system
            || ($input['assessmentPackageCode'] ?? null) !== $package->code) {
            throw new IntegrationContractViolation('INTEGRATION_CONTEXT_INVALID');
        }
        $payer = $input['payerType'] ?? null;
        if (array_key_exists('fundingMode', $input)) {
            if (array_key_exists('payerType', $input)) {
                throw new IntegrationContractViolation('AMBIGUOUS_PAYER_INPUT', 422);
            }
            if (config('assessment_integration.checkout.allow_legacy_funding_mapping') !== true) {
                throw new IntegrationContractViolation('LEGACY_FUNDING_MAPPING_DISABLED');
            }
            $payer = match ($input['fundingMode']) {
                'COMMERCIAL_SELF_PAY' => 'self',
                'INVOICED_TO_ORGANIZATION' => 'organization',
                default => throw new IntegrationContractViolation('LEGACY_FUNDING_NOT_SUPPORTED', 422),
            };
        }
        $decision = $this->policy->resolve($organization, $client, $source, $package, now(), $payer);
        if ($decision->rejectionReason !== null) {
            throw new IntegrationContractViolation($decision->rejectionReason);
        }

        return $decision;
    }
}
