<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Actions\Payments\ClaimAssessmentBillInvoice;
use App\Actions\Payments\CoordinateAssessmentInvoiceReconciliation;
use App\Actions\Payments\IssueAssessmentBillInvoice;
use App\Actions\Payments\PreviewAssessmentBill;
use App\Actions\Payments\ReserveAssessmentBill;
use App\Contracts\PaymentProvider;
use App\Data\Payments\PaymentInvoice;
use App\Enums\AdminRole;
use App\Enums\PayerType;
use App\Models\Admin;
use App\Models\AssessmentBill;
use App\Models\OutboxMessage;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use App\Services\Payments\Exceptions\PaymentProviderException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentPreviewFixture as Fixture;

final class AssessmentInvoiceReconciliationCoordinatorTest extends OrganizationPaymentTestCase
{
    use DatabaseTruncation;

    private int $method;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake([]);
        Bus::fake();
        Queue::fake();
        config()->set('assessment_integration.checkout.enabled', true);
        config()->set('assessment_billing.invoice_duration_hours', 24);
        config()->set('assessment_billing.invoice_reconciliation_batch_size', 25);
        config()->set('assessment_billing.invoice_reconciliation_scan_limit', 100);
        config()->set('assessment_billing.invoice_reconciliation_lease_seconds', 60);
        config()->set('assessment_billing.invoice_reconciliation_cooldown_seconds', 300);
        config()->set('assessment_billing.invoice_reconciliation_max_lookups', 12);
        $this->method = DB::table('payment_methods')->insertGetId([
            'code' => 'xendit', 'display_name' => 'Synthetic coordinator', 'is_active' => true,
        ]);
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

    public function test_empty_run_returns_deterministic_non_sensitive_summary(): void
    {
        $summary = app(CoordinateAssessmentInvoiceReconciliation::class)->execute();

        $this->assertSame([
            'batchLimit' => 25,
            'scanLimit' => 100,
            'maxLookups' => 12,
            'reserved' => 0,
            'validated' => 0,
            'issued' => 0,
            'unknown' => 0,
            'recoveryRequired' => 0,
        ], $summary);
    }

    public function test_configured_batch_one_processes_only_one_due_hint(): void
    {
        config()->set('assessment_billing.invoice_reconciliation_batch_size', 1);
        config()->set('assessment_billing.invoice_reconciliation_scan_limit', 1);
        $first = $this->prepareInvoice('batch-first');
        $second = $this->prepareInvoice('batch-second');
        $provider = $this->exactProvider([$first]);

        $summary = $this->execute($provider);

        $this->assertSame(1, $summary['batchLimit']);
        $this->assertSame(1, $summary['scanLimit']);
        $this->assertSame(1, $summary['reserved']);
        $this->assertSame(1, $summary['issued']);
        $this->assertSame('processed', $first['intent']->fresh()->status);
        $this->assertSame('processing', $second['intent']->fresh()->status);
        $this->assertNull($second['intent']->fresh()->reconciliation_lease_token);
    }

    public function test_max_lookup_exhaustion_is_skipped_using_canonical_config(): void
    {
        config()->set('assessment_billing.invoice_reconciliation_batch_size', 2);
        config()->set('assessment_billing.invoice_reconciliation_scan_limit', 2);
        config()->set('assessment_billing.invoice_reconciliation_max_lookups', 1);
        $exhausted = $this->prepareInvoice('max-exhausted');
        $eligible = $this->prepareInvoice('max-eligible');
        DB::table('outbox_messages')->where('id', $exhausted['intent']->id)
            ->update(['reconciliation_lookup_attempts' => 1]);

        $summary = $this->execute($this->exactProvider([$eligible]));

        $this->assertSame(1, $summary['maxLookups']);
        $this->assertSame(1, $summary['reserved']);
        $this->assertSame(1, $summary['issued']);
        $this->assertSame('processing', $exhausted['intent']->fresh()->status);
        $this->assertSame(1, $exhausted['intent']->fresh()->reconciliation_lookup_attempts);
        $this->assertNull($exhausted['intent']->fresh()->reconciliation_lease_token);
    }

