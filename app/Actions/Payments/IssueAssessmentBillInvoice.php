<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Contracts\PaymentProvider;
use App\Data\Payments\AssessmentInvoicePermit;
use App\Data\Payments\CreateInvoiceRequest;
use App\Data\Payments\PaymentInvoice;
use App\Domain\Retention\RetentionDataClass;
use App\Domain\Retention\RetentionPolicy;
use App\Models\AssessmentBill;
use App\Models\AssessmentBillItem;
use App\Models\Branch;
use App\Models\OutboxMessage;
use App\Security\RlsContextRunner;
use App\Services\Payments\Exceptions\PaymentProviderException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;

/** Internal queue entrypoint. No route, command, dispatcher, or scheduler is registered. */
final readonly class IssueAssessmentBillInvoice
{
    private const TOPIC = 'assessment.bill.invoice-issuance';

    public function __construct(
        private PaymentProvider $provider,
        private ClaimAssessmentBillInvoice $claim,
        private RlsContextRunner $contexts,
        private PersistAssessmentInvoiceOutcome $outcomes,
        private RetentionPolicy $retention,
    ) {}

    /** @return array{decision: string, messageId: string} */
    public function execute(string $messageId): array
    {
        $permit = $this->consume($messageId);
        if ($permit === null) {
            return ['decision' => 'recovery_required', 'messageId' => $messageId];
        }
        $this->assertOutsideTransaction();

        $created = null;
        try {
            $created = $this->provider->createInvoice($this->request($permit));
        } catch (Throwable $exception) {
            $this->reportUnexpected($exception, 'create');
        }

        $verified = null;
        try {
            $lookup = $this->provider->lookupInvoice($permit->merchantReference, $permit->amount, $permit->currency);
            if (($created === null || $this->sameCreateResult($created, $lookup, $permit))
                && $lookup->amount === $permit->amount && $lookup->currency === $permit->currency
                && $lookup->expiresAt->isFuture()) {
                $verified = $lookup;
            }
        } catch (Throwable $exception) {
            $this->reportUnexpected($exception, 'lookup');
        }

        try {
            return $this->outcomes->execute($permit, $verified);
        } catch (DomainException) {
            // A late/duplicate worker cannot overwrite terminal or changed state.
            return ['decision' => 'recovery_required', 'messageId' => $messageId];
        }
    }

    /**
     * Testable crash boundary: this method commits processing/1 and returns before any provider call.
     *
     * @return AssessmentInvoicePermit|null Null means the permit was already consumed.
     */
    public function consume(string $messageId): ?AssessmentInvoicePermit
    {
        $this->assertOutsideTransaction();
        if (! Str::isUlid($messageId)) {
            throw new DomainException('INVOICE_PERMIT_INVALID');
        }

        $permit = $this->contexts->runAsService(function () use ($messageId): ?AssessmentInvoicePermit {
            // This is an unlocked routing hint only. Canonical claim below locks organization first,
            // then reloads every authoritative row and this message before permission is consumed.
            $hint = OutboxMessage::query()->where('message_id', $messageId)->first();
            $snapshot = $hint?->payload['snapshot'] ?? null;
            $organizationId = is_array($snapshot) ? ($snapshot['organizationId'] ?? null) : null;
            $billId = is_array($snapshot) ? ($snapshot['billId'] ?? null) : null;
            if (! is_int($organizationId) || $organizationId < 1 || ! is_int($billId) || $billId < 1) {
                throw new DomainException('INVOICE_PERMIT_INVALID');
            }
            if ($hint->status === 'processed') {
                $this->assertCompletedReplay($hint, $organizationId, $billId);

                return null;
            }
            $validated = $this->claim->execute($organizationId, $billId);
            if (($validated['messageId'] ?? null) !== $messageId) {
                throw new DomainException('INVOICE_PERMIT_INVALID');
            }
            if ($validated['decision'] === 'recovery_required') {
                return null;
            }
            if ($validated['decision'] !== 'replayed') {
                throw new DomainException('INVOICE_PERMIT_INVALID');
            }

            $message = OutboxMessage::query()->where('message_id', $messageId)->lockForUpdate()->sole();
            if ($message->status !== 'pending' || $message->attempts !== 0 || $message->last_error !== null || $message->processed_at !== null) {
                throw new DomainException('INVOICE_PERMIT_INVALID');
            }
            $permit = $this->permit($message);
            // Constructing the canonical request before the update also rejects an expired intent.
            $this->request($permit);
            $at = now();
            $message->forceFill(['status' => 'processing', 'attempts' => 1, 'updated_at' => $at])->save();
            $this->audit($permit, 'assessment_bill.invoice_permit_consumed', $at, ['snapshotHash' => $message->payload['snapshotHash']]);

            return $permit;
        });

        // A nested savepoint is not sufficient authority to leave for the network.
        $this->assertOutsideTransaction();

        return $permit;
    }

    private function assertCompletedReplay(OutboxMessage $hint, int $organizationId, int $billId): void
    {
        Branch::query()->lockForUpdate()->findOrFail($organizationId);
        $bill = AssessmentBill::query()->where('organization_id', $organizationId)->lockForUpdate()->find($billId);
        AssessmentBillItem::query()->where('bill_id', $billId)->orderBy('id')->lockForUpdate()->get();
        $message = OutboxMessage::query()->where('message_id', $hint->message_id)->lockForUpdate()->sole();
        $payload = $message->payload;
        $snapshot = $payload['snapshot'] ?? null;
        $url = $bill === null ? false : parse_url((string) $bill->invoice_url);
        if ($bill === null || ! is_array($snapshot) || $message->topic !== self::TOPIC
            || $message->aggregate_type !== AssessmentBill::class || $message->aggregate_id !== (string) $billId
            || $message->status !== 'processed' || $message->attempts !== 1 || $message->processed_at === null || $message->last_error !== null
            || ($payload['messageId'] ?? null) !== $message->message_id
            || ($payload['snapshotHash'] ?? null) !== hash('sha256', $this->canonical($snapshot))
            || ($snapshot['organizationId'] ?? null) !== $organizationId || ($snapshot['billId'] ?? null) !== $billId
            || $bill->status !== 'pending' || $bill->public_reference !== ($snapshot['publicReference'] ?? null)
            || $bill->amount !== ($snapshot['amount'] ?? null) || $bill->currency !== ($snapshot['currency'] ?? null)
            || $bill->item_count !== ($snapshot['itemCount'] ?? null) || $bill->payment_method_id !== ($snapshot['paymentMethodId'] ?? null)
            || ! is_string($bill->gateway_ref) || ! preg_match('/^[A-Za-z0-9_-]{1,160}$/D', $bill->gateway_ref)
            || ! is_array($url) || ($url['scheme'] ?? null) !== 'https' || ! is_string($url['host'] ?? null)
            || $bill->expires_at === null || $bill->paid_at !== null) {
            throw new DomainException('INVOICE_PERMIT_INVALID');
        }
    }

    private function assertOutsideTransaction(): void
    {
        if ($this->contexts->current() !== null || DB::transactionLevel() !== 0) {
            throw new LogicException('Invoice issuance requires an empty RLS context and no ambient transaction.');
        }
    }

    private function request(AssessmentInvoicePermit $permit): CreateInvoiceRequest
    {
        try {
            return new CreateInvoiceRequest($permit->merchantReference, $permit->amount, $permit->currency,
                $permit->description, $permit->requestedExpiresAt);
        } catch (InvalidArgumentException) {
            throw new DomainException('INVOICE_PERMIT_INVALID');
        }
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
            throw new DomainException('INVOICE_PERMIT_INVALID');
        }

        return new AssessmentInvoicePermit($message->message_id, $snapshot['organizationId'], $snapshot['billId'],
            $snapshot['publicReference'], $snapshot['amount'], $snapshot['currency'], $payload['description'], $expiry,
            hash('sha256', $this->canonical($payload)), $snapshot);
    }

    private function sameCreateResult(PaymentInvoice $created, PaymentInvoice $lookup, AssessmentInvoicePermit $permit): bool
    {
        return $created->providerReference === $lookup->providerReference
            && $created->amount === $permit->amount && $created->currency === $permit->currency;
    }

    /** @param array<string, mixed> $extra */
    private function audit(AssessmentInvoicePermit $permit, string $action, CarbonInterface $at, array $extra = []): void
    {
        $anchor = CarbonImmutable::instance($at)->utc();
        DB::table('audit_logs')->insert(['branch_id' => $permit->organizationId, 'actor_type' => 'service', 'actor_id' => null,
            'action' => $action, 'subject_type' => AssessmentBill::class, 'subject_id' => (string) $permit->billId,
            'context' => json_encode(['messageId' => $permit->messageId, ...$extra], JSON_THROW_ON_ERROR),
            'occurred_at' => $anchor,
            'expires_at' => $this->retention->expiresAt(RetentionDataClass::Audit, $anchor)]);
    }

    private function reportUnexpected(Throwable $exception, string $operation): void
    {
        if (! $exception instanceof PaymentProviderException) {
            report(new RuntimeException('Assessment invoice provider '.$operation.' failed unexpectedly.'));
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
