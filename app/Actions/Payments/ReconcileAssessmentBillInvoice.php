<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Contracts\PaymentProvider;
use App\Data\Payments\AssessmentInvoicePermit;
use App\Models\OutboxMessage;
use App\Security\RlsContextRunner;
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

            return $this->permit($message);
        });

        $this->assertOutsideTransaction();

        return $permit;
    }

    private function permit(OutboxMessage $message): AssessmentInvoicePermit
    {
        $payload = $message->payload;
        $snapshot = $payload['snapshot'] ?? null;
        $rawExpiry = $payload['requestedExpiresAt'] ?? null;
        $expiry = is_string($rawExpiry) ? CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $rawExpiry, 'UTC') : null;
        if (! is_array($snapshot) || $expiry === null || $expiry->format('Y-m-d\TH:i:s\Z') !== $rawExpiry
            || ! is_int($snapshot['organizationId'] ?? null) || ! is_int($snapshot['billId'] ?? null)
            || ! is_string($snapshot['publicReference'] ?? null) || ! is_int($snapshot['amount'] ?? null)
            || ! is_string($snapshot['currency'] ?? null) || ! is_string($payload['description'] ?? null)) {
            throw new DomainException('INVOICE_RECONCILIATION_INVALID');
        }

        return new AssessmentInvoicePermit($message->message_id, $snapshot['organizationId'], $snapshot['billId'],
            $snapshot['publicReference'], $snapshot['amount'], $snapshot['currency'], $payload['description'], $expiry,
            hash('sha256', $this->canonical($payload)), $snapshot);
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
