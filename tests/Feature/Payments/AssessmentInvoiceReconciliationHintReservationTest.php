<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Actions\Payments\ReserveAssessmentInvoiceReconciliationHints;
use App\Data\Payments\ProvisionalAssessmentInvoiceLease;
use App\Models\AssessmentBill;
use App\Security\RlsContext;
use App\Security\RlsContextRunner;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;

final class AssessmentInvoiceReconciliationHintReservationTest extends OrganizationPaymentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $command = $this->artisan('migrate', ['--force' => true]);
        if (is_int($command)) {
            $this->fail('The migration command did not return a test command wrapper.');
        }
        $command->assertExitCode(0);
    }

    public function test_reserves_only_requested_bound_in_due_order_without_changing_business_state(): void
    {
        $later = $this->insertHint(['reconciliation_next_at' => now()->subMinute()]);
        $neverScheduled = $this->insertHint();
        $earlier = $this->insertHint(['reconciliation_next_at' => now()->subMinutes(2)]);
        $before = DB::table('outbox_messages')->whereIn('id', [$later, $neverScheduled, $earlier])
            ->orderBy('id')->get($this->businessColumns());
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $leases = app(ReserveAssessmentInvoiceReconciliationHints::class)->execute(2, 1);
        $actionQueries = $queries;

        $this->assertCount(1, $leases);
        $this->assertSame($this->messageId($neverScheduled), $leases[0]->messageId);
        $this->assertTrue(Str::isUuid($leases[0]->leaseToken));
        $this->assertTrue($leases[0]->leaseExpiresAt->isFuture());
        $this->assertSame($leases[0]->leaseToken, DB::table('outbox_messages')->where('id', $neverScheduled)
            ->value('reconciliation_lease_token'));
        $this->assertNull(DB::table('outbox_messages')->where('id', $earlier)->value('reconciliation_lease_token'));
        $this->assertEquals($before, DB::table('outbox_messages')->whereIn('id', [$later, $neverScheduled, $earlier])
            ->orderBy('id')->get($this->businessColumns()));
        $this->assertSame(0, DB::table('outbox_messages')->whereIn('id', [$later, $neverScheduled, $earlier])
            ->where('reconciliation_lookup_attempts', '<>', 0)->count());
        $this->assertSame(0, DB::table('audit_logs')->count());
        $this->assertTrue(collect($actionQueries)->contains(fn (string $sql): bool => str_contains($sql, 'current_timestamp')));
        foreach (['branches', 'assessment_bills', 'assessment_bill_items', 'integration_clients', 'integration_sources'] as $table) {
            $this->assertFalse(collect($actionQueries)->contains(fn (string $sql): bool => str_contains($sql, $table)));
        }
        $this->assertNull(app(RlsContextRunner::class)->current());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_skips_ineligible_active_cooldown_and_exhausted_hints_then_reclaims_expired_lease(): void
    {
        config()->set('assessment_billing.invoice_reconciliation_max_lookups', 2);
        $eligible = $this->insertHint();
        $expired = $this->insertHint([
            'reconciliation_lease_token' => '11111111-1111-4111-8111-111111111111',
            'reconciliation_lease_expires_at' => now()->subMinute(),
        ]);
        $oldToken = DB::table('outbox_messages')->where('id', $expired)->value('reconciliation_lease_token');
        $active = $this->insertHint([
            'reconciliation_lease_token' => '22222222-2222-4222-8222-222222222222',
            'reconciliation_lease_expires_at' => now()->addHour(),
        ]);
        $activeToken = DB::table('outbox_messages')->where('id', $active)->value('reconciliation_lease_token');
        $skipped = [
            $this->insertHint(['topic' => 'participant.activation', 'aggregate_type' => 'synthetic']),
            $this->insertHint(['aggregate_type' => 'synthetic']),
            $this->insertHint(['attempts' => 0]),
            $this->insertHint(['processed_at' => now()]),
            $this->insertHint(['status' => 'processing', 'last_error' => 'NONCANONICAL']),
            $this->insertHint(['status' => 'failed', 'last_error' => null]),
            $this->insertHint(['status' => 'failed', 'last_error' => 'NONCANONICAL']),
            $this->insertHint(['status' => 'pending', 'last_error' => null]),
            $this->insertHint(['reconciliation_lookup_attempts' => 2]),
            $this->insertHint(['reconciliation_next_at' => now()->addHour()]),
        ];

        $leases = app(ReserveAssessmentInvoiceReconciliationHints::class)->execute(10, 10);

        $this->assertCount(2, $leases);
        $this->assertSame([$this->messageId($eligible), $this->messageId($expired)],
            array_map(fn (ProvisionalAssessmentInvoiceLease $lease): string => $lease->messageId, $leases));
        $this->assertNotSame($oldToken, $leases[1]->leaseToken);
        $this->assertSame(0, DB::table('outbox_messages')->whereIn('id', $skipped)
            ->whereNotNull('reconciliation_lease_token')->count());
        $this->assertSame($activeToken, DB::table('outbox_messages')->where('id', $active)
            ->value('reconciliation_lease_token'));
        $this->assertSame(0, DB::table('outbox_messages')->whereIn('id', [$eligible, $expired])
            ->where('reconciliation_lookup_attempts', '<>', 0)->count());
    }

    public function test_transaction_failure_rolls_back_all_provisional_tokens(): void
    {
        $ids = [$this->insertHint(), $this->insertHint()];
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed): void {
            if ($armed && str_starts_with(strtolower($query->sql), 'update')
                && str_contains($query->sql, 'reconciliation_lease_token')) {
                $armed = false;
                throw new RuntimeException('synthetic-provisional-failure');
            }
        });

        try {
            app(ReserveAssessmentInvoiceReconciliationHints::class)->execute(2, 2);
            $this->fail('Synthetic failure must abort reservation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic-provisional-failure', $exception->getMessage());
        }

        $this->assertFalse($armed);
        $this->assertSame(0, DB::table('outbox_messages')->whereIn('id', $ids)
            ->whereNotNull('reconciliation_lease_token')->count());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertNull(app(RlsContextRunner::class)->current());
    }

    public function test_rejects_ambient_context_or_transaction_without_writes(): void
    {
        $id = $this->insertHint();
        $action = app(ReserveAssessmentInvoiceReconciliationHints::class);
        foreach (['service', 'participant', 'branch_admin'] as $role) {
            try {
                $context = new RlsContext($role, $role === 'service' ? null : 1, $role === 'participant' ? 1 : null);
                app(RlsContextRunner::class)->run($context, fn () => $action->execute(1, 1));
                $this->fail('Ambient RLS context must fail.');
            } catch (LogicException $exception) {
                $this->assertSame('Invoice reconciliation hint reservation requires an empty RLS context and no ambient transaction.', $exception->getMessage());
            }
        }
        try {
            DB::transaction(fn () => $action->execute(1, 1));
            $this->fail('Ambient transaction must fail.');
        } catch (LogicException $exception) {
            $this->assertSame('Invoice reconciliation hint reservation requires an empty RLS context and no ambient transaction.', $exception->getMessage());
        }
        $this->assertNull(DB::table('outbox_messages')->where('id', $id)->value('reconciliation_lease_token'));
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('invalidLimits')]
    public function test_rejects_invalid_config_and_requested_limits(array $override, int $batch, int $scan,
        string $exceptionClass): void
    {
        foreach ($override as $key => $value) {
            config()->set('assessment_billing.'.$key, $value);
        }
        $id = $this->insertHint();
        try {
            app(ReserveAssessmentInvoiceReconciliationHints::class)->execute($batch, $scan);
            $this->fail('Invalid limits must fail.');
        } catch (LogicException|DomainException $exception) {
            $this->assertSame($exceptionClass, $exception::class);
        }
        $this->assertNull(DB::table('outbox_messages')->where('id', $id)->value('reconciliation_lease_token'));
    }

    /** @return iterable<string, array{array<string, mixed>, int, int, class-string<\Throwable>}> */
    public static function invalidLimits(): iterable
    {
        yield 'batch not integer' => [['invoice_reconciliation_batch_size' => '25'], 1, 1, LogicException::class];
        yield 'batch zero' => [['invoice_reconciliation_batch_size' => 0], 1, 1, LogicException::class];
        yield 'batch over bound' => [['invoice_reconciliation_batch_size' => 101], 1, 1, LogicException::class];
        yield 'scan below batch' => [['invoice_reconciliation_scan_limit' => 24], 1, 1, LogicException::class];
        yield 'scan over bound' => [['invoice_reconciliation_scan_limit' => 401], 1, 1, LogicException::class];
        yield 'lease too short' => [['invoice_reconciliation_lease_seconds' => 29], 1, 1, LogicException::class];
        yield 'lease too long' => [['invoice_reconciliation_lease_seconds' => 301], 1, 1, LogicException::class];
        yield 'cooldown too short' => [['invoice_reconciliation_cooldown_seconds' => 59], 1, 1, LogicException::class];
        yield 'cooldown too long' => [['invoice_reconciliation_cooldown_seconds' => 86401], 1, 1, LogicException::class];
        yield 'max zero' => [['invoice_reconciliation_max_lookups' => 0], 1, 1, LogicException::class];
        yield 'max over bound' => [['invoice_reconciliation_max_lookups' => 101], 1, 1, LogicException::class];
        yield 'remaining batch zero' => [[], 0, 1, DomainException::class];
        yield 'remaining batch over config' => [[], 26, 1, DomainException::class];
        yield 'remaining scan zero' => [[], 1, 0, DomainException::class];
        yield 'remaining scan over config' => [[], 1, 101, DomainException::class];
    }

    /** @param array<string, mixed> $override */
    private function insertHint(array $override = []): int
    {
        $at = CarbonImmutable::now()->utc();
        $messageId = (string) Str::ulid();

        return DB::table('outbox_messages')->insertGetId([...[
            'message_id' => $messageId,
            'deduplication_key' => hash('sha256', $messageId),
            'topic' => 'assessment.bill.invoice-issuance',
            'aggregate_type' => AssessmentBill::class,
            'aggregate_id' => (string) random_int(1, PHP_INT_MAX),
            'payload' => '{}',
            'status' => 'processing',
            'attempts' => 1,
            'available_at' => $at,
            'processed_at' => null,
            'expires_at' => $at->addYears(2),
            'last_error' => null,
            'created_at' => $at,
            'updated_at' => $at,
        ], ...$override]);
    }

    private function messageId(int $id): string
    {
        $messageId = DB::table('outbox_messages')->where('id', $id)->value('message_id');
        if (! is_string($messageId)) {
            throw new RuntimeException('Synthetic hint is missing its message id.');
        }

        return $messageId;
    }

    /** @return list<string> */
    private function businessColumns(): array
    {
        return ['id', 'message_id', 'deduplication_key', 'topic', 'aggregate_type', 'aggregate_id', 'payload',
            'status', 'attempts', 'available_at', 'processed_at', 'expires_at', 'last_error', 'created_at', 'updated_at',
            'reconciliation_next_at', 'reconciliation_lookup_attempts'];
    }
}