    public function test_invalid_hint_and_provider_failure_are_isolated_from_other_reserved_hints(): void
    {
        config()->set('assessment_billing.invoice_reconciliation_batch_size', 4);
        config()->set('assessment_billing.invoice_reconciliation_scan_limit', 4);
        $issued = $this->prepareInvoice('mixed-issued');
        $invalid = $this->prepareInvoice('mixed-invalid');
        $unknown = $this->prepareInvoice('mixed-unknown');
        $issuedAfterFailure = $this->prepareInvoice('mixed-issued-after-failure');
        $seen = [];
        $provider = $this->createMock(PaymentProvider::class);
        $provider->expects($this->never())->method('createInvoice');
        $provider->expects($this->exactly(3))->method('lookupInvoice')
            ->willReturnCallback(function (string $reference, int $amount, string $currency) use (
                $issued, $invalid, $unknown, $issuedAfterFailure, &$seen,
            ): PaymentInvoice {
                $seen[] = $reference;
                if ($reference === $issued['bill']->public_reference) {
                    $payload = $invalid['intent']->fresh()->payload;
                    $payload['snapshot']['amount']++;
                    $invalid['intent']->forceFill(['payload' => $payload])->save();

                    return $this->invoice($issued['bill']);
                }
                if ($reference === $unknown['bill']->public_reference) {
                    $this->assertSame($unknown['bill']->amount, $amount);
                    $this->assertSame($unknown['bill']->currency, $currency);
                    throw new PaymentProviderException('Synthetic bounded lookup failure.');
                }
                $this->assertSame($issuedAfterFailure['bill']->public_reference, $reference);

                return $this->invoice($issuedAfterFailure['bill']);
            });

        $summary = $this->execute($provider);

        $this->assertSame([
            'batchLimit' => 4,
            'scanLimit' => 4,
            'maxLookups' => 12,
            'reserved' => 4,
            'validated' => 3,
            'issued' => 2,
            'unknown' => 1,
            'recoveryRequired' => 1,
        ], $summary);
        $this->assertSame([
            $issued['bill']->public_reference,
            $unknown['bill']->public_reference,
            $issuedAfterFailure['bill']->public_reference,
        ], $seen);
        $this->assertSame('processed', $issued['intent']->fresh()->status);
        $this->assertSame('processing', $invalid['intent']->fresh()->status);
        $this->assertNull($invalid['intent']->fresh()->reconciliation_lease_token);
        $this->assertSame(0, $invalid['intent']->fresh()->reconciliation_lookup_attempts);
        $this->assertSame('failed', $unknown['intent']->fresh()->status);
        $this->assertSame('INVOICE_OUTCOME_UNKNOWN', $unknown['intent']->fresh()->last_error);
        $this->assertSame('processed', $issuedAfterFailure['intent']->fresh()->status);
        $encoded = json_encode($summary, JSON_THROW_ON_ERROR);
        foreach ([$issued, $invalid, $unknown, $issuedAfterFailure] as $state) {
            $this->assertStringNotContainsString($state['intent']->message_id, $encoded);
            $this->assertStringNotContainsString($state['bill']->public_reference, $encoded);
        }
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('invalidConfig')]
    public function test_invalid_canonical_limits_fail_closed_before_writes(array $override): void
    {
        foreach ($override as $key => $value) {
            config()->set('assessment_billing.'.$key, $value);
        }

        $this->expectException(LogicException::class);
        app(CoordinateAssessmentInvoiceReconciliation::class)->execute();
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidConfig(): iterable
    {
        yield 'batch type' => [['invoice_reconciliation_batch_size' => '25']];
        yield 'scan below batch' => [['invoice_reconciliation_scan_limit' => 24]];
        yield 'max zero' => [['invoice_reconciliation_max_lookups' => 0]];
    }

    public function test_ambient_context_and_transaction_are_rejected_before_reservation(): void
    {
        $action = app(CoordinateAssessmentInvoiceReconciliation::class);
        foreach ([new RlsContext('service'), new RlsContext('participant', 1, 1)] as $context) {
            try {
                app(RlsContextRunner::class)->run($context, fn () => $action->execute());
                $this->fail('Ambient context must fail.');
            } catch (LogicException $exception) {
                $this->assertSame(
                    'Invoice reconciliation hint reservation requires an empty RLS context and no ambient transaction.',
                    $exception->getMessage(),
                );
            }
        }
        try {
            DB::transaction(fn () => $action->execute());
            $this->fail('Outer transaction must fail.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'Invoice reconciliation hint reservation requires an empty RLS context and no ambient transaction.',
                $exception->getMessage(),
            );
        }
        $this->assertSame(0, DB::table('outbox_messages')->count());
    }

    /** @param list<array{bill: AssessmentBill, intent: OutboxMessage}> $states */
    private function exactProvider(array $states): PaymentProvider&MockObject
    {
        $provider = $this->createMock(PaymentProvider::class);
        $provider->expects($this->never())->method('createInvoice');
        $provider->expects($this->exactly(count($states)))->method('lookupInvoice')
            ->willReturnCallback(function (string $reference) use ($states): PaymentInvoice {
                foreach ($states as $state) {
                    if ($state['bill']->public_reference === $reference) {
                        return $this->invoice($state['bill']);
                    }
                }
                $this->fail('Unexpected lookup reference.');
            });

        return $provider;
    }

    /** @return array{bill: AssessmentBill, intent: OutboxMessage} */
    private function prepareInvoice(string $key): array
    {
        $fixture = Fixture::create();
        DB::table('assessment_participants')->where('id', $fixture['attempt'])->update([
            'funding_mode' => 'INVOICED_TO_ORGANIZATION',
            'metadata' => '{"checkout_contract_version":"checkout-v2","checkout_initial_funding_mode":null}',
        ]);
        $admin = Admin::create([
            'branch_id' => $fixture['organization'], 'name' => 'Synthetic coordinator',
            'email' => $key.'@example.test', 'password' => 'synthetic', 'role' => AdminRole::BranchAdmin,
        ]);
        $state = app(RlsContextRunner::class)->runAsService(function () use ($fixture, $admin, $key): array {
            $selection = [['assessmentParticipantId' => $fixture['attempt'], 'consultationRequested' => false]];
            $preview = app(PreviewAssessmentBill::class)->execute($fixture['organization'], $selection, PayerType::Organization);
            $bill = app(ReserveAssessmentBill::class)->execute(
                $admin, $selection, $this->method, $preview['selectionHash'], $key,
            );
            app(ClaimAssessmentBillInvoice::class)->execute($fixture['organization'], $bill->id);
            $intent = OutboxMessage::query()->where('aggregate_type', AssessmentBill::class)
                ->where('aggregate_id', (string) $bill->id)->sole();

            return ['bill' => $bill, 'intent' => $intent];
        });
        $this->assertNotNull(app(IssueAssessmentBillInvoice::class)->consume($state['intent']->message_id));

        return $state;
    }

    private function invoice(AssessmentBill $bill): PaymentInvoice
    {
        return new PaymentInvoice(
            'coordinator-'.$bill->id,
            'https://invoice.example.test/coordinator-'.$bill->id,
            $bill->amount,
            $bill->currency,
            now()->addHour(),
        );
    }

    /** @return array{batchLimit: int, scanLimit: int, maxLookups: int, reserved: int, validated: int, issued: int, unknown: int, recoveryRequired: int} */
    private function execute(PaymentProvider $provider): array
    {
        app()->instance(PaymentProvider::class, $provider);

        return app(CoordinateAssessmentInvoiceReconciliation::class)->execute();
    }
}
