<?php

declare(strict_types=1);

namespace Tests\Unit\Payments;

use App\Data\Payments\PayerDecision;
use App\Enums\PayerType;
use App\Models\Branch;
use App\Models\IntegrationClient;
use App\Models\IntegrationSource;
use App\Models\TestPackage;
use App\Services\Payments\ResolvePayerPolicy;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PayerPolicyTest extends TestCase
{
    private Branch $organization;

    private IntegrationClient $client;

    private IntegrationSource $source;

    private TestPackage $package;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = (new Branch)->forceFill([
            'id' => 10, 'is_active' => true, 'status' => 'ACTIVE',
            'allowed_payer_types' => ['self', 'organization'],
        ]);
        $this->client = (new IntegrationClient)->setDateFormat('Y-m-d H:i:s')->forceFill([
            'id' => 20, 'organization_id' => 10, 'enabled' => true,
        ]);
        $this->source = (new IntegrationSource)->setDateFormat('Y-m-d H:i:s')->forceFill([
            'id' => 30, 'integration_client_id' => 20, 'status' => 'ACTIVE',
            'allowed_assessment_packages' => ['SYNTHETIC'],
            'allowed_payer_types' => ['self', 'organization'], 'locked_payer_type' => null,
        ]);
        $this->package = (new TestPackage)->forceFill(['id' => 40, 'code' => 'SYNTHETIC', 'is_active' => true]);
    }

    public function test_multiple_choices_require_selection_without_assuming_a_payer(): void
    {
        $decision = $this->resolve();
        $this->assertNull($decision->rejectionReason);
        $this->assertSame([PayerType::SelfPay, PayerType::Organization], $decision->allowedPayerTypes);
        $this->assertTrue($decision->requiresSelection());
        $this->assertNull($decision->selectedPayerType);
        $this->assertNull($decision->payerOrganizationId);
    }

    public function test_self_pay_never_has_an_organization_payer_id(): void
    {
        $decision = $this->resolve('self');
        $this->assertNull($decision->rejectionReason);
        $this->assertSame(PayerType::SelfPay, $decision->selectedPayerType);
        $this->assertNull($decision->payerOrganizationId);
        $this->assertFalse($decision->requiresSelection());
    }

    public function test_organization_payer_comes_from_authenticated_client_mapping(): void
    {
        $decision = $this->resolve('organization');
        $this->assertNull($decision->rejectionReason);
        $this->assertSame(PayerType::Organization, $decision->selectedPayerType);
        $this->assertSame(10, $decision->payerOrganizationId);
    }

    public function test_intersection_selects_the_only_effective_option(): void
    {
        $this->organization->allowed_payer_types = ['self'];
        $decision = $this->resolve();
        $this->assertSame([PayerType::SelfPay], $decision->allowedPayerTypes);
        $this->assertSame(PayerType::SelfPay, $decision->selectedPayerType);
        $this->assertNull($decision->rejectionReason);
    }

    public function test_source_lock_limits_choices_and_cannot_be_overridden(): void
    {
        $this->source->locked_payer_type = 'organization';
        $decision = $this->resolve();
        $this->assertSame([PayerType::Organization], $decision->allowedPayerTypes);
        $this->assertSame(PayerType::Organization, $decision->lockedPayerType);
        $this->assertSame(PayerType::Organization, $decision->selectedPayerType);
        $this->assertSame(10, $decision->payerOrganizationId);
        $this->assertDenied($this->resolve('self'), 'PAYER_LOCKED');
    }

    /** @return iterable<string, array{string}> */
    public static function forgedPayers(): iterable
    {
        foreach (['SPONSORED', 'INTERNAL', 'WAIVED', 'COMMERCIAL_SELF_PAY', 'INVOICED_TO_ORGANIZATION', 'credit', '', 'SELF', ' self '] as $value) {
            yield 'reject '.$value => [$value];
        }
    }

    #[DataProvider('forgedPayers')]
    public function test_unknown_and_legacy_input_is_not_implicitly_mapped(string $requested): void
    {
        $this->assertDenied($this->resolve($requested), 'INVALID_PAYER_TYPE');
    }

    /** @return iterable<string, array{string, string, mixed, string}> */
    public static function invalidContexts(): iterable
    {
        yield 'organization swapped' => ['organization', 'id', 99, 'INTEGRATION_CONTEXT_INVALID'];
        yield 'client organization swapped' => ['client', 'organization_id', 99, 'INTEGRATION_CONTEXT_INVALID'];
        yield 'source belongs to other client' => ['source', 'integration_client_id', 99, 'INTEGRATION_CONTEXT_INVALID'];
        foreach (['organization', 'client', 'source'] as $target) {
            yield $target.' missing identity' => [$target, 'id', null, 'INTEGRATION_CONTEXT_INVALID'];
        }
        yield 'organization disabled' => ['organization', 'is_active', false, 'ORGANIZATION_NOT_ALLOWED'];
        yield 'organization suspended' => ['organization', 'status', 'SUSPENDED', 'ORGANIZATION_NOT_ALLOWED'];
        yield 'client disabled' => ['client', 'enabled', false, 'INTEGRATION_NOT_ALLOWED'];
        yield 'source draft' => ['source', 'status', 'DRAFT', 'SOURCE_NOT_ALLOWED'];
        yield 'source retired' => ['source', 'status', 'RETIRED', 'SOURCE_NOT_ALLOWED'];
        foreach (['client' => 'INTEGRATION_NOT_ALLOWED', 'source' => 'SOURCE_NOT_ALLOWED'] as $target => $reason) {
            yield $target.' before start' => [$target, 'effective_from', '2026-08-31 12:00:01', $reason];
            yield $target.' at expiry' => [$target, 'effective_until', '2026-08-31 12:00:00', $reason];
            yield $target.' after expiry' => [$target, 'effective_until', '2026-08-30 12:00:00', $reason];
        }
        yield 'package disabled' => ['package', 'is_active', false, 'PACKAGE_NOT_ALLOWED'];
        yield 'package missing' => ['package', 'id', null, 'PACKAGE_NOT_ALLOWED'];
        yield 'package not whitelisted' => ['package', 'code', 'OTHER', 'PACKAGE_NOT_ALLOWED'];
        yield 'empty package allow-list' => ['source', 'allowed_assessment_packages', [], 'PACKAGE_NOT_ALLOWED'];
        yield 'null package allow-list' => ['source', 'allowed_assessment_packages', null, 'PACKAGE_NOT_ALLOWED'];
        yield 'package map is not a list' => ['source', 'allowed_assessment_packages', ['code' => 'SYNTHETIC'], 'PACKAGE_NOT_ALLOWED'];
        foreach (['organization', 'source'] as $target) {
            yield $target.' unconfigured' => [$target, 'allowed_payer_types', null, 'PAYER_POLICY_UNCONFIGURED'];
            yield $target.' all off' => [$target, 'allowed_payer_types', [], 'PAYER_NOT_ALLOWED'];
            foreach (['legacy' => ['SPONSORED'], 'mixed unknown' => ['self', 'credit'], 'map' => ['mode' => 'self'], 'scalar' => 'self', 'nested' => [['self']], 'numeric' => [1]] as $label => $value) {
                yield $target.' '.$label => [$target, 'allowed_payer_types', $value, 'PAYER_POLICY_INVALID'];
            }
        }
        yield 'unknown lock' => ['source', 'locked_payer_type', 'SPONSORED', 'PAYER_POLICY_INVALID'];
        yield 'empty lock' => ['source', 'locked_payer_type', '', 'PAYER_POLICY_INVALID'];
    }

    #[DataProvider('invalidContexts')]
    public function test_invalid_contexts_fail_closed(string $target, string $attribute, mixed $value, string $reason): void
    {
        $this->{$target}->setAttribute($attribute, $value);
        $this->assertDenied($this->resolve('organization'), $reason);
    }

    public function test_lock_cannot_reenable_a_payer_disabled_by_the_organization(): void
    {
        $this->organization->allowed_payer_types = ['self'];
        $this->source->locked_payer_type = 'organization';
        $this->assertDenied($this->resolve(), 'PAYER_POLICY_INVALID');
    }

    public function test_source_cannot_extend_organization_permissions_and_vice_versa(): void
    {
        $this->organization->allowed_payer_types = ['self'];
        $this->assertDenied($this->resolve('organization'), 'PAYER_NOT_ALLOWED');
        $this->organization->allowed_payer_types = ['self', 'organization'];
        $this->source->allowed_payer_types = ['self'];
        $this->assertDenied($this->resolve('organization'), 'PAYER_NOT_ALLOWED');
        $this->organization->allowed_payer_types = ['organization'];
        $this->assertDenied($this->resolve(), 'PAYER_NOT_ALLOWED');
    }

    public function test_start_is_inclusive_and_expiry_exclusive_for_client_and_source(): void
    {
        foreach ([$this->client, $this->source] as $record) {
            $record->effective_from = '2026-08-31 12:00:00';
            $record->effective_until = '2026-08-31 12:00:01';
        }
        $this->assertNull($this->resolve('self')->rejectionReason);
    }

    public function test_choices_have_stable_order_and_no_duplicates(): void
    {
        $this->organization->allowed_payer_types = ['organization', 'self', 'self'];
        $this->source->allowed_payer_types = ['organization', 'organization', 'self'];
        $this->assertSame([PayerType::SelfPay, PayerType::Organization], $this->resolve()->allowedPayerTypes);
    }

    private function resolve(?string $requested = null): PayerDecision
    {
        return (new ResolvePayerPolicy)->resolve(
            $this->organization, $this->client, $this->source, $this->package,
            CarbonImmutable::parse('2026-08-31 12:00:00', 'UTC'), $requested,
        );
    }

    private function assertDenied(PayerDecision $decision, string $reason): void
    {
        $this->assertSame($reason, $decision->rejectionReason);
        $this->assertSame([], $decision->allowedPayerTypes);
        $this->assertNull($decision->selectedPayerType);
        $this->assertNull($decision->lockedPayerType);
        $this->assertNull($decision->payerOrganizationId);
        $this->assertFalse($decision->requiresSelection());
    }
}
