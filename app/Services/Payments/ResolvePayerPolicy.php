<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Data\Payments\PayerDecision;
use App\Enums\PayerType;
use App\Models\Branch;
use App\Models\IntegrationClient;
use App\Models\IntegrationSource;
use App\Models\TestPackage;
use Carbon\CarbonInterface;

final class ResolvePayerPolicy
{
    /**
     * Evaluate freshly loaded server-side registry records, never browser models.
     * The caller owns authentication, RLS, and transactional reload before reservation.
     * No queries, writes, gateway calls, or changes to existing orders occur here.
     */
    public function resolve(
        Branch $organization,
        IntegrationClient $client,
        IntegrationSource $source,
        TestPackage $package,
        CarbonInterface $at,
        ?string $requestedPayer = null,
    ): PayerDecision {
        if ($organization->id < 1 || $client->id < 1 || $source->id < 1
            || $client->organization_id !== $organization->id
            || $source->integration_client_id !== $client->id) {
            return PayerDecision::denied('INTEGRATION_CONTEXT_INVALID');
        }
        if (! $organization->is_active || $organization->status !== 'ACTIVE') {
            return PayerDecision::denied('ORGANIZATION_NOT_ALLOWED');
        }
        if (! $client->enabled || ! $this->effective($client->effective_from, $client->effective_until, $at)) {
            return PayerDecision::denied('INTEGRATION_NOT_ALLOWED');
        }
        if ($source->status !== 'ACTIVE' || ! $this->effective($source->effective_from, $source->effective_until, $at)) {
            return PayerDecision::denied('SOURCE_NOT_ALLOWED');
        }
        $packages = $source->getAttribute('allowed_assessment_packages');
        if ($package->id < 1 || ! $package->is_active || ! is_array($packages)
            || ! array_is_list($packages) || ! in_array($package->code, $packages, true)) {
            return PayerDecision::denied('PACKAGE_NOT_ALLOWED');
        }

        $organizationTypes = $organization->getAttribute('allowed_payer_types');
        $sourceTypes = $source->getAttribute('allowed_payer_types');
        if ($organizationTypes === null || $sourceTypes === null) {
            return PayerDecision::denied('PAYER_POLICY_UNCONFIGURED');
        }
        if (! $this->validPayerList($organizationTypes) || ! $this->validPayerList($sourceTypes)) {
            return PayerDecision::denied('PAYER_POLICY_INVALID');
        }

        $requested = $requestedPayer === null ? null : PayerType::tryFrom($requestedPayer);
        if ($requestedPayer !== null && $requested === null) {
            return PayerDecision::denied('INVALID_PAYER_TYPE');
        }

        $allowed = array_values(array_filter(PayerType::cases(), fn (PayerType $type): bool => in_array($type->value, $organizationTypes, true)
            && in_array($type->value, $sourceTypes, true)));
        if ($allowed === []) {
            return PayerDecision::denied('PAYER_NOT_ALLOWED');
        }

        $lockValue = $source->getAttribute('locked_payer_type');
        $locked = is_string($lockValue) ? PayerType::tryFrom($lockValue) : null;
        if ($lockValue !== null && $locked === null) {
            return PayerDecision::denied('PAYER_POLICY_INVALID');
        }
        if ($locked !== null) {
            if (! in_array($locked, $allowed, true)) {
                return PayerDecision::denied('PAYER_POLICY_INVALID');
            }
            if ($requested !== null && $requested !== $locked) {
                return PayerDecision::denied('PAYER_LOCKED');
            }
            $allowed = [$locked];
        }
        if ($requested !== null && ! in_array($requested, $allowed, true)) {
            return PayerDecision::denied('PAYER_NOT_ALLOWED');
        }

        $selected = $requested ?? $locked ?? (count($allowed) === 1 ? $allowed[0] : null);

        return new PayerDecision(
            $allowed, $selected, $locked,
            $selected === PayerType::Organization ? $client->organization_id : null,
        );
    }

    private function effective(?CarbonInterface $from, ?CarbonInterface $until, CarbonInterface $at): bool
    {
        return ($from === null || $from->lte($at)) && ($until === null || $until->gt($at));
    }

    /** @phpstan-assert-if-true list<string> $values */
    private function validPayerList(mixed $values): bool
    {
        if (! is_array($values) || ! array_is_list($values)) {
            return false;
        }
        foreach ($values as $value) {
            if (! is_string($value) || PayerType::tryFrom($value) === null) {
                return false;
            }
        }

        return true;
    }
}
