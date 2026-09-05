<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Actions\Integrations\CheckoutSessionLifecycle;
use App\Actions\Integrations\CoordinateCheckoutSelfPayment;
use App\Actions\Integrations\EstablishCheckoutSession;
use App\Actions\Integrations\IssueCheckoutHandoff;
use App\Actions\Integrations\PrepareCheckoutSelfPayment;
use App\Data\Integrations\CheckoutHandoffIssueInput;
use App\Data\Integrations\CheckoutSelfPaymentClaimResult;
use App\Data\Integrations\CheckoutSelfPaymentPreparation;
use App\Data\Integrations\CheckoutSessionExchangeInput;
use App\Data\Integrations\CheckoutSessionMutationCredentials;
use App\Enums\CheckoutHandoffIntent;
use App\Models\AssessmentBill;
use App\Models\CheckoutSession;
use App\Models\IntegrationClient;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use DomainException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;

final class CheckoutSelfPaymentPreparationTest extends OrganizationPaymentTestCase
{
    use DatabaseTruncation;

    private int $method;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('assessment_integration.checkout_handoff.enabled', true);
        config()->set('assessment_integration.checkout_handoff.ttl_seconds', 600);
        config()->set('assessment_integration.checkout_session', [
            'enabled' => true, 'idle_minutes' => 30, 'absolute_minutes' => 120,
            'terminal_retention_days' => 30,
        ]);
        config()->set('assessment_integration.checkout.enabled', true);
        $this->method = DB::table('payment_methods')->insertGetId([
            'code' => 'xendit', 'display_name' => 'Synthetic Xendit', 'is_active' => true,
        ]);
    }

    public function test_positive_self_payment_creates_once_and_exact_replay_returns_same_preparation(): void
    {
        $fixture = $this->established();
        $first = $this->prepare($fixture, true);
        DB::table('packages')->where('id', $fixture['package'])->update(['amount' => 999]);
        DB::table('payment_methods')->where('id', $this->method)->update(['is_active' => false]);
        $second = $this->prepare($fixture, true);

        $this->assertInstanceOf(CheckoutSelfPaymentPreparation::class, $first);
        $this->assertSame($first->billId, $second->billId);
        $this->assertSame($fixture['organization'], $first->organizationId);
        $this->assertSame('reserved', $first->status);
        $this->assertTrue($first->created);
        $this->assertFalse($second->created);
        $this->assertDatabaseCount('assessment_bills', 1);
        $this->assertDatabaseCount('assessment_charges', 1);
        $this->assertDatabaseCount('assessment_bill_items', 1);
        $this->assertSame(130, (int) DB::table('assessment_bills')->value('amount'));
        $this->assertSame('checkout-self-v1:'.$fixture['attemptPublicId'], DB::table('assessment_bills')->value('idempotency_key'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.reserved')->count());
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public function test_changed_consultation_conflicts_without_a_second_bill(): void
    {
        $fixture = $this->established();
        $this->prepare($fixture, false);

        $this->assertUnavailable(fn () => $this->prepare($fixture, true));
        $this->assertDatabaseCount('assessment_bills', 1);
        $this->assertDatabaseCount('assessment_charges', 1);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.reserved')->count());
    }

    public function test_organization_and_zero_total_never_create_payment_rows(): void
    {
        $organization = $this->established('INVOICED_TO_ORGANIZATION', 100);
        $zero = $this->established('COMMERCIAL_SELF_PAY', 0);

        $this->assertUnavailable(fn () => $this->prepare($organization, false));
        $this->assertUnavailable(fn () => $this->prepare($zero, false));
        $this->assertDatabaseCount('assessment_bills', 0);
        $this->assertDatabaseCount('assessment_charges', 0);
        $this->assertDatabaseCount('assessment_bill_items', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    #[DataProvider('staleAuthorityChanges')]
    public function test_stale_hydrated_authority_is_denied_before_reservation(string $change): void
    {
        $fixture = $this->established();
        $snapshot = app(CheckoutSessionLifecycle::class)->hydrateWithCsrfDelivery($this->credentials($fixture));
        $this->assertSame($fixture['attempt'], $snapshot->assessmentParticipantId);
        $this->changeAuthority($change, $fixture);

        $this->assertUnavailable(fn () => $this->prepare($fixture, false));
        $this->assertDatabaseCount('assessment_bills', 0);
        $this->assertDatabaseCount('assessment_charges', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame(0, DB::transactionLevel());
    }

    public static function staleAuthorityChanges(): iterable
    {
        yield 'session revoked' => ['session'];
        yield 'recovery generation' => ['recovery'];
        yield 'payer policy changed' => ['policy'];
        yield 'client disabled' => ['client'];
        yield 'source disabled' => ['source'];
        yield 'package disabled' => ['package'];
        yield 'participant deleted' => ['participant'];
        yield 'attempt revoked' => ['revoked'];
        yield 'attempt finalized' => ['finalized'];
    }

    public function test_active_exact_xendit_method_is_required(): void
    {
        $fixture = $this->established();
        DB::table('payment_methods')->where('id', $this->method)->update(['is_active' => false]);
        $this->assertUnavailable(fn () => $this->prepare($fixture, false));
        DB::table('payment_methods')->where('id', $this->method)->update([
            'is_active' => true, 'code' => 'manual_transfer',
        ]);
        $this->assertUnavailable(fn () => $this->prepare($fixture, false));
        $this->assertDatabaseCount('assessment_bills', 0);
    }

    public function test_action_rejects_ambient_context_or_transaction_without_partial_rows(): void
    {
        $fixture = $this->established();
        foreach ([
            fn () => app(RlsContextRunner::class)->runAsService(fn () => $this->prepare($fixture, false)),
            fn () => DB::transaction(fn () => $this->prepare($fixture, false)),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Ambient payment preparation was accepted.');
            } catch (\LogicException $exception) {
                $this->assertSame('Checkout payment preparation owns its service transaction.', $exception->getMessage());
            }
        }
        $this->assertDatabaseCount('assessment_bills', 0);
        $this->assertDatabaseCount('assessment_charges', 0);
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame(0, DB::transactionLevel());
    }

    #[DataProvider('acceptedExistingStates')]
    public function test_canonical_existing_states_replay_even_after_xendit_is_disabled(string $status): void
    {
        $fixture = $this->established();
        $created = $this->prepare($fixture, false);
        $bill = AssessmentBill::query()->findOrFail($created->billId);
        if ($status === 'pending') {
            $bill->update([
                'status' => 'pending', 'gateway_ref' => 'synthetic-provider-ref',
                'invoice_url' => 'https://invoice.xendit.co/synthetic', 'expires_at' => now()->addDay(),
            ]);
        } elseif ($status === 'paid') {
            $paidAt = now()->startOfSecond();
            $bill->update([
                'status' => 'paid', 'gateway_ref' => 'synthetic-provider-ref',
                'invoice_url' => 'https://invoice.xendit.co/synthetic', 'expires_at' => now()->addDay(),
                'paid_at' => $paidAt,
            ]);
            DB::table('assessment_bill_items')->where('bill_id', $bill->id)->update(['settled_at' => $paidAt]);
            DB::table('audit_logs')->insert([
                'branch_id' => $fixture['organization'], 'actor_type' => 'system', 'actor_id' => 'payment-provider',
                'action' => 'assessment_bill.paid', 'subject_type' => AssessmentBill::class,
                'subject_id' => (string) $bill->id, 'context' => '{}', 'occurred_at' => $paidAt,
                'expires_at' => $paidAt->copy()->addYears(2),
            ]);
        }
        DB::table('payment_methods')->where('id', $this->method)->update(['is_active' => false]);

        $replay = $this->prepare($fixture, false);
        $this->assertSame($bill->id, $replay->billId);
        $this->assertSame($status, $replay->status);
        $this->assertFalse($replay->created);
        $this->assertDatabaseCount('assessment_bills', 1);
        $this->assertDatabaseCount('assessment_charges', 1);
        $this->assertDatabaseCount('assessment_bill_items', 1);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public static function acceptedExistingStates(): iterable
    {
        yield ['reserved'];
        yield ['pending'];
        yield ['paid'];
    }

    #[DataProvider('blockedExistingStates')]
    public function test_recovery_and_terminal_existing_states_fail_closed(string $status): void
    {
        $fixture = $this->established();
        $created = $this->prepare($fixture, false);
        AssessmentBill::query()->whereKey($created->billId)->update(['status' => $status]);

        $this->assertUnavailable(fn () => $this->prepare($fixture, false));
        $this->assertDatabaseCount('assessment_bills', 1);
        $this->assertDatabaseCount('assessment_charges', 1);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public static function blockedExistingStates(): iterable
    {
        yield 'unknown' => ['unknown'];
        yield 'expired' => ['expired'];
        yield 'rejected' => ['rejected'];
    }

    #[DataProvider('corruptExistingChanges')]
    public function test_corrupt_existing_bill_fails_closed_without_replacement(string $change): void
    {
        $fixture = $this->established();
        $created = $this->prepare($fixture, false);
        $this->corruptExisting($change, $created->billId);

        $this->assertUnavailable(fn () => $this->prepare($fixture, false));
        $this->assertDatabaseCount('assessment_bills', 1);
        $this->assertDatabaseCount('assessment_charges', 1);
        $this->assertDatabaseCount('assessment_bill_items', 1);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public static function corruptExistingChanges(): iterable
    {
        yield 'bill total' => ['bill-total'];
        yield 'request hash' => ['request-hash'];
        yield 'item state' => ['item-state'];
        yield 'price snapshot' => ['snapshot'];
        yield 'payment method' => ['method'];
    }

    public function test_unexpected_reservation_failure_rolls_back_every_payment_row_and_context(): void
    {
        $fixture = $this->established();
        $auditCount = DB::table('audit_logs')->count();
        DB::listen(function (QueryExecuted $query): void {
            if (str_contains($query->sql, 'insert into "audit_logs"')) {
                throw new RuntimeException('synthetic-reservation-failure');
            }
        });

        try {
            $this->prepare($fixture, false);
            $this->fail('Synthetic failure did not run.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic-reservation-failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('assessment_bills', 0);
        $this->assertDatabaseCount('assessment_charges', 0);
        $this->assertDatabaseCount('assessment_bill_items', 0);
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
        $this->assertDatabaseCount('outbox_messages', 0);
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_claim_runs_after_preparation_commit_and_replays_one_canonical_message(): void
    {
        $fixture = $this->established();
        $preparationCommitted = false;
        DB::listen(function (QueryExecuted $query) use (&$preparationCommitted): void {
            if (str_contains($query->sql, 'insert into "audit_logs"')
                && in_array('assessment_bill.reserved', $query->bindings, true)) {
                DB::afterCommit(function () use (&$preparationCommitted): void {
                    $preparationCommitted = true;
                });
            }
            if (str_contains($query->sql, 'insert into "outbox_messages"')) {
                $this->assertTrue($preparationCommitted, 'Claim began before preparation committed.');
            }
        });

        $first = $this->coordinate($fixture, false);
        $second = $this->coordinate($fixture, false);
        $this->assertInstanceOf(CheckoutSelfPaymentClaimResult::class, $first);
        $this->assertSame('issuance_required', $first->state);
        $this->assertNotNull($first->messageId);
        $this->assertTrue(Str::isUlid($first->messageId));
        $this->assertSame($first->messageId, $second->messageId);
        $this->assertSame('issuing', DB::table('assessment_bills')->value('status'));
        $this->assertDatabaseCount('assessment_bills', 1);
        $this->assertDatabaseCount('assessment_charges', 1);
        $this->assertDatabaseCount('assessment_bill_items', 1);
        $this->assertDatabaseCount('outbox_messages', 1);
        $this->assertDatabaseHas('outbox_messages', [
            'message_id' => $first->messageId, 'topic' => 'assessment.bill.invoice-issuance',
            'status' => 'pending', 'attempts' => 0, 'processed_at' => null,
        ]);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.invoice_claimed')->count());
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame(0, DB::transactionLevel());
    }

    #[DataProvider('claimBypassStates')]
    public function test_pending_and_paid_bypass_claim_even_when_xendit_is_inactive(string $status): void
    {
        $fixture = $this->established();
        $prepared = $this->prepare($fixture, false);
        $bill = AssessmentBill::query()->findOrFail($prepared->billId);
        $attributes = [
            'status' => $status, 'gateway_ref' => 'synthetic-provider-ref',
            'invoice_url' => 'https://invoice.xendit.co/synthetic', 'expires_at' => now()->addDay(),
        ];
        if ($status === 'paid') {
            $paidAt = now()->startOfSecond();
            $attributes['paid_at'] = $paidAt;
            DB::table('assessment_bill_items')->where('bill_id', $bill->id)->update(['settled_at' => $paidAt]);
            DB::table('audit_logs')->insert([
                'branch_id' => $fixture['organization'], 'actor_type' => 'system', 'actor_id' => 'payment-provider',
                'action' => 'assessment_bill.paid', 'subject_type' => AssessmentBill::class,
                'subject_id' => (string) $bill->id, 'context' => '{}', 'occurred_at' => $paidAt,
                'expires_at' => $paidAt->copy()->addYears(2),
            ]);
        }
        $bill->update($attributes);
        DB::table('payment_methods')->where('id', $this->method)->update(['is_active' => false]);

        $result = $this->coordinate($fixture, false);
        $this->assertSame($status, $result->state);
        $this->assertNull($result->messageId);
        $this->assertDatabaseCount('outbox_messages', 0);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'assessment_bill.invoice_claimed')->count());
    }

    public static function claimBypassStates(): iterable
    {
        yield ['pending'];
        yield ['paid'];
    }

    #[DataProvider('claimUnavailableChanges')]
    public function test_recovery_terminal_corrupt_and_stale_claims_fail_generic(string $change): void
    {
        $fixture = $this->established();
        if ($change === 'recovery-required') {
            $claimed = $this->coordinate($fixture, false);
            DB::table('outbox_messages')->where('message_id', $claimed->messageId)->update([
                'status' => 'processing', 'attempts' => 1,
            ]);
        } elseif (in_array($change, ['expired', 'corrupt'], true)) {
            $prepared = $this->prepare($fixture, false);
            AssessmentBill::query()->whereKey($prepared->billId)->update($change === 'expired'
                ? ['status' => 'expired'] : ['request_hash' => str_repeat('f', 64)]);
        } elseif ($change === 'policy') {
            app(CheckoutSessionLifecycle::class)->hydrateWithCsrfDelivery($this->credentials($fixture));
            $this->changeAuthority('policy', $fixture);
        } else {
            app(CheckoutSessionLifecycle::class)->hydrateWithCsrfDelivery($this->credentials($fixture));
            $this->changeAuthority('session', $fixture);
        }

        $this->assertUnavailable(fn () => $this->coordinate($fixture, false));
        $this->assertSame(in_array($change, ['policy', 'session'], true) ? 0 : 1,
            DB::table('assessment_bills')->count());
        $this->assertLessThanOrEqual(1, DB::table('outbox_messages')->count());
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame(0, DB::transactionLevel());
    }

    public static function claimUnavailableChanges(): iterable
    {
        yield ['recovery-required'];
        yield ['expired'];
        yield ['corrupt'];
        yield ['policy'];
        yield ['session'];
    }

    public function test_claim_audit_failure_rolls_back_to_reserved_then_retry_claims_once(): void
    {
        $fixture = $this->established();
        $fail = true;
        DB::listen(function (QueryExecuted $query) use (&$fail): void {
            if ($fail && str_contains($query->sql, 'insert into "audit_logs"')
                && in_array('assessment_bill.invoice_claimed', $query->bindings, true)) {
                $fail = false;
                throw new RuntimeException('synthetic-claim-audit-failure');
            }
        });
        try {
            $this->coordinate($fixture, false);
            $this->fail('Synthetic claim failure did not run.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic-claim-audit-failure', $exception->getMessage());
        }
        $this->assertDatabaseHas('assessment_bills', ['status' => 'reserved']);
        $this->assertDatabaseCount('assessment_bills', 1);
        $this->assertDatabaseCount('assessment_charges', 1);
        $this->assertDatabaseCount('outbox_messages', 0);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'assessment_bill.invoice_claimed')->count());
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame(0, DB::transactionLevel());

        $result = $this->coordinate($fixture, false);
        $this->assertSame('issuance_required', $result->state);
        $this->assertDatabaseCount('outbox_messages', 1);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'assessment_bill.invoice_claimed')->count());
    }

    public function test_claim_coordinator_rejects_ambient_context_or_transaction(): void
    {
        $fixture = $this->established();
        foreach ([
            fn () => app(RlsContextRunner::class)->runAsService(fn () => $this->coordinate($fixture, false)),
            fn () => DB::transaction(fn () => $this->coordinate($fixture, false)),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Ambient claim coordination was accepted.');
            } catch (\LogicException $exception) {
                $this->assertSame('Checkout payment claim owns an empty context and transaction.', $exception->getMessage());
            }
        }
        $this->assertDatabaseCount('assessment_bills', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame(0, DB::transactionLevel());
    }

    /** @param array<string, mixed> $fixture */
    private function prepare(array $fixture, bool $consultation): CheckoutSelfPaymentPreparation
    {
        return app(PrepareCheckoutSelfPayment::class)->execute($this->credentials($fixture), $consultation);
    }

    /** @param array<string, mixed> $fixture */
    private function coordinate(array $fixture, bool $consultation): CheckoutSelfPaymentClaimResult
    {
        return app(CoordinateCheckoutSelfPayment::class)->execute($this->credentials($fixture), $consultation);
    }

    private function assertUnavailable(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Unavailable checkout payment was prepared.');
        } catch (DomainException $exception) {
            $this->assertSame('CHECKOUT_PAYMENT_UNAVAILABLE', $exception->getMessage());
        }
    }

    /** @param array<string, mixed> $fixture */
    private function credentials(array $fixture): CheckoutSessionMutationCredentials
    {
        return new CheckoutSessionMutationCredentials($fixture['selector'], $fixture['csrf']);
    }

    /** @param array<string, mixed> $fixture */
    private function changeAuthority(string $change, array $fixture): void
    {
        match ($change) {
            'session' => DB::table('checkout_sessions')->where('id', $fixture['session'])->update([
                'status' => 'REVOKED', 'active_marker' => null, 'revoked_at' => now(),
                'revocation_reason' => 'REPLACED', 'updated_at' => now(),
            ]),
            'recovery' => app(RlsContextRunner::class)->runAsService(fn () => app(IssueCheckoutHandoff::class)
                ->execute(new CheckoutHandoffIssueInput(IntegrationClient::query()->findOrFail($fixture['client']),
                    $fixture['attemptPublicId'], $fixture['sourceSystem'], 'ih1_'.bin2hex(random_bytes(16)),
                    CheckoutHandoffIntent::Recovery))),
            'policy' => DB::table('branches')->where('id', $fixture['organization'])->update([
                'allowed_payer_types' => json_encode(['organization'], JSON_THROW_ON_ERROR),
            ]),
            'client' => DB::table('integration_clients')->where('id', $fixture['client'])->update(['enabled' => false]),
            'source' => DB::table('integration_sources')->where('id', $fixture['source'])->update(['status' => 'INACTIVE']),
            'package' => DB::table('packages')->where('id', $fixture['package'])->update(['is_active' => false]),
            'participant' => DB::table('participants')->where('id', $fixture['participant'])->update(['deleted_at' => now()]),
            'revoked' => DB::table('assessment_participants')->where('id', $fixture['attempt'])->update([
                'assessment_status' => 'REVOKED', 'revoked_at' => now(),
            ]),
            'finalized' => DB::table('assessment_participants')->where('id', $fixture['attempt'])->update([
                'assessment_status' => 'COMPLETED', 'finalized_at' => now(),
            ]),
            default => throw new RuntimeException('Unknown synthetic authority change.'),
        };
    }

    private function corruptExisting(string $change, int $billId): void
    {
        $chargeId = (int) DB::table('assessment_bill_items')->where('bill_id', $billId)->value('charge_id');
        match ($change) {
            'bill-total' => DB::table('assessment_bills')->where('id', $billId)->update(['amount' => 101]),
            'request-hash' => DB::table('assessment_bills')->where('id', $billId)->update([
                'request_hash' => str_repeat('f', 64),
            ]),
            'item-state' => DB::table('assessment_bill_items')->where('bill_id', $billId)->update(['settled_at' => now()]),
            'snapshot' => DB::table('assessment_charges')->where('id', $chargeId)->update([
                'price_snapshot' => json_encode(['version' => 1], JSON_THROW_ON_ERROR),
            ]),
            'method' => DB::table('payment_methods')->where('id', $this->method)->update(['code' => 'manual_transfer']),
            default => throw new RuntimeException('Unknown synthetic corruption.'),
        };
    }

    /** @return array<string, int|string> */
    private function established(string $funding = 'COMMERCIAL_SELF_PAY', int $amount = 100): array
    {
        $key = (string) Str::ulid();
        $sourceSystem = 'PAYPREP_'.$key;
        $packageCode = 'PP'.$key;
        $payer = $funding === 'COMMERCIAL_SELF_PAY' ? 'self' : 'organization';
        $organization = DB::table('branches')->insertGetId([
            'code' => $key, 'ref_code' => $key, 'name' => 'Synthetic', 'organization_code' => $key,
            'display_name' => 'Synthetic', 'status' => 'ACTIVE', 'is_active' => true,
            'allowed_payer_types' => json_encode([$payer], JSON_THROW_ON_ERROR),
        ]);
        $participant = DB::table('participants')->insertGetId([
            'branch_id' => $organization, 'referral_branch_id' => $organization,
            'referral_source' => 'manual', 'source_system' => $sourceSystem,
            'full_name' => 'Synthetic Person', 'phone' => '620000000000',
        ]);
        $client = DB::table('integration_clients')->insertGetId([
            'organization_id' => $organization, 'client_id' => $key,
            'credential_reference' => 'synthetic-only', 'enabled' => true,
        ]);
        $source = DB::table('integration_sources')->insertGetId([
            'integration_client_id' => $client, 'source_system' => $sourceSystem,
            'contract_version' => 'checkout-v2',
            'allowed_assessment_packages' => json_encode([$packageCode], JSON_THROW_ON_ERROR),
            'allowed_funding_modes' => '[]', 'allowed_payer_types' => json_encode([$payer], JSON_THROW_ON_ERROR),
            'status' => 'ACTIVE',
        ]);
        $package = DB::table('packages')->insertGetId([
            'code' => $packageCode, 'name' => 'Synthetic', 'amount' => $amount,
            'consultation_amount' => 30, 'currency' => 'IDR', 'is_active' => true,
        ]);
        DB::table('package_items')->insert(['package_id' => $package, 'test_type' => 'ist', 'sort_order' => 1]);
        $attemptPublicId = (string) Str::ulid();
        $attempt = DB::table('assessment_participants')->insertGetId([
            'organization_id' => $organization, 'integration_client_id' => $client,
            'participant_id' => $participant, 'package_id' => $package,
            'assessment_attempt_id' => $attemptPublicId, 'source_system' => $sourceSystem,
            'external_candidate_id' => $key, 'funding_mode' => $funding,
            'assessment_status' => 'PROVISIONED', 'idempotency_key' => $key,
            'request_hash' => hash('sha256', $key), 'logical_assessment_key' => hash('sha256', 'logical'.$key),
            'metadata' => json_encode([
                'checkout_contract_version' => 'checkout-v2', 'checkout_initial_funding_mode' => $funding,
            ], JSON_THROW_ON_ERROR),
        ]);
        $issued = app(RlsContextRunner::class)->run(new RlsContext('service'), fn () => app(IssueCheckoutHandoff::class)
            ->execute(new CheckoutHandoffIssueInput(IntegrationClient::query()->findOrFail($client),
                $attemptPublicId, $sourceSystem, 'ih1_'.bin2hex(random_bytes(16)), CheckoutHandoffIntent::Issue)));
        $raw = $issued->rawToken();
        if (! is_string($raw)) {
            $this->fail('Synthetic handoff unavailable.');
        }
        $result = app(EstablishCheckoutSession::class)->execute(new CheckoutSessionExchangeInput($raw));
        $session = CheckoutSession::query()->where('public_id', $result->sessionPublicId)->value('id');
        if (! is_int($session)) {
            $this->fail('Synthetic session unavailable.');
        }

        return compact('organization', 'participant', 'client', 'source', 'package', 'attempt',
            'attemptPublicId', 'sourceSystem', 'session') + [
                'selector' => $result->rawSelector(), 'csrf' => $result->rawCsrfToken(),
            ];
    }
}
