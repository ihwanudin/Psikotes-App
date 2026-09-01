<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Contracts\PaymentProvider;
use App\Data\Payments\AssessmentInvoicePermit;
use App\Data\Payments\AssessmentInvoiceReconciliationPermit;
use App\Models\AssessmentBill;
use App\Models\OutboxMessage;
use App\Security\RlsContextRunner;
use App\Services\Payments\AssessmentInvoicePermitFactory;
use App\Services\Payments\Exceptions\PaymentProviderException;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Throwable;

/** Internal single-intent recovery boundary. No command, job, route, or scheduler is registered. */
final readonly class ReconcileAssessmentBillInvoice
{
    public function __construct(
        private PaymentProvider $provider,
        private ClaimAssessmentBillInvoice $claim,
        private PersistAssessmentInvoiceOutcome $outcomes,
        private AssessmentInvoicePermitFactory $permits,
        private RlsContextRunner $contexts,
    ) {}

    /** @return array{decision: string, messageId: string} */
    public function execute(string $messageId): array
    {
        $permit = $this->preflight($messageId);
        $this->assertOutsideTransaction();

        $invoice = null;
        try {
            $lookup = $this->provider->lookupInvoice($permit->merchantReference, $permit->amount, $permit->currency);
            if ($lookup->amount === $permit->amount && $lookup->currency === $permit->currency) {
                $invoice = $lookup;
            }
        } catch (Throwable $exception) {
            $this->reportUnexpected($exception);
        }

        try {
            return $this->outcomes->execute($permit, $invoice);
        } catch (DomainException) {
            return ['decision' => 'recovery_required', 'messageId' => $messageId];
        }
    }

    /** @return array{decision: string, messageId: string} */
    public function executeLeased(AssessmentInvoiceReconciliationPermit $permit): array
    {
        $this->assertOutsideTransaction();
        $cooldown = $this->cooldownSeconds();
        if (! $this->leasedPreflight($permit)) {
            return ['decision' => 'recovery_required', 'messageId' => $permit->invoice->messageId];
        }
        $this->assertOutsideTransaction();

        $invoice = null;
        try {
            $lookup = $this->provider->lookupInvoice(
                $permit->invoice->merchantReference,
                $permit->invoice->amount,
                $permit->invoice->currency,
            );
            if ($lookup->amount === $permit->invoice->amount && $lookup->currency === $permit->invoice->currency) {
                $invoice = $lookup;
            }
        } catch (Throwable $exception) {
            $this->reportUnexpected($exception);
        }

        try {
            return $this->outcomes->executeReconciliation($permit, $invoice, $cooldown);
        } catch (DomainException) {
            return ['decision' => 'recovery_required', 'messageId' => $permit->invoice->messageId];
        }
    }

    private function leasedPreflight(AssessmentInvoiceReconciliationPermit $permit): bool
    {
        if (! Str::isUlid($permit->invoice->messageId) || ! Str::isUuid($permit->leaseToken)
            || $permit->lookupGeneration < 1 || $permit->lookupGeneration > 100) {
            return false;
        }

        try {
            return $this->contexts->runAsService(function () use ($permit): bool {
                $message = OutboxMessage::query()->where('message_id', $permit->invoice->messageId)->lockForUpdate()->first();
                if ($message === null) {
                    return false;
                }
                $decoded = $this->permits->fromMessage($message);
                $now = $this->databaseNow();
                $expiry = $message->reconciliation_lease_expires_at;
                $processing = $message->status === 'processing' && $message->last_error === null;
                $unknown = $message->status === 'failed' && $message->last_error === 'INVOICE_OUTCOME_UNKNOWN';

                return $message->topic === 'assessment.bill.invoice-issuance'
                    && $message->aggregate_type === AssessmentBill::class
                    && $message->aggregate_id === (string) $permit->invoice->billId
                    && ($processing || $unknown) && $message->attempts === 1 && $message->processed_at === null
                    && $decoded->messageId === $permit->invoice->messageId
                    && $decoded->payloadDigest === $permit->invoice->payloadDigest
                    && $decoded->organizationId === $permit->invoice->organizationId
                    && $decoded->billId === $permit->invoice->billId
                    && $decoded->merchantReference === $permit->invoice->merchantReference
                    && $decoded->amount === $permit->invoice->amount
                    && $decoded->currency === $permit->invoice->currency
                    && $message->reconciliation_lease_token === $permit->leaseToken
                    && $message->reconciliation_lookup_attempts === $permit->lookupGeneration
                    && $expiry !== null && $expiry->equalTo($permit->leaseExpiresAt->startOfSecond())
                    && $expiry->greaterThan($now);
            });
        } catch (DomainException) {
            return false;
        }
    }

    private function preflight(string $messageId): AssessmentInvoicePermit
    {
        $this->assertOutsideTransaction();
        if (! Str::isUlid($messageId)) {
            throw new DomainException('INVOICE_RECONCILIATION_INVALID');
        }

        $permit = $this->contexts->runAsService(function () use ($messageId): AssessmentInvoicePermit {
            $hint = OutboxMessage::query()->where('message_id', $messageId)->first();
            $snapshot = $hint?->payload['snapshot'] ?? null;
            $organizationId = is_array($snapshot) ? ($snapshot['organizationId'] ?? null) : null;
            $billId = is_array($snapshot) ? ($snapshot['billId'] ?? null) : null;
            if (! is_int($organizationId) || $organizationId < 1 || ! is_int($billId) || $billId < 1) {
                throw new DomainException('INVOICE_RECONCILIATION_INVALID');
            }

            $validated = $this->claim->execute($organizationId, $billId);
            if ($validated['decision'] !== 'recovery_required' || $validated['messageId'] !== $messageId) {
                throw new DomainException('INVOICE_RECONCILIATION_INVALID');
            }

            $message = OutboxMessage::query()->where('message_id', $messageId)->lockForUpdate()->sole();
            $bill = DB::table('assessment_bills')->where('organization_id', $organizationId)->where('id', $billId)->lockForUpdate()->first();
            $processing = $bill?->status === 'issuing' && $message->status === 'processing' && $message->last_error === null;
            $unknown = $bill?->status === 'unknown' && $message->status === 'failed'
                && $message->last_error === 'INVOICE_OUTCOME_UNKNOWN';
            if ((! $processing && ! $unknown) || $message->attempts !== 1 || $message->processed_at !== null) {
                throw new DomainException('INVOICE_RECONCILIATION_INVALID');
            }

            return $this->permits->fromMessage($message);
        });

        $this->assertOutsideTransaction();

        return $permit;
    }

    private function assertOutsideTransaction(): void
    {
        if ($this->contexts->current() !== null || DB::transactionLevel() !== 0) {
            throw new LogicException('Invoice reconciliation requires an empty RLS context and no ambient transaction.');
        }
    }

    private function reportUnexpected(Throwable $exception): void
    {
        if (! $exception instanceof PaymentProviderException) {
            report(new RuntimeException('Assessment invoice provider lookup failed unexpectedly.'));
        }
    }

    private function cooldownSeconds(): int
    {
        $cooldown = config('assessment_billing.invoice_reconciliation_cooldown_seconds');
        if (! is_int($cooldown) || $cooldown < 60 || $cooldown > 86400) {
            throw new LogicException('Invoice reconciliation configuration is invalid.');
        }

        return $cooldown;
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
}
