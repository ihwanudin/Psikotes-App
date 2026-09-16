<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Actions\Payments\ClaimAssessmentBillInvoice;
use App\Actions\Payments\IssueAssessmentBillInvoice;
use App\Actions\Payments\PreviewAssessmentBill;
use App\Actions\Payments\ReserveAssessmentBill;
use App\Actions\Payments\ReserveAssessmentInvoiceReconciliationHints;
use App\Actions\Payments\ValidateAssessmentInvoiceReconciliationLease;
use App\Data\Payments\AssessmentInvoiceReconciliationPermit;
use App\Data\Payments\ProvisionalAssessmentInvoiceLease;
use App\Enums\AdminRole;
use App\Enums\PayerType;
use App\Models\Admin;
use App\Models\AssessmentBill;
use App\Models\OutboxMessage;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentPreviewFixture as Fixture;

final class AssessmentInvoiceReconciliationLeaseValidationTest extends OrganizationPaymentTestCase
{
    use DatabaseTruncation;

    /** @var array{organization: int, participant: int, package: int, attempt: int, charge: int, bill: int, payer: string, source: int, client: int} */
    private array $fixture;

    private AssessmentBill $bill;

    private OutboxMessage $intent;

    private int $method;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::setUp();
        Date::setTestNow('2026-09-01T00:00:00Z');
        Http::preventStrayRequests();
        Http::fake([]);
        Bus::fake();
        Queue::fake();
        config()->set('assessment_integration.checkout.enabled', true);
        config()->set('assessment_billing.invoice_reconciliation_batch_size', 25);
        config()->set('assessment_billing.invoice_reconciliation_scan_limit', 100);
        config()->set('assessment_billing.invoice_reconciliation_lease_seconds', 60);
        config()->set('assessment_billing.invoice_reconciliation_cooldown_seconds', 300);
        config()->set('assessment_billing.invoice_reconciliation_max_lookups', 12);
        $this->fixture = $this->createFixture();
        DB::table('assessment_participants')->where('id', $this->fixture['attempt'])->update([
            'funding_mode' => 'INVOICED_TO_ORGANIZATION',
            'metadata' => '{"checkout_contract_version":"checkout-v2","checkout_initial_funding_mode":null}',
        ]);
        $admin = Admin::create(['branch_id' => $this->fixture['organization'], 'name' => 'Synthetic lease validator',
            'email' => 'lease-validator@example.test', 'password' => 'synthetic', 'role' => AdminRole::BranchAdmin]);
        $this->method = DB::table('payment_methods')->insertGetId([
            'code' => 'xendit', 'display_name' => 'Synthetic', 'is_active' => true,
        ]);
        app(RlsContextRunner::class)->runAsService(function () use ($admin): void {
            $selection = [['assessmentParticipantId' => $this->fixture['attempt'], 'consultationRequested' => false]];
            $preview = app(PreviewAssessmentBill::class)->execute($this->fixture['organization'], $selection, PayerType::Organization);
            $this->bill = app(ReserveAssessmentBill::class)->execute(
                $admin, $selection, $this->method, $preview['selectionHash'], 'lease-validation-one',
            );
            app(ClaimAssessmentBillInvoice::class)->execute($this->fixture['organization'], $this->bill->id);
            $this->intent = OutboxMessage::query()->where('aggregate_id', (string) $this->bill->id)->sole();
        });
        $this->assertNotNull(app(IssueAssessmentBillInvoice::class)->consume($this->intent->message_id));
    }

    protected function tearDown(): void
    {
        try {
            Http::assertNothingSent();
            Bus::assertNothingDispatched();
            Queue::assertNothingPushed();
            $this->assertNull(app(RlsContextRunner::class)->current());
            $this->assertSame(0, DB::transactionLevel());
        } finally {
            RefreshDatabaseState::$migrated = false;
            parent::tearDown();
        }
    }

    #[DataProvider('canonicalStates')]
    public function test_validates_canonical_states_rotates_token_refreshes_expiry_and_increments_generation(string $state): void
    {
        if ($state === 'unknown') {
            $this->unknownState();
        }
        config()->set('assessment_billing.invoice_reconciliation_lease_seconds', 30);
        $provisional = $this->reserve();
        config()->set('assessment_billing.invoice_reconciliation_lease_seconds', 60);
        $before = $this->businessState();
        $audit = DB::table('audit_logs')->count();

        $permit = app(ValidateAssessmentInvoiceReconciliationLease::class)->execute($provisional);

        $this->assertInstanceOf(AssessmentInvoiceReconciliationPermit::class, $permit);
        $this->assertSame($this->intent->message_id, $permit->invoice->messageId);
        $this->assertSame($this->bill->public_reference, $permit->invoice->merchantReference);
        $this->assertSame(100, $permit->invoice->amount);
        $this->assertSame('IDR', $permit->invoice->currency);
        $this->assertSame(1, $permit->lookupGeneration);
        $this->assertTrue(Str::isUuid($permit->leaseToken));
        $this->assertNotSame($provisional->leaseToken, $permit->leaseToken);
        $this->assertTrue($permit->leaseExpiresAt->greaterThan($provisional->leaseExpiresAt));
        $fresh = $this->intent->fresh();
        $this->assertSame($permit->leaseToken, $fresh->reconciliation_lease_token);
        $this->assertTrue($permit->leaseExpiresAt->equalTo($fresh->reconciliation_lease_expires_at));
        $this->assertSame(1, $fresh->reconciliation_lookup_attempts);
        $this->assertSame($before, $this->businessState());
        $this->assertSame($audit, DB::table('audit_logs')->count());
    }

    /** @return iterable<string, array{string}> */
    public static function canonicalStates(): iterable
    {
        yield 'issuing processing' => ['processing'];
        yield 'unknown failed' => ['unknown'];
    }

    public function test_replayed_provisional_token_returns_null_without_increment_or_mutation(): void
    {
        $provisional = $this->reserve();
        $permit = app(ValidateAssessmentInvoiceReconciliationLease::class)->execute($provisional);
        $this->assertNotNull($permit);
        $after = $this->intent->fresh()->getAttributes();
        $audit = DB::table('audit_logs')->count();

        $this->assertNull(app(ValidateAssessmentInvoiceReconciliationLease::class)->execute($provisional));
        $this->assertSame($after, $this->intent->fresh()->getAttributes());
        $this->assertSame(1, $this->intent->fresh()->reconciliation_lookup_attempts);
        $this->assertSame($audit, DB::table('audit_logs')->count());
    }

    #[DataProvider('leaseFenceCases')]
    public function test_lost_expired_or_stolen_provisional_fails_token_fenced(string $case): void
    {
        $provisional = $this->reserve();
        $ownerToken = (string) Str::uuid();
        if ($case === 'lost') {
            DB::table('outbox_messages')->where('id', $this->intent->id)->update([
                'reconciliation_lease_token' => null, 'reconciliation_lease_expires_at' => null,
            ]);
        } elseif ($case === 'expired') {
            $expiry = CarbonImmutable::now()->subMinute();
            $this->intent->forceFill(['reconciliation_lease_expires_at' => $expiry])->save();
            $provisional = new ProvisionalAssessmentInvoiceLease(
                $provisional->messageId, $provisional->leaseToken, $expiry,
            );
        } else {
            $this->intent->forceFill(['reconciliation_lease_token' => $ownerToken])->save();
        }

        $this->assertNull(app(ValidateAssessmentInvoiceReconciliationLease::class)->execute($provisional));
        $fresh = $this->intent->fresh();
        $this->assertSame(0, $fresh->reconciliation_lookup_attempts);
        if ($case === 'stolen') {
            $this->assertSame($ownerToken, $fresh->reconciliation_lease_token);
        } else {
            $this->assertNull($fresh->reconciliation_lease_token);
            $this->assertNull($fresh->reconciliation_lease_expires_at);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function leaseFenceCases(): iterable
    {
        yield 'lost' => ['lost'];
        yield 'expired' => ['expired'];
        yield 'stolen' => ['stolen'];
    }

    #[DataProvider('invalidCandidates')]
    public function test_current_invalid_candidate_rolls_back_then_clears_only_provisional_metadata(string $case): void
    {
        $provisional = $this->reserve();
        match ($case) {
            'policy-off' => DB::table('branches')->where('id', $this->fixture['organization'])
                ->update(['allowed_payer_types' => '["self"]']),
            'channel-off' => DB::table('payment_methods')->where('id', $this->method)->update(['is_active' => false]),
            'revoked' => DB::table('assessment_participants')->where('id', $this->fixture['attempt'])->update(['revoked_at' => now()]),
            'corrupt' => $this->corruptPayload(),
            'foreign' => $this->foreignPayload(),
            'terminal' => $this->bill->update(['status' => 'paid', 'paid_at' => now()]),
            'exhausted' => DB::table('outbox_messages')->where('id', $this->intent->id)
                ->update(['reconciliation_lookup_attempts' => 12]),
            default => throw new RuntimeException('Unknown invalid candidate case.'),
        };
        $before = $this->businessState();
        $audit = DB::table('audit_logs')->count();

        $this->assertNull(app(ValidateAssessmentInvoiceReconciliationLease::class)->execute($provisional));

        $fresh = $this->intent->fresh();
        $this->assertNull($fresh->reconciliation_lease_token);
        $this->assertNull($fresh->reconciliation_lease_expires_at);
        $this->assertSame($case === 'exhausted' ? 12 : 0, $fresh->reconciliation_lookup_attempts);
        $this->assertSame($before, $this->businessState());
        $this->assertSame($audit, DB::table('audit_logs')->count());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCandidates(): iterable
    {
        yield 'current policy off' => ['policy-off'];
        yield 'channel off' => ['channel-off'];
        yield 'attempt revoked' => ['revoked'];
        yield 'payload corrupt' => ['corrupt'];
        yield 'foreign routing hint' => ['foreign'];
        yield 'terminal paid' => ['terminal'];
        yield 'lookup counter exhausted' => ['exhausted'];
    }

    public function test_unexpected_database_failure_propagates_and_leaves_provisional_to_expire(): void
    {
        $provisional = $this->reserve();
        $before = $this->intent->fresh()->getAttributes();
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed): void {
            if ($armed && str_starts_with(strtolower($query->sql), 'update')
                && str_contains($query->sql, 'reconciliation_lookup_attempts')) {
                $armed = false;
                throw new RuntimeException('synthetic-permit-persist-failure');
            }
        });
        try {
            app(ValidateAssessmentInvoiceReconciliationLease::class)->execute($provisional);
            $this->fail('Unexpected database failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic-permit-persist-failure', $exception->getMessage());
        }
        $this->assertFalse($armed);
        $this->assertSame($before, $this->intent->fresh()->getAttributes());
    }

    public function test_rejects_ambient_context_transaction_invalid_identity_and_config(): void
    {
        $provisional = $this->reserve();
        $validator = app(ValidateAssessmentInvoiceReconciliationLease::class);
        try {
            app(RlsContextRunner::class)->run(new RlsContext('service'), fn () => $validator->execute($provisional));
            $this->fail('Ambient context must fail.');
        } catch (LogicException $exception) {
            $this->assertSame('Invoice reconciliation lease validation requires an empty RLS context and no ambient transaction.', $exception->getMessage());
        }
        try {
            DB::transaction(fn () => $validator->execute($provisional));
            $this->fail('Ambient transaction must fail.');
        } catch (LogicException $exception) {
            $this->assertSame('Invoice reconciliation lease validation requires an empty RLS context and no ambient transaction.', $exception->getMessage());
        }
        foreach ([
            new ProvisionalAssessmentInvoiceLease('invalid', $provisional->leaseToken, $provisional->leaseExpiresAt),
            new ProvisionalAssessmentInvoiceLease($provisional->messageId, 'invalid', $provisional->leaseExpiresAt),
        ] as $invalid) {
            try {
                $validator->execute($invalid);
                $this->fail('Invalid persisted identity must fail.');
            } catch (DomainException $exception) {
                $this->assertSame('INVOICE_RECONCILIATION_LEASE_INVALID', $exception->getMessage());
            }
        }
        config()->set('assessment_billing.invoice_reconciliation_max_lookups', 0);
        try {
            $validator->execute($provisional);
            $this->fail('Invalid reconciliation config must fail.');
        } catch (LogicException $exception) {
            $this->assertSame('Invoice reconciliation configuration is invalid.', $exception->getMessage());
        }
        $this->assertSame($provisional->leaseToken, $this->intent->fresh()->reconciliation_lease_token);
        $this->assertSame(0, $this->intent->fresh()->reconciliation_lookup_attempts);
    }

    private function reserve(): ProvisionalAssessmentInvoiceLease
    {
        $leases = app(ReserveAssessmentInvoiceReconciliationHints::class)->execute(1, 1);
        $this->assertCount(1, $leases);

        return $leases[0];
    }

    private function unknownState(): void
    {
        $this->bill->update(['status' => 'unknown']);
        $this->intent->forceFill(['status' => 'failed', 'last_error' => 'INVOICE_OUTCOME_UNKNOWN'])->save();
    }

    /** @return array<string, mixed> */
    private function businessState(): array
    {
        $attributes = $this->intent->fresh()->getAttributes();
        unset($attributes['reconciliation_lease_token'], $attributes['reconciliation_lease_expires_at'],
            $attributes['reconciliation_lookup_attempts']);

        return $attributes;
    }

    private function corruptPayload(): void
    {
        $payload = $this->intent->payload;
        $payload['snapshot']['amount']++;
        $this->intent->forceFill(['payload' => $payload])->save();
    }

    private function foreignPayload(): void
    {
        $payload = $this->intent->payload;
        $payload['snapshot']['organizationId']++;
        $this->intent->forceFill(['payload' => $payload])->save();
    }

    /** @return array{organization: int, participant: int, package: int, attempt: int, charge: int, bill: int, payer: string, source: int, client: int} */
    private function createFixture(): array
    {
        $fixture = Fixture::create();
        foreach (['organization', 'participant', 'package', 'attempt', 'charge', 'bill', 'source', 'client'] as $key) {
            if (! is_int($fixture[$key] ?? null)) {
                throw new RuntimeException('Synthetic billing fixture is invalid.');
            }
        }
        if (! is_string($fixture['payer'] ?? null)) {
            throw new RuntimeException('Synthetic billing fixture payer is invalid.');
        }

        return ['organization' => $fixture['organization'], 'participant' => $fixture['participant'],
            'package' => $fixture['package'], 'attempt' => $fixture['attempt'], 'charge' => $fixture['charge'],
            'bill' => $fixture['bill'], 'payer' => $fixture['payer'], 'source' => $fixture['source'],
            'client' => $fixture['client']];
    }
}
