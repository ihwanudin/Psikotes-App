<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Data\Payments\AssessmentInvoiceReconciliationPermit;
use App\Data\Payments\ProvisionalAssessmentInvoiceLease;
use App\Models\AssessmentBill;
use App\Models\OutboxMessage;
use App\Security\RlsContextRunner;
use App\Services\Payments\AssessmentInvoicePermitFactory;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;

/** Internal organization-first phase-two validator. It performs no provider operation. */
final readonly class ValidateAssessmentInvoiceReconciliationLease
{
    private const TOPIC = 'assessment.bill.invoice-issuance';

    public function __construct(
        private ClaimAssessmentBillInvoice $claim,
        private AssessmentInvoicePermitFactory $permits,
        private RlsContextRunner $contexts,
    ) {}

    public function execute(ProvisionalAssessmentInvoiceLease $provisional): ?AssessmentInvoiceReconciliationPermit
    {
        $this->assertOutsideTransaction();
        if (! Str::isUlid($provisional->messageId) || ! Str::isUuid($provisional->leaseToken)) {
            throw new DomainException('INVOICE_RECONCILIATION_LEASE_INVALID');
        }
        $config = $this->validatedConfig();

        try {
            $permit = $this->contexts->runAsService(function () use ($provisional, $config): AssessmentInvoiceReconciliationPermit {
                // Routing only: this unlocked hint grants no authority. Claim reloads and
                // validates the complete organization-first canonical graph.
                $hint = OutboxMessage::query()->where('message_id', $provisional->messageId)->first();
                $snapshot = $hint?->payload['snapshot'] ?? null;
                $organizationId = is_array($snapshot) ? ($snapshot['organizationId'] ?? null) : null;
                $billId = is_array($snapshot) ? ($snapshot['billId'] ?? null) : null;
                if (! is_int($organizationId) || $organizationId < 1 || ! is_int($billId) || $billId < 1) {
                    throw new DomainException('INVOICE_RECONCILIATION_LEASE_INVALID');
                }

                $validated = $this->claim->execute($organizationId, $billId);
                if ($validated['decision'] !== 'recovery_required' || $validated['messageId'] !== $provisional->messageId) {
                    throw new DomainException('INVOICE_RECONCILIATION_LEASE_INVALID');
                }

                $message = OutboxMessage::query()->where('message_id', $provisional->messageId)->lockForUpdate()->sole();
                $billStatus = DB::table('assessment_bills')->where('organization_id', $organizationId)
                    ->where('id', $billId)->value('status');
                $now = $this->databaseNow();
                $expiry = $message->reconciliation_lease_expires_at;
                $provisionalExpiry = $provisional->leaseExpiresAt->startOfSecond();
                $processing = $billStatus === 'issuing' && $message->status === 'processing' && $message->last_error === null;
                $unknown = $billStatus === 'unknown' && $message->status === 'failed'
                    && $message->last_error === 'INVOICE_OUTCOME_UNKNOWN';
                if ((! $processing && ! $unknown) || $message->topic !== self::TOPIC
                    || $message->aggregate_type !== AssessmentBill::class || $message->aggregate_id !== (string) $billId
                    || $message->attempts !== 1 || $message->processed_at !== null
                    || $message->reconciliation_lease_token !== $provisional->leaseToken
                    || $expiry === null || ! $expiry->equalTo($provisionalExpiry) || ! $expiry->greaterThan($now)
                    || $message->reconciliation_lookup_attempts < 0
                    || $message->reconciliation_lookup_attempts >= $config['max']) {
                    throw new DomainException('INVOICE_RECONCILIATION_LEASE_INVALID');
                }

                $invoice = $this->permits->fromMessage($message);
                $nextToken = (string) Str::uuid();
                $nextExpiry = $now->addSeconds($config['lease']);
                $generation = $message->reconciliation_lookup_attempts + 1;
                $updated = $this->conditionalPermitUpdate($message->id, $provisional, $provisionalExpiry, $now,
                    $message->reconciliation_lookup_attempts, $config['max'], $nextToken, $nextExpiry, $generation);
                if ($updated !== 1) {
                    throw new DomainException('INVOICE_RECONCILIATION_LEASE_INVALID');
                }

                return new AssessmentInvoiceReconciliationPermit($invoice, $nextToken, $generation, $nextExpiry);
            });
        } catch (DomainException) {
            $this->clearProvisional($provisional);

            return null;
        }

        $this->assertOutsideTransaction();

        return $permit;
    }

    private function conditionalPermitUpdate(int $id, ProvisionalAssessmentInvoiceLease $provisional,
        CarbonImmutable $provisionalExpiry, CarbonImmutable $now, int $currentGeneration, int $maxLookups, string $nextToken,
        CarbonImmutable $nextExpiry, int $generation): int
    {
        return DB::table('outbox_messages')->where('id', $id)
            ->where('topic', self::TOPIC)->where('aggregate_type', AssessmentBill::class)
            ->where('attempts', 1)->whereNull('processed_at')
            ->where(function (Builder $query): void {
                $query->where(function (Builder $pair): void {
                    $pair->where('status', 'processing')->whereNull('last_error');
                })->orWhere(function (Builder $pair): void {
                    $pair->where('status', 'failed')->where('last_error', 'INVOICE_OUTCOME_UNKNOWN');
                });
            })
            ->where('reconciliation_lease_token', $provisional->leaseToken)
            ->where('reconciliation_lease_expires_at', $provisionalExpiry)
            ->where('reconciliation_lease_expires_at', '>', $now)
            ->where('reconciliation_lookup_attempts', $currentGeneration)
            ->where('reconciliation_lookup_attempts', '<', $maxLookups)
            ->update([
                'reconciliation_lease_token' => $nextToken,
                'reconciliation_lease_expires_at' => $nextExpiry,
                'reconciliation_lookup_attempts' => $generation,
            ]);
    }

    private function clearProvisional(ProvisionalAssessmentInvoiceLease $provisional): void
    {
        $this->contexts->runAsService(function () use ($provisional): void {
            DB::table('outbox_messages')->where('message_id', $provisional->messageId)
                ->where('reconciliation_lease_token', $provisional->leaseToken)
                ->where('reconciliation_lease_expires_at', $provisional->leaseExpiresAt)
                ->update(['reconciliation_lease_token' => null, 'reconciliation_lease_expires_at' => null]);
        });
    }

    /** @return array{lease: int, max: int} */
    private function validatedConfig(): array
    {
        $batch = config('assessment_billing.invoice_reconciliation_batch_size');
        $scan = config('assessment_billing.invoice_reconciliation_scan_limit');
        $lease = config('assessment_billing.invoice_reconciliation_lease_seconds');
        $cooldown = config('assessment_billing.invoice_reconciliation_cooldown_seconds');
        $max = config('assessment_billing.invoice_reconciliation_max_lookups');
        if (! is_int($batch) || $batch < 1 || $batch > 100
            || ! is_int($scan) || $scan < $batch || $scan > 400
            || ! is_int($lease) || $lease < 30 || $lease > 300
            || ! is_int($cooldown) || $cooldown < 60 || $cooldown > 86400
            || ! is_int($max) || $max < 1 || $max > 100) {
            throw new LogicException('Invoice reconciliation configuration is invalid.');
        }

        return compact('lease', 'max');
    }

    private function databaseNow(): CarbonImmutable
    {
        $row = (array) DB::selectOne('SELECT CURRENT_TIMESTAMP AS reconciliation_now');
        $value = $row['reconciliation_now'] ?? null;
        if (! is_string($value)) {
            throw new RuntimeException('Database clock did not return a timestamp.');
        }

        return CarbonImmutable::parse($value)->utc()->startOfSecond();
    }

    private function assertOutsideTransaction(): void
    {
        if ($this->contexts->current() !== null || DB::transactionLevel() !== 0) {
            throw new LogicException('Invoice reconciliation lease validation requires an empty RLS context and no ambient transaction.');
        }
    }
}
