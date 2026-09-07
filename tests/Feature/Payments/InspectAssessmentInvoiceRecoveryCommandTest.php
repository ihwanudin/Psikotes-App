<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Contracts\PaymentProvider;
use App\Models\AssessmentBill;
use App\Security\RlsContextRunner;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\OrganizationPaymentTestCase;
use Tests\Support\AssessmentBillingFixture;

final class InspectAssessmentInvoiceRecoveryCommandTest extends OrganizationPaymentTestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::setUp();
        Date::setTestNow('2026-09-07T12:00:00Z');
        $this->app->bind(PaymentProvider::class, static fn (): never => throw new RuntimeException('Provider must not be resolved.'));
    }

    protected function tearDown(): void
    {
        try {
            $this->assertNull(app(RlsContextRunner::class)->current());
            $this->assertSame(0, DB::transactionLevel());
        } finally {
            Date::setTestNow();
            RefreshDatabaseState::$migrated = false;
            parent::tearDown();
        }
    }

    public function test_issuing_inspection_is_bounded_redacted_and_select_only(): void
    {
        $state = $this->recoveryState('issuing');
        $before = $this->businessSnapshot();
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->assertSame(0, Artisan::call('assessment-bills:inspect-invoice-recovery', [
            'reference' => $state['reference'],
        ]));

        $this->assertSame([
            'version' => 1,
            'result' => 'consistent',
            'action' => 'pause_and_escalate',
            'reference' => $state['reference'],
            'organizationId' => $state['organization'],
            'billState' => 'issuing',
            'messageId' => $state['messageId'],
            'intentState' => 'processing',
            'attempts' => 1,
            'reconciliationLookupAttempts' => 0,
            'leaseState' => 'none',
            'leaseExpiresAt' => null,
            'nextLookupAt' => null,
            'snapshotBindingValid' => true,
            'claimedAuditCount' => 1,
            'unknownAuditCount' => 0,
            'issuedAuditCount' => 0,
            'observedAt' => '2026-09-07T12:00:00Z',
            'violations' => [],
        ], $this->decodedOutput());
        $this->assertNotEmpty($queries);
        foreach ($queries as $sql) {
            $this->assertMatchesRegularExpression('/^\s*(select|pragma)\b/i', $sql, $sql);
            $this->assertStringNotContainsString(' for update', strtolower($sql));
        }
        $this->assertSame($before, $this->businessSnapshot());
    }

    public function test_unknown_inspection_reports_active_lease_without_disclosing_token(): void
    {
        $state = $this->recoveryState('unknown', true);

        $this->assertSame(0, Artisan::call('assessment-bills:inspect-invoice-recovery', [
            'reference' => $state['reference'],
        ]));

        $output = $this->decodedOutput();
        $this->assertSame('consistent', $output['result']);
        $this->assertSame('wait_for_active_lease', $output['action']);
        $this->assertSame('active', $output['leaseState']);
        $this->assertSame('2026-09-07T12:05:00Z', $output['leaseExpiresAt']);
        $this->assertSame(1, $output['unknownAuditCount']);
        $encoded = json_encode($output, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($state['leaseToken'], $encoded);
        $this->assertStringNotContainsString('invoice_url', $encoded);
        $this->assertStringNotContainsString('gateway_ref', $encoded);
        $this->assertStringNotContainsString('snapshotHash', $encoded);
    }

    #[DataProvider('corruptions')]
    public function test_corrupt_recovery_evidence_fails_closed(string $corruption, string $violation): void
    {
        $state = $this->recoveryState('unknown');
        match ($corruption) {
            'payload' => DB::table('outbox_messages')->where('message_id', $state['messageId'])
                ->update(['payload' => '{"version":1,"messageId":"'.$state['messageId'].'","snapshot":{},"snapshotHash":"'.str_repeat('0', 64).'"}']),
            'wrong_snapshot' => $this->replaceSnapshot($state, ['billId' => $state['bill'] + 1,
                'organizationId' => $state['organization'], 'publicReference' => $state['reference']]),
            'duplicate_outbox' => $this->duplicateOutbox($state),
            'future_audit' => DB::table('audit_logs')->where('action', 'assessment_bill.invoice_unknown')
                ->update(['occurred_at' => Date::now()->addMinute()]),
            'provider_fields' => DB::table('assessment_bills')->where('id', $state['bill'])
                ->update(['gateway_ref' => 'must-not-be-exposed']),
            'lookup_attempts' => DB::table('outbox_messages')->where('message_id', $state['messageId'])
                ->update(['reconciliation_lookup_attempts' => -1]),
            default => throw new RuntimeException('Unknown synthetic corruption.'),
        };

        $this->assertSame(1, Artisan::call('assessment-bills:inspect-invoice-recovery', [
            'reference' => $state['reference'],
        ]));

        $output = $this->decodedOutput();
        $this->assertSame('escalate', $output['result']);
        $this->assertSame('allocation_invariant_breach', $output['action']);
        $this->assertContains($violation, $output['violations']);
        $this->assertStringNotContainsString('must-not-be-exposed', json_encode($output, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string, array{string, string}> */
    public static function corruptions(): iterable
    {
        yield 'payload digest mismatch' => ['payload', 'SNAPSHOT_BINDING_INVALID'];
        yield 'self-consistent snapshot for another bill' => ['wrong_snapshot', 'SNAPSHOT_BINDING_INVALID'];
        yield 'ambiguous issuance intent' => ['duplicate_outbox', 'INTENT_COUNT_INVALID'];
        yield 'future audit' => ['future_audit', 'AUDIT_TIME_INVALID'];
        yield 'unexpected provider state' => ['provider_fields', 'BILL_PROVIDER_STATE_INVALID'];
        yield 'invalid lookup generation' => ['lookup_attempts', 'RECONCILIATION_METADATA_INVALID'];
    }

    #[DataProvider('unavailableReferences')]
    public function test_invalid_missing_or_non_recoverable_reference_is_generic(string $case, int $exit, string $result, string $code): void
    {
        $reference = 'AB_'.Str::ulid();
        if ($case === 'paid') {
            $state = $this->recoveryState('issuing');
            $reference = $state['reference'];
            DB::table('assessment_bills')->where('id', $state['bill'])->update([
                'status' => 'paid', 'paid_at' => Date::now(),
            ]);
        } elseif ($case === 'invalid') {
            $reference = 'not-a-reference';
        }

        $this->assertSame($exit, Artisan::call('assessment-bills:inspect-invoice-recovery', [
            'reference' => $reference,
        ]));
        $this->assertSame(['version' => 1, 'result' => $result, 'code' => $code], $this->decodedOutput());
    }

    /** @return iterable<string, array{string, int, string, string}> */
    public static function unavailableReferences(): iterable
    {
        yield 'invalid syntax' => ['invalid', 2, 'invalid', 'REFERENCE_INVALID'];
        yield 'missing' => ['missing', 1, 'unavailable', 'REFERENCE_NOT_AVAILABLE'];
        yield 'other lifecycle state' => ['paid', 1, 'unavailable', 'STATE_NOT_RECOVERABLE'];
    }

    /** @return array{organization: int, participant: int, package: int, attempt: int, charge: int, bill: int,
     *     payer: string, reference: string, messageId: string, leaseToken: string}
     */
    private function recoveryState(string $state, bool $activeLease = false): array
    {
        $fixture = AssessmentBillingFixture::create('organization');
        DB::table('assessment_bill_items')->insert(AssessmentBillingFixture::item($fixture));
        $reference = (string) DB::table('assessment_bills')->where('id', $fixture['bill'])->value('public_reference');
        $messageId = (string) Str::ulid();
        $snapshot = ['billId' => $fixture['bill'], 'organizationId' => $fixture['organization'], 'publicReference' => $reference];
        $snapshotHash = hash('sha256', $this->canonical($snapshot));
        $leaseToken = '11111111-1111-4111-8111-111111111111';
        DB::table('assessment_bills')->where('id', $fixture['bill'])->update(['status' => $state]);
        DB::table('outbox_messages')->insert([
            'message_id' => $messageId,
            'deduplication_key' => hash('sha256', 'synthetic:'.$messageId),
            'topic' => 'assessment.bill.invoice-issuance',
            'aggregate_type' => AssessmentBill::class,
            'aggregate_id' => (string) $fixture['bill'],
            'payload' => $this->canonical(['version' => 1, 'messageId' => $messageId, 'snapshot' => $snapshot, 'snapshotHash' => $snapshotHash]),
            'status' => $state === 'issuing' ? 'processing' : 'failed',
            'attempts' => 1,
            'available_at' => Date::now()->subMinute(),
            'processed_at' => null,
            'expires_at' => Date::now()->addYear(),
            'last_error' => $state === 'unknown' ? 'INVOICE_OUTCOME_UNKNOWN' : null,
            'reconciliation_lease_token' => $activeLease ? $leaseToken : null,
            'reconciliation_lease_expires_at' => $activeLease ? Date::now()->addMinutes(5) : null,
            'reconciliation_next_at' => null,
            'reconciliation_lookup_attempts' => $activeLease ? 1 : 0,
            'created_at' => Date::now()->subMinute(),
            'updated_at' => Date::now()->subMinute(),
        ]);
        $this->audit($fixture, 'assessment_bill.invoice_claimed', [
            'messageId' => $messageId, 'reference' => $reference, 'snapshotHash' => $snapshotHash,
        ]);
        if ($state === 'unknown') {
            $this->audit($fixture, 'assessment_bill.invoice_unknown', ['messageId' => $messageId]);
        }

        return [
            'organization' => (int) $fixture['organization'],
            'participant' => (int) $fixture['participant'],
            'package' => (int) $fixture['package'],
            'attempt' => (int) $fixture['attempt'],
            'charge' => (int) $fixture['charge'],
            'bill' => (int) $fixture['bill'],
            'payer' => (string) $fixture['payer'],
            'reference' => $reference,
            'messageId' => $messageId,
            'leaseToken' => $leaseToken,
        ];
    }

    /** @param array<string, int|string> $fixture
     * @param  array<string, string>  $context
     */
    private function audit(array $fixture, string $action, array $context): void
    {
        DB::table('audit_logs')->insert([
            'branch_id' => $fixture['organization'], 'actor_type' => 'service', 'actor_id' => null,
            'action' => $action, 'subject_type' => AssessmentBill::class, 'subject_id' => (string) $fixture['bill'],
            'context' => json_encode($context, JSON_THROW_ON_ERROR), 'occurred_at' => Date::now()->subMinute(),
            'expires_at' => Date::now()->addYear(),
        ]);
    }

    /** @param array<string, int|string> $state */
    private function duplicateOutbox(array $state): void
    {
        $row = (array) DB::table('outbox_messages')->where('message_id', $state['messageId'])->first();
        unset($row['id']);
        $row['message_id'] = (string) Str::ulid();
        $row['deduplication_key'] = hash('sha256', 'duplicate:'.$row['message_id']);
        DB::table('outbox_messages')->insert($row);
    }

    /** @param array<string, int|string> $state
     * @param  array<string, int|string>  $snapshot
     */
    private function replaceSnapshot(array $state, array $snapshot): int
    {
        $payload = ['version' => 1, 'messageId' => $state['messageId'], 'snapshot' => $snapshot,
            'snapshotHash' => hash('sha256', $this->canonical($snapshot))];

        return DB::table('outbox_messages')->where('message_id', $state['messageId'])
            ->update(['payload' => $this->canonical($payload)]);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function businessSnapshot(): array
    {
        $tables = ['assessment_bills', 'assessment_bill_items', 'assessment_charges', 'assessment_participants', 'outbox_messages', 'audit_logs'];

        $snapshot = [];
        foreach ($tables as $table) {
            $snapshot[$table] = [];
            foreach (DB::table($table)->orderBy('id')->get() as $row) {
                $snapshot[$table][] = (array) $row;
            }
        }

        return $snapshot;
    }

    /** @return array<string, mixed> */
    private function decodedOutput(): array
    {
        return json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
    }

    private function canonical(mixed $value): string
    {
        $sort = function (mixed $item) use (&$sort): mixed {
            if (! is_array($item)) {
                return $item;
            }
            if (! array_is_list($item)) {
                ksort($item);
            }

            return array_map($sort, $item);
        };

        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
