<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Contracts\PaymentProvider;
use App\Data\Payments\AssessmentInvoicePermit;
use App\Data\Payments\CreateInvoiceRequest;
use App\Data\Payments\PaymentInvoice;
use App\Models\AssessmentBill;
use App\Models\AssessmentBillItem;
use App\Models\AssessmentCharge;
use App\Models\AssessmentParticipant;
use App\Models\Branch;
use App\Models\IntegrationClient;
use App\Models\IntegrationSource;
use App\Models\OutboxMessage;
use App\Models\Participant;
use App\Models\PaymentMethod;
use App\Models\TestPackage;
use App\Security\RlsContextRunner;
use App\Services\Payments\Exceptions\PaymentProviderException;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
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
            return $this->persist($permit, $verified);
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

    /** @return array{decision: string, messageId: string} */
    private function persist(AssessmentInvoicePermit $permit, ?PaymentInvoice $invoice): array
    {
        return $this->contexts->runAsService(function () use ($permit, $invoice): array {
            $organization = Branch::query()->lockForUpdate()->find($permit->organizationId);
            $clients = IntegrationClient::query()->where('organization_id', $permit->organizationId)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $sources = IntegrationSource::query()->whereIn('integration_client_id', $clients->keys())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $bill = AssessmentBill::query()->where('organization_id', $permit->organizationId)->lockForUpdate()->find($permit->billId);
            if ($organization === null || $bill === null) {
                throw new DomainException('INVOICE_RESULT_CONFLICT');
            }
            $items = AssessmentBillItem::query()->where('bill_id', $bill->id)->orderBy('id')->lockForUpdate()->get();
            $links = $permit->snapshot['items'] ?? null;
            if (! is_array($links) || ! array_is_list($links)) {
                throw new DomainException('INVOICE_RESULT_CONFLICT');
            }
            $attemptIds = array_column($links, 'assessmentParticipantId');
            $attempts = AssessmentParticipant::query()->where('organization_id', $permit->organizationId)->whereIn('id', $attemptIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $participants = Participant::query()->where('branch_id', $permit->organizationId)->whereIn('id', $attempts->pluck('participant_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $packages = TestPackage::query()->whereIn('id', $attempts->pluck('package_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $charges = AssessmentCharge::query()->whereIn('id', $items->pluck('charge_id'))->orderBy('assessment_participant_id')->lockForUpdate()->get()->keyBy('id');
            $method = PaymentMethod::query()->lockForUpdate()->find($bill->payment_method_id);
            $message = OutboxMessage::query()->where('message_id', $permit->messageId)->lockForUpdate()->sole();

            $this->assertPersistedState($permit, $bill, $items, $attempts, $participants, $packages, $charges, $clients, $sources, $method, $message);
            $at = now();
            if ($invoice === null) {
                if ($bill->status !== 'issuing' || $message->status !== 'processing') {
                    throw new DomainException('INVOICE_RESULT_CONFLICT');
                }
                $bill->update(['status' => 'unknown']);
                $message->forceFill(['status' => 'failed', 'last_error' => 'INVOICE_OUTCOME_UNKNOWN', 'updated_at' => $at])->save();
                $this->audit($permit, 'assessment_bill.invoice_unknown', $at);

                return ['decision' => 'unknown', 'messageId' => $permit->messageId];
            }
            $recoverable = ($bill->status === 'issuing' && $message->status === 'processing')
                || ($bill->status === 'unknown' && $message->status === 'failed');
            if (! $recoverable || $bill->paid_at !== null || $bill->gateway_ref !== null || $bill->invoice_url !== null) {
                throw new DomainException('INVOICE_RESULT_CONFLICT');
            }
            $bill->update(['status' => 'pending', 'gateway_ref' => $invoice->providerReference,
                'invoice_url' => $invoice->paymentUrl, 'expires_at' => $invoice->expiresAt]);
            $message->forceFill(['status' => 'processed', 'processed_at' => $at, 'last_error' => null, 'updated_at' => $at])->save();
            $this->audit($permit, 'assessment_bill.invoice_issued', $at,
                ['providerReferenceHash' => hash('sha256', $invoice->providerReference)]);

            return ['decision' => 'issued', 'messageId' => $permit->messageId];
        });
    }

    /** @param Collection<int, AssessmentBillItem> $items
     * @param  Collection<int, AssessmentParticipant>  $attempts
     * @param  Collection<int, Participant>  $participants
     * @param  Collection<int, TestPackage>  $packages
     * @param  Collection<int, AssessmentCharge>  $charges
     * @param  Collection<int, IntegrationClient>  $clients
     * @param  Collection<int, IntegrationSource>  $sources
     */
    private function assertPersistedState(AssessmentInvoicePermit $permit, AssessmentBill $bill, Collection $items,
        Collection $attempts, Collection $participants, Collection $packages, Collection $charges, Collection $clients,
        Collection $sources, ?PaymentMethod $method, OutboxMessage $message): void
    {
        $snapshot = $permit->snapshot;
        if ($message->topic !== self::TOPIC || $message->aggregate_type !== AssessmentBill::class
            || $message->aggregate_id !== (string) $bill->id || $message->attempts !== 1 || $message->processed_at !== null
            || hash('sha256', $this->canonical($message->payload)) !== $permit->payloadDigest
            || $bill->organization_id !== $permit->organizationId || $bill->id !== $permit->billId
            || $bill->public_reference !== $permit->merchantReference || $bill->amount !== $permit->amount
            || $bill->currency !== $permit->currency || $bill->item_count !== ($snapshot['itemCount'] ?? null)
            || $bill->payment_method_id !== ($snapshot['paymentMethodId'] ?? null) || $method?->code !== 'xendit'
            || $bill->payer_type !== ($snapshot['payerType'] ?? null) || $bill->payer_participant_id !== ($snapshot['payerParticipantId'] ?? null)
            || $items->count() !== $bill->item_count || $attempts->count() !== $items->count()
            || $charges->count() !== $items->count()) {
            throw new DomainException('INVOICE_RESULT_CONFLICT');
        }
        $rawLinks = $snapshot['items'] ?? null;
        if (! is_array($rawLinks) || ! array_is_list($rawLinks)) {
            throw new DomainException('INVOICE_RESULT_CONFLICT');
        }
        $links = [];
        foreach ($rawLinks as $link) {
            if (! is_array($link) || ! is_int($link['itemId'] ?? null) || isset($links[$link['itemId']])) {
                throw new DomainException('INVOICE_RESULT_CONFLICT');
            }
            $links[$link['itemId']] = $link;
        }
        $sum = 0;
        foreach ($items as $item) {
            $link = $links[$item->id] ?? null;
            $charge = $charges->get($item->charge_id);
            $attempt = is_array($link) ? $attempts->get($link['assessmentParticipantId'] ?? null) : null;
            $participant = $attempt === null ? null : $participants->get($attempt->participant_id);
            $package = $attempt === null ? null : $packages->get($attempt->package_id);
            $client = $attempt === null ? null : $clients->get($attempt->integration_client_id);
            $source = $attempt === null ? null : $sources->first(fn (IntegrationSource $row): bool => $row->integration_client_id === $attempt->integration_client_id && $row->source_system === $attempt->source_system);
            $metadata = $attempt?->metadata;
            $initial = is_array($metadata) && ($metadata['checkout_contract_version'] ?? null) === 'checkout-v2'
                && array_key_exists('checkout_initial_funding_mode', $metadata)
                ? $metadata['checkout_initial_funding_mode'] : false;
            if (! is_array($link) || $charge === null || $attempt === null || $participant === null || $package === null || $client === null || $source === null
                || ! in_array($initial, [null, 'COMMERCIAL_SELF_PAY', 'INVOICED_TO_ORGANIZATION'], true)
                || $item->organization_id !== $permit->organizationId || $item->participant_id !== $participant->id
                || $item->payer_type !== $bill->payer_type || $item->payer_participant_id !== $bill->payer_participant_id
                || $charge->organization_id !== $permit->organizationId || $charge->participant_id !== $participant->id
                || $charge->package_id !== $package->id || $charge->payer_type !== $bill->payer_type
                || $charge->amount !== $item->amount || $charge->currency !== $item->currency
                || $this->canonical($link) !== $this->canonical(['itemId' => $item->id, 'chargeId' => $charge->id,
                    'assessmentParticipantId' => $attempt->id, 'participantId' => $participant->id, 'packageId' => $package->id,
                    'integrationClientId' => $client->id, 'sourceId' => $source->id, 'sourceSystem' => $source->source_system,
                    'amount' => $item->amount, 'currency' => $item->currency,
                    'initialFundingMode' => $initial,
                    'attemptIdentityHash' => $this->attemptHash($attempt),
                    'priceSnapshot' => $charge->price_snapshot, 'policySnapshot' => $charge->policy_snapshot])
                || $item->settled_at !== null || $charge->free_settled_at !== null) {
                throw new DomainException('INVOICE_RESULT_CONFLICT');
            }
            if ($sum > PHP_INT_MAX - $item->amount) {
                throw new DomainException('INVOICE_RESULT_CONFLICT');
            }
            $sum += $item->amount;
        }
        if ($sum !== $bill->amount || count($links) !== $items->count()) {
            throw new DomainException('INVOICE_RESULT_CONFLICT');
        }
    }

    private function attemptHash(AssessmentParticipant $attempt): string
    {
        return hash('sha256', $this->canonical([$attempt->assessment_attempt_id, $attempt->external_candidate_id,
            $attempt->external_process_id, $attempt->external_registration_id, $attempt->assessment_round_id,
            $attempt->logical_assessment_key, $attempt->idempotency_key, $attempt->request_hash]));
    }

    /** @param array<string, mixed> $extra */
    private function audit(AssessmentInvoicePermit $permit, string $action, mixed $at, array $extra = []): void
    {
        DB::table('audit_logs')->insert(['branch_id' => $permit->organizationId, 'actor_type' => 'service', 'actor_id' => null,
            'action' => $action, 'subject_type' => AssessmentBill::class, 'subject_id' => (string) $permit->billId,
            'context' => json_encode(['messageId' => $permit->messageId, ...$extra], JSON_THROW_ON_ERROR),
            'occurred_at' => $at, 'expires_at' => $at->copy()->addYears(2)]);
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
